<?php
/**
 * ASAE Taxonomy Organizer - Batch Manager
 *
 * Manages background batch processing jobs via WP-Cron.
 *
 * v0.1.0 additions:
 * - api_calls_made tracking per batch
 * - Stall detection with automatic requeue
 * - cancel_scheduled() helper for pre-scheduled cleanup
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Batch_Manager {

    /**
     * A batch stuck in 'processing' for longer than this (seconds) is
     * considered stalled and will be requeued automatically.
     */
    const STALL_TIMEOUT = 300; // 5 minutes

    /**
     * Create a new batch record.
     *
     * @return string Unique batch ID
     */
    public function create_batch($post_type, $taxonomy, $total_items, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '', $confidence_threshold = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        $batch_id = 'batch_' . uniqid();

        $result = $wpdb->insert(
            $table_name,
            array(
                'batch_id'             => $batch_id,
                'post_type'            => $post_type,
                'taxonomy'             => $taxonomy,
                'total_items'          => $total_items,
                'processed_items'      => 0,
                'api_calls_made'       => 0,
                'status'               => 'pending',
                'ignore_categorized'   => $ignore_categorized ? 1 : 0,
                'date_from'            => $date_from ?: null,
                'date_to'              => $date_to ?: null,
                'exclude_taxonomy'     => $exclude_taxonomy ?: null,
                'confidence_threshold' => $confidence_threshold,
                'created_at'           => current_time('mysql'),
                'updated_at'           => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            error_log('ASAE Taxonomy Organizer: Failed to create batch. DB error: ' . $wpdb->last_error);
            return false;
        }

        return $batch_id;
    }

    /**
     * Schedule the next cron event for a batch.
     *
     * @param string $batch_id
     * @param int    $delay_seconds Seconds from now (default 5)
     */
    public function schedule_batch($batch_id, $delay_seconds = 5) {
        if (!wp_next_scheduled('asae_to_process_batch', array($batch_id))) {
            wp_schedule_single_event(time() + $delay_seconds, 'asae_to_process_batch', array($batch_id));
        }
    }

    /**
     * Cancel any pending cron event for a batch.
     *
     * @param string $batch_id
     */
    public function cancel_scheduled($batch_id) {
        $timestamp = wp_next_scheduled('asae_to_process_batch', array($batch_id));
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'asae_to_process_batch', array($batch_id));
        }
    }

    /**
     * Mark a batch as paused (waiting for API availability).
     *
     * @param string $batch_id
     * @param int    $retry_seconds Seconds until next retry
     * @param string $reason        Why paused (rate_limited, budget_exceeded, error)
     */
    public function pause_batch($batch_id, $retry_seconds, $reason = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        $next_retry = gmdate('Y-m-d H:i:s', time() + $retry_seconds);

        $wpdb->update(
            $table_name,
            array(
                'status'        => 'paused',
                'next_retry_at' => $next_retry,
                'pause_reason'  => $reason,
                'updated_at'    => current_time('mysql'),
            ),
            array('batch_id' => $batch_id),
            array('%s', '%s', '%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get the most recent batch that is still running (pending/processing/paused).
     * Used on page load to detect an interrupted batch for resume.
     *
     * @return object|null
     */
    public function get_running_batch() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        return $wpdb->get_row(
            "SELECT * FROM $table_name WHERE status IN ('pending', 'processing', 'paused') ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Get all active (pending / processing / paused) batches.
     *
     * @return array
     */
    public function get_active_batches() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        return $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status IN ('pending', 'processing', 'paused') ORDER BY created_at DESC"
        );
    }

    /**
     * Get a single batch by ID.
     *
     * @param string $batch_id
     * @return object|null
     */
    public function get_batch($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE batch_id = %s",
            $batch_id
        ));
    }

    /**
     * Cancel a specific batch.
     *
     * @param string $batch_id
     * @return array
     */
    public function cancel_batch($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        $result = $wpdb->update(
            $table_name,
            array('status' => 'cancelled', 'updated_at' => current_time('mysql')),
            array('batch_id' => $batch_id),
            array('%s', '%s'),
            array('%s')
        );

        $this->cancel_scheduled($batch_id);
        delete_transient('asae_to_lock_' . $batch_id);

        if ($result !== false) {
            return array('success' => true, 'message' => __('Batch cancelled successfully.', 'asae-taxonomy-organizer'));
        }
        return array('success' => false, 'message' => __('Failed to cancel batch.', 'asae-taxonomy-organizer'));
    }

    /**
     * Cancel all active batches.
     *
     * @return array
     */
    public function cancel_all_batches() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        $active_batches = $this->get_active_batches();
        foreach ($active_batches as $batch) {
            $this->cancel_scheduled($batch->batch_id);
            delete_transient('asae_to_lock_' . $batch->batch_id);
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name SET status = %s, updated_at = %s WHERE status IN ('pending', 'processing', 'paused')",
            'cancelled',
            current_time('mysql')
        ));

        return array(
            'success' => true,
            'message' => sprintf(__('%d batches cancelled.', 'asae-taxonomy-organizer'), count($active_batches)),
        );
    }

    // =========================================================================
    // Stall detection & watchdog
    // =========================================================================

    /**
     * Watchdog: ensure any paused batches whose retry time has passed get
     * re-scheduled.  Also detects processing stalls.  Called by the recurring
     * cron event `asae_to_batch_watchdog` (every 5 minutes) and on admin page load.
     *
     * @return int Number of batches kicked
     */
    public function watchdog() {
        $kicked = 0;
        $kicked += $this->requeue_overdue_paused();
        $kicked += $this->detect_and_requeue_stalled();
        return $kicked;
    }

    /**
     * Find batches that should be running but aren't.  Handles:
     * - Paused batches whose retry time has passed
     * - Pending/processing batches with overdue cron events (loopback broken)
     * - Orphaned batches with no cron event at all
     *
     * When a cron event is overdue, runs the chunk directly instead of
     * re-scheduling (since WP-Cron loopback may be broken on this host).
     *
     * @return int Number of batches kicked
     */
    public function requeue_overdue_paused() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        $now = gmdate('Y-m-d H:i:s');
        $kicked = 0;

        // 1. Paused batches whose retry time has passed
        $overdue = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = 'paused' AND next_retry_at IS NOT NULL AND next_retry_at <= %s",
            $now
        ));

        foreach ($overdue as $batch) {
            delete_transient('asae_to_lock_' . $batch->batch_id);
            $this->cancel_scheduled($batch->batch_id);
            $this->run_chunk_directly($batch->batch_id);
            $kicked++;
        }

        // 2. Pending/processing batches with overdue or missing cron events
        $active = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status IN ('pending', 'processing')"
        );

        foreach ($active as $batch) {
            $scheduled = wp_next_scheduled('asae_to_process_batch', array($batch->batch_id));
            $lock_held = (bool) get_transient('asae_to_lock_' . $batch->batch_id);

            if ($lock_held) {
                continue; // A chunk is currently running
            }

            if (!$scheduled) {
                // No cron event at all — orphaned
                $this->run_chunk_directly($batch->batch_id);
                error_log('ASAE Taxonomy Organizer: Ran orphaned batch ' . $batch->batch_id . ' directly');
                $kicked++;
            } elseif ($scheduled <= time()) {
                // Cron event is overdue — loopback probably broken
                $this->cancel_scheduled($batch->batch_id);
                $this->run_chunk_directly($batch->batch_id);
                error_log('ASAE Taxonomy Organizer: Ran overdue batch ' . $batch->batch_id . ' directly (cron loopback may be broken)');
                $kicked++;
            }
        }

        return $kicked;
    }

    /**
     * Run a single batch chunk directly (bypassing WP-Cron).
     * Used as a fallback when the cron loopback is broken.
     *
     * @param string $batch_id
     */
    private function run_chunk_directly($batch_id) {
        $processor = new ASAE_TO_Processor();
        $processor->process_batch_chunk($batch_id);
    }

    /**
     * Find batches stuck in 'processing' past the stall timeout and requeue
     * them.  'paused' batches are excluded — they are intentionally waiting.
     *
     * @return int Number of batches requeued
     */
    public function detect_and_requeue_stalled() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::STALL_TIMEOUT);

        // Only detect stalls in 'processing' — 'paused' batches are waiting intentionally
        $stalled = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = 'processing' AND updated_at < %s",
            $cutoff
        ));

        $requeued = 0;

        foreach ($stalled as $batch) {
            // Clear any stale lock
            delete_transient('asae_to_lock_' . $batch->batch_id);

            // Run directly — don't just reschedule, since cron loopback
            // may be broken (which is likely why the batch stalled)
            $this->cancel_scheduled($batch->batch_id);
            $this->run_chunk_directly($batch->batch_id);

            error_log('ASAE Taxonomy Organizer: Ran stalled batch ' . $batch->batch_id . ' directly');
            $requeued++;
        }

        return $requeued;
    }
}

/**
 * WP-Cron hook handler for batch chunks.
 */
add_action('asae_to_process_batch', function ($batch_id) {
    if (!preg_match('/^batch_[a-f0-9]+$/', $batch_id)) {
        error_log('ASAE Taxonomy Organizer: Invalid batch_id format: ' . $batch_id);
        return;
    }

    $processor = new ASAE_TO_Processor();
    $processor->process_batch_chunk($batch_id);
});

/**
 * Recurring watchdog: runs every 5 minutes to catch paused batches whose
 * retry time has passed but whose cron event was never fired (because
 * WP-Cron only triggers on page loads).
 */
add_action('asae_to_batch_watchdog', function () {
    $batch_manager = new ASAE_TO_Batch_Manager();
    $kicked = $batch_manager->watchdog();
    if ($kicked > 0) {
        spawn_cron();
    }
});
