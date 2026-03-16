<?php
/**
 * ASAE Taxonomy Organizer - Batch Manager
 * 
 * This class manages background batch processing jobs. When users need to process
 * more content than can be handled in a single request, we use WordPress's
 * WP-Cron system to run the work in chunks.
 * 
 * The batch system is designed to be resumable and cancellable. If something
 * goes wrong or the user wants to stop, they can cancel at any time without
 * losing the work already completed.
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
     * Create a new batch processing job
     * 
     * Stores all the configuration needed to process the batch in the database.
     * This includes filters, thresholds, and progress tracking fields.
     * 
     * @param string $post_type            Post type to process
     * @param string $taxonomy             Target taxonomy
     * @param int    $total_items          Total items to process
     * @param bool   $ignore_categorized   Skip already categorized
     * @param string $date_from            Date range start
     * @param string $date_to              Date range end
     * @param string $exclude_taxonomy     Taxonomy to exclude
     * @param int    $confidence_threshold Auto-save threshold
     * @return string The unique batch ID
     */
    public function create_batch($post_type, $taxonomy, $total_items, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '', $confidence_threshold = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Generate a unique batch ID
        // Using uniqid() for uniqueness - good enough for our use case
        $batch_id = 'batch_' . uniqid();
        
        // Insert batch record with all configuration
        // Note: We use wpdb->insert with format specifiers for SQL injection protection
        $wpdb->insert(
            $table_name,
            array(
                'batch_id' => $batch_id,
                'post_type' => $post_type,
                'taxonomy' => $taxonomy,
                'total_items' => $total_items,
                'processed_items' => 0,
                'status' => 'pending',
                'ignore_categorized' => $ignore_categorized ? 1 : 0,
                'date_from' => $date_from ?: null,
                'date_to' => $date_to ?: null,
                'exclude_taxonomy' => $exclude_taxonomy ?: null,
                'confidence_threshold' => $confidence_threshold,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        return $batch_id;
    }
    
    /**
     * Schedule a batch for processing
     * 
     * Uses WP-Cron to schedule the next processing chunk. We use a 5-second
     * delay to give the system a moment and prevent overwhelming the server.
     * 
     * @param string $batch_id The batch to schedule
     */
    public function schedule_batch($batch_id) {
        // Only schedule if not already scheduled
        if (!wp_next_scheduled('asae_to_process_batch', array($batch_id))) {
            // Schedule to run in 5 seconds
            wp_schedule_single_event(time() + 5, 'asae_to_process_batch', array($batch_id));
        }
    }
    
    /**
     * Get all active (running or pending) batches
     * 
     * Used to populate the batch status panel in the admin UI.
     * 
     * @return array Array of batch objects
     */
    public function get_active_batches() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Fetch pending and processing batches, newest first
        // This query is safe - no user input, just static status values
        $batches = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status IN ('pending', 'processing') ORDER BY created_at DESC"
        );
        
        return $batches;
    }
    
    /**
     * Get a specific batch by ID
     * 
     * @param string $batch_id The batch ID to retrieve
     * @return object|null The batch object or null if not found
     */
    public function get_batch($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Use prepared statement for the batch_id parameter
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE batch_id = %s",
            $batch_id
        ));
    }
    
    /**
     * Cancel a specific batch
     * 
     * Marks the batch as cancelled and removes any scheduled processing events.
     * Already-processed items remain categorized.
     * 
     * @param string $batch_id The batch to cancel
     * @return array Result with success status and message
     */
    public function cancel_batch($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Update batch status using prepared statement
        $result = $wpdb->update(
            $table_name,
            array(
                'status' => 'cancelled',
                'updated_at' => current_time('mysql')
            ),
            array('batch_id' => $batch_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Remove any scheduled cron events for this batch
        wp_clear_scheduled_hook('asae_to_process_batch', array($batch_id));
        
        if ($result !== false) {
            return array(
                'success' => true,
                'message' => __('Batch cancelled successfully.', 'asae-taxonomy-organizer')
            );
        }
        
        return array(
            'success' => false,
            'message' => __('Failed to cancel batch.', 'asae-taxonomy-organizer')
        );
    }
    
    /**
     * Cancel all active batches
     * 
     * Emergency stop - cancels everything that's pending or processing.
     * 
     * @return array Result with count of cancelled batches
     */
    public function cancel_all_batches() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Get all active batches first to clear their cron events
        $active_batches = $this->get_active_batches();
        
        // Clear all scheduled events
        foreach ($active_batches as $batch) {
            wp_clear_scheduled_hook('asae_to_process_batch', array($batch->batch_id));
        }
        
        // Update all active batches to cancelled
        // Note: Using direct query here because we're updating multiple rows
        // The status values are hardcoded, not user input, so this is safe
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name SET status = %s, updated_at = %s WHERE status IN ('pending', 'processing')",
                'cancelled',
                current_time('mysql')
            )
        );
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('%d batches cancelled.', 'asae-taxonomy-organizer'), 
                count($active_batches)
            )
        );
    }
}

/**
 * WP-Cron Hook Handler
 * 
 * This hook is called by WordPress when a scheduled batch processing event fires.
 * We delegate to the Processor class to handle the actual work.
 */
add_action('asae_to_process_batch', function($batch_id) {
    // Validate batch_id format to prevent any shenanigans
    if (!preg_match('/^batch_[a-f0-9]+$/', $batch_id)) {
        error_log('ASAE Taxonomy Organizer: Invalid batch_id format: ' . $batch_id);
        return;
    }
    
    $processor = new ASAE_TO_Processor();
    $processor->process_batch_chunk($batch_id);
});
