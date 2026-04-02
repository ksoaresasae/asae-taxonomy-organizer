<?php
/**
 * ASAE Taxonomy Organizer - Content Processor
 *
 * Orchestrates content fetching, analysis, preview, direct-save, and batch
 * processing.  Key resilience features added in v0.1.0:
 *
 * - Per-item progress saves (batch chunks)
 * - Pre-scheduled next chunk (crash-safe cron chain)
 * - Try-catch per item so one bad post can't kill a chunk
 * - Chunked preview endpoint for incremental AJAX results
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Processor {

    /** Items per background cron chunk. */
    private $batch_size = 20;

    /** Hard cap on preview-mode items. */
    private $preview_limit = 100;

    /** Items returned per chunked-preview AJAX call. */
    private $preview_chunk_size = 10;

    // =========================================================================
    // Public entry points
    // =========================================================================

    /**
     * Main entry – called from the AJAX handler for direct-save and batch modes.
     *
     * @param array $params POST parameters from the admin form
     * @return array JSON-ready response
     */
    public function process($params) {
        $post_type            = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : '';
        $taxonomy             = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';
        $items_count          = isset($params['items_count']) ? sanitize_text_field($params['items_count']) : '10';
        $ignore_categorized   = isset($params['ignore_categorized']) && $params['ignore_categorized'] === 'true';
        $preview_mode         = isset($params['preview_mode']) && $params['preview_mode'] === 'true';
        $confidence_threshold = isset($params['confidence_threshold']) ? intval($params['confidence_threshold']) : 0;
        $date_from            = isset($params['date_from']) ? sanitize_text_field($params['date_from']) : '';
        $date_to              = isset($params['date_to']) ? sanitize_text_field($params['date_to']) : '';
        $exclude_taxonomy     = isset($params['exclude_taxonomy']) ? sanitize_text_field($params['exclude_taxonomy']) : '';

        if (empty($post_type) || empty($taxonomy)) {
            return array('success' => false, 'message' => __('Please select both a post type and taxonomy.', 'asae-taxonomy-organizer'));
        }
        if (!post_type_exists($post_type)) {
            return array('success' => false, 'message' => __('Invalid post type selected.', 'asae-taxonomy-organizer'));
        }
        if (!taxonomy_exists($taxonomy)) {
            return array('success' => false, 'message' => __('Invalid taxonomy selected.', 'asae-taxonomy-organizer'));
        }

        $limit = ($items_count === 'all') ? -1 : intval($items_count);

        // "All Items" → batch mode
        if ($items_count === 'all') {
            return $this->start_batch_process($post_type, $taxonomy, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy, $confidence_threshold);
        }

        if ($preview_mode && $limit > $this->preview_limit) {
            $limit = $this->preview_limit;
        }

        return $this->process_items($post_type, $taxonomy, $limit, $preview_mode, $ignore_categorized, $confidence_threshold, $date_from, $date_to, $exclude_taxonomy);
    }

    /**
     * Chunked preview – returns one page of analysed items for the JS
     * to append incrementally.
     *
     * @param array $params POST parameters (includes offset, chunk_size)
     * @return array JSON-ready response
     */
    public function process_preview_chunk($params) {
        $post_type          = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : '';
        $taxonomy           = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';
        $ignore_categorized = isset($params['ignore_categorized']) && $params['ignore_categorized'] === 'true';
        $date_from          = isset($params['date_from']) ? sanitize_text_field($params['date_from']) : '';
        $date_to            = isset($params['date_to']) ? sanitize_text_field($params['date_to']) : '';
        $exclude_taxonomy   = isset($params['exclude_taxonomy']) ? sanitize_text_field($params['exclude_taxonomy']) : '';
        $offset             = isset($params['offset']) ? max(0, intval($params['offset'])) : 0;
        $chunk_size         = isset($params['chunk_size']) ? min(20, max(1, intval($params['chunk_size']))) : $this->preview_chunk_size;

        if (empty($post_type) || empty($taxonomy)) {
            return array('success' => false, 'data' => __('Post type and taxonomy required.', 'asae-taxonomy-organizer'));
        }

        // Total matching items (for progress bar)
        $total = $this->count_posts($post_type, $taxonomy, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);
        // Cap at preview limit
        $total = min($total, $this->preview_limit);

        if ($total === 0 || $offset >= $total) {
            return array(
                'success' => true,
                'data'    => array('results' => array(), 'total' => $total, 'has_more' => false, 'all_terms' => array()),
            );
        }

        // Clamp chunk to remaining items
        $remaining  = $total - $offset;
        $chunk_size = min($chunk_size, $remaining);

        $posts = $this->get_posts_paged($post_type, $taxonomy, $chunk_size, $offset, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);

        $ai_analyzer = new ASAE_TO_AI_Analyzer();
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        if (is_wp_error($terms) || empty($terms)) {
            return array('success' => false, 'data' => __('No terms found in the selected taxonomy.', 'asae-taxonomy-organizer'));
        }

        $results = array();
        foreach ($posts as $post) {
            try {
                $analysis = $ai_analyzer->analyze_content($post, $terms);
            } catch (\Exception $e) {
                error_log('ASAE Taxonomy Organizer: Exception analysing post ' . $post->ID . ' – ' . $e->getMessage());
                $analysis = null;
            }

            // API refused (rate limited, budget exceeded, or error) — stop chunk
            if (in_array($ai_analyzer->last_status, array('rate_limited', 'budget_exceeded', 'error'), true)) {
                return array(
                    'success' => true,
                    'data'    => array(
                        'results'        => $results,
                        'total'          => $total,
                        'has_more'       => true,
                        'api_unavailable' => true,
                        'api_status'     => $ai_analyzer->last_status,
                        'all_terms'      => ($offset === 0) ? $this->simplify_terms($terms) : array(),
                    ),
                );
            }

            $results[] = array(
                'post_id'            => $post->ID,
                'title'              => $post->post_title,
                'post_date'          => $post->post_date,
                'suggested_category' => $analysis ? $analysis['term']->name : __('Unable to determine', 'asae-taxonomy-organizer'),
                'term_id'            => $analysis ? $analysis['term']->term_id : null,
                'confidence'         => $analysis ? $analysis['confidence'] : 0,
                'confidence_level'   => $analysis ? $analysis['confidence_level'] : 'low',
                'suggested_tags'     => $analysis && !empty($analysis['tags']) ? $analysis['tags'] : array(),
                'saved'              => false,
                'needs_review'       => true,
            );
        }

        $has_more = ($offset + count($results)) < $total;

        return array(
            'success' => true,
            'data'    => array(
                'results'   => $results,
                'total'     => $total,
                'has_more'  => $has_more,
                'all_terms' => ($offset === 0) ? $this->simplify_terms($terms) : array(),
            ),
        );
    }

    /**
     * Return a cost estimate for a planned processing run.
     *
     * @param array $params Same filter params as process()
     * @return array
     */
    public function get_cost_estimate($params) {
        $post_type          = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : '';
        $taxonomy           = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';
        $items_count        = isset($params['items_count']) ? sanitize_text_field($params['items_count']) : '10';
        $ignore_categorized = isset($params['ignore_categorized']) && $params['ignore_categorized'] === 'true';
        $date_from          = isset($params['date_from']) ? sanitize_text_field($params['date_from']) : '';
        $date_to            = isset($params['date_to']) ? sanitize_text_field($params['date_to']) : '';
        $exclude_taxonomy   = isset($params['exclude_taxonomy']) ? sanitize_text_field($params['exclude_taxonomy']) : '';

        $total = $this->count_posts($post_type, $taxonomy, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);

        if ($items_count !== 'all') {
            $total = min($total, intval($items_count));
        }

        $use_ai  = get_option('asae_to_use_ai', 'no') === 'yes';
        $api_key = get_option('asae_to_openai_api_key', '');
        $ai_available = $use_ai && !empty($api_key);

        $estimate = ASAE_TO_AI_Analyzer::estimate_cost($total);
        $usage    = ASAE_TO_AI_Analyzer::get_monthly_usage();

        $budget_exceeded = false;
        if ($usage['limit'] > 0) {
            $budget_exceeded = ($usage['used'] + $total) > $usage['limit'];
        }

        return array(
            'count'           => $total,
            'ai_enabled'      => $ai_available,
            'model'           => $estimate['model'],
            'estimated_cost'  => $ai_available ? number_format($estimate['cost'], 4) : '0.00',
            'remaining_calls' => $usage['limit'] > 0 ? max(0, $usage['limit'] - $usage['used']) : null,
            'monthly_limit'   => $usage['limit'],
            'budget_exceeded' => $budget_exceeded,
        );
    }

    /**
     * Save user-approved items.
     *
     * @param array $params { taxonomy: string, items: array }
     * @return array
     */
    public function save_approved_items($params) {
        $items    = isset($params['items']) ? $params['items'] : array();
        $taxonomy = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';

        if (empty($items) || empty($taxonomy)) {
            return array('success' => false, 'message' => __('No items to save.', 'asae-taxonomy-organizer'));
        }
        if (!taxonomy_exists($taxonomy)) {
            return array('success' => false, 'message' => __('Invalid taxonomy.', 'asae-taxonomy-organizer'));
        }

        $saved  = 0;
        $failed = 0;

        foreach ($items as $item) {
            $post_id = isset($item['post_id']) ? intval($item['post_id']) : 0;
            $term_id = isset($item['term_id']) ? intval($item['term_id']) : 0;
            $tags    = isset($item['tags']) && is_array($item['tags']) ? $item['tags'] : array();

            if ($post_id > 0 && $term_id > 0) {
                if (get_post_status($post_id) !== false) {
                    $term = get_term($term_id, $taxonomy);
                    if (!is_wp_error($term) && $term) {
                        $result = wp_set_object_terms($post_id, $term_id, $taxonomy, false);
                        if (!is_wp_error($result)) {
                            $saved++;
                            if (!empty($tags)) {
                                $this->resolve_and_assign_tags($post_id, $tags);
                            }
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                } else {
                    $failed++;
                }
            }
        }

        return array(
            'success' => true,
            'message' => sprintf(__('%d items saved successfully.', 'asae-taxonomy-organizer'), $saved),
            'saved'   => $saved,
            'failed'  => $failed,
        );
    }

    // =========================================================================
    // Batch processing
    // =========================================================================

    /**
     * Process one chunk of a batch job (called by WP-Cron).
     *
     * Resilience features:
     * - Lock to prevent overlapping execution
     * - Next chunk pre-scheduled before processing starts
     * - Per-item progress saves
     * - Try-catch per item
     * - Rate-limit awareness: pauses batch with backoff
     *
     * @param string $batch_id
     * @return bool
     */
    public function process_batch_chunk($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';

        // Transient-based lock to prevent overlapping cron executions
        $lock_key = 'asae_to_lock_' . $batch_id;
        if (get_transient($lock_key)) {
            return false;
        }
        set_transient($lock_key, true, 300); // 5-minute expiry

        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE batch_id = %s AND status IN ('pending', 'processing', 'paused')",
            $batch_id
        ));

        if (!$batch) {
            delete_transient($lock_key);
            return false;
        }

        // Mark processing
        $wpdb->update(
            $table_name,
            array('status' => 'processing', 'updated_at' => current_time('mysql')),
            array('batch_id' => $batch_id),
            array('%s', '%s'),
            array('%s')
        );

        // Pre-schedule the NEXT chunk now, so a crash in this chunk doesn't
        // break the cron chain.  We cancel it at the end if the batch is done.
        $batch_manager = new ASAE_TO_Batch_Manager();
        $batch_manager->schedule_batch($batch_id, 30); // 30-second gap

        $ignore_categorized   = (bool) $batch->ignore_categorized;
        $current_processed    = intval($batch->processed_items);
        $confidence_threshold = intval($batch->confidence_threshold);
        $current_api_calls    = intval($batch->api_calls_made);

        $posts = $this->get_batch_posts(
            $batch->post_type,
            $batch->taxonomy,
            $this->batch_size,
            $current_processed,
            $ignore_categorized,
            isset($batch->date_from) ? $batch->date_from : '',
            isset($batch->date_to) ? $batch->date_to : '',
            isset($batch->exclude_taxonomy) ? $batch->exclude_taxonomy : ''
        );

        // No posts left → complete
        if (empty($posts)) {
            $this->complete_batch($batch_id, $batch_manager, $lock_key);
            return true;
        }

        $ai_analyzer = new ASAE_TO_AI_Analyzer();
        $terms = get_terms(array('taxonomy' => $batch->taxonomy, 'hide_empty' => false));

        if (is_wp_error($terms) || empty($terms)) {
            delete_transient($lock_key);
            return false;
        }

        $processed      = 0;
        $api_calls_made = 0;

        foreach ($posts as $post) {
            try {
                $analysis = $ai_analyzer->analyze_content($post, $terms);
            } catch (\Exception $e) {
                error_log('ASAE Taxonomy Organizer: Exception on post ' . $post->ID . ' – ' . $e->getMessage());
                $analysis = null;
            }

            // API refused (rate limited, budget exceeded, or error): pause batch
            if (in_array($ai_analyzer->last_status, array('rate_limited', 'budget_exceeded', 'error'), true)) {
                $this->save_batch_progress($batch_id, $current_processed + $processed, $current_api_calls + $api_calls_made);
                // Cancel the pre-scheduled event and reschedule with configurable retry delay
                $batch_manager->cancel_scheduled($batch_id);
                $retry_minutes = max(1, intval(get_option('asae_to_api_retry_delay_minutes', 60)));
                $retry_seconds = $retry_minutes * 60;
                $batch_manager->schedule_batch($batch_id, $retry_seconds);
                // Mark batch as paused with next retry time
                $batch_manager->pause_batch($batch_id, $retry_seconds, $ai_analyzer->last_status);
                delete_transient($lock_key);
                error_log('ASAE Taxonomy Organizer: Batch ' . $batch_id . ' paused (' . $ai_analyzer->last_status . '), retry in ' . $retry_minutes . ' minutes');
                return true;
            }

            if ($analysis && $analysis['confidence'] >= $confidence_threshold) {
                wp_set_object_terms($post->ID, $analysis['term']->term_id, $batch->taxonomy, false);
                if (!empty($analysis['tags'])) {
                    $this->resolve_and_assign_tags($post->ID, $analysis['tags']);
                }
            }

            // Track API call if AI was used
            if (get_option('asae_to_use_ai', 'no') === 'yes' && !empty(get_option('asae_to_openai_api_key', ''))) {
                $api_calls_made++;
            }

            $processed++;

            // Per-item progress save
            $this->save_batch_progress($batch_id, $current_processed + $processed, $current_api_calls + $api_calls_made);
        }

        // Check completion
        $done = false;
        if ($ignore_categorized) {
            $remaining = $this->count_posts(
                $batch->post_type, $batch->taxonomy, true,
                isset($batch->date_from) ? $batch->date_from : '',
                isset($batch->date_to) ? $batch->date_to : '',
                isset($batch->exclude_taxonomy) ? $batch->exclude_taxonomy : ''
            );
            if ($remaining === 0) {
                $done = true;
                $new_processed = $current_processed + $processed;
                $wpdb->update(
                    $table_name,
                    array('total_items' => $new_processed, 'status' => 'completed', 'updated_at' => current_time('mysql')),
                    array('batch_id' => $batch_id),
                    array('%d', '%s', '%s'),
                    array('%s')
                );
            }
        } else {
            $new_processed = $current_processed + $processed;
            if ($new_processed >= $batch->total_items) {
                $done = true;
                $wpdb->update(
                    $table_name,
                    array('status' => 'completed', 'updated_at' => current_time('mysql')),
                    array('batch_id' => $batch_id),
                    array('%s', '%s'),
                    array('%s')
                );
            }
        }

        if ($done) {
            $batch_manager->cancel_scheduled($batch_id);
        }

        delete_transient($lock_key);
        return true;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Process items synchronously (non-batch, non-chunked-preview).
     * Used for direct-save mode with a specific count.
     */
    private function process_items($post_type, $taxonomy, $limit, $preview_mode, $ignore_categorized, $confidence_threshold, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $posts = $this->get_posts($post_type, $taxonomy, $limit, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);

        if (empty($posts)) {
            return array('success' => true, 'message' => __('No content found matching your criteria.', 'asae-taxonomy-organizer'), 'results' => array(), 'preview_mode' => $preview_mode);
        }

        $ai_analyzer = new ASAE_TO_AI_Analyzer();
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));

        if (is_wp_error($terms) || empty($terms)) {
            return array('success' => false, 'message' => __('No terms found in the selected taxonomy.', 'asae-taxonomy-organizer'));
        }

        $results      = array();
        $auto_saved   = 0;
        $needs_review = 0;

        foreach ($posts as $post) {
            try {
                $analysis = $ai_analyzer->analyze_content($post, $terms);
            } catch (\Exception $e) {
                error_log('ASAE Taxonomy Organizer: Exception on post ' . $post->ID . ' – ' . $e->getMessage());
                $analysis = null;
            }

            $suggested_tags = $analysis && !empty($analysis['tags']) ? $analysis['tags'] : array();

            $result = array(
                'post_id'            => $post->ID,
                'title'              => $post->post_title,
                'post_date'          => $post->post_date,
                'suggested_category' => $analysis ? $analysis['term']->name : __('Unable to determine', 'asae-taxonomy-organizer'),
                'term_id'            => $analysis ? $analysis['term']->term_id : null,
                'confidence'         => $analysis ? $analysis['confidence'] : 0,
                'confidence_level'   => $analysis ? $analysis['confidence_level'] : 'low',
                'suggested_tags'     => $suggested_tags,
                'saved'              => false,
                'needs_review'       => true,
            );

            if (!$preview_mode && $analysis) {
                if ($analysis['confidence'] >= $confidence_threshold) {
                    $set_result = wp_set_object_terms($post->ID, $analysis['term']->term_id, $taxonomy, false);
                    $result['saved']        = !is_wp_error($set_result);
                    $result['needs_review'] = false;
                    if ($result['saved']) {
                        $this->resolve_and_assign_tags($post->ID, $suggested_tags);
                    }
                    $auto_saved++;
                } else {
                    $needs_review++;
                }
            } else {
                $needs_review++;
            }

            $results[] = $result;
        }

        $message = '';
        if ($preview_mode) {
            $message = sprintf(__('Analysis complete. %d items ready for review.', 'asae-taxonomy-organizer'), count($results));
        } else {
            if ($auto_saved > 0 && $needs_review > 0) {
                $message = sprintf(__('%d items auto-saved (above %d%% confidence). %d items need review.', 'asae-taxonomy-organizer'), $auto_saved, $confidence_threshold, $needs_review);
            } elseif ($auto_saved > 0) {
                $message = sprintf(__('%d items categorized successfully.', 'asae-taxonomy-organizer'), $auto_saved);
            } else {
                $message = sprintf(__('%d items analyzed. All need review (below %d%% confidence threshold).', 'asae-taxonomy-organizer'), count($results), $confidence_threshold);
            }
        }

        return array(
            'success'      => true,
            'message'      => $message,
            'results'      => $results,
            'preview_mode' => $preview_mode,
            'auto_saved'   => $auto_saved,
            'needs_review' => $needs_review,
            'taxonomy'     => $taxonomy,
            'all_terms'    => $this->simplify_terms($terms),
        );
    }

    /**
     * Start a background batch job.
     */
    private function start_batch_process($post_type, $taxonomy, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '', $confidence_threshold = 0) {
        $batch_manager = new ASAE_TO_Batch_Manager();
        $total_items   = $this->count_posts($post_type, $taxonomy, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);

        if ($total_items === 0) {
            return array('success' => true, 'message' => __('No content found matching your criteria.', 'asae-taxonomy-organizer'), 'results' => array(), 'preview_mode' => false);
        }

        $batch_id = $batch_manager->create_batch($post_type, $taxonomy, $total_items, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy, $confidence_threshold);

        if ($batch_id === false) {
            return array('success' => false, 'message' => __('Failed to create batch record. Please deactivate and reactivate the plugin to update the database schema.', 'asae-taxonomy-organizer'));
        }

        $batch_manager->schedule_batch($batch_id);

        return array(
            'success'     => true,
            'message'     => sprintf(__('Batch process started. %d items will be processed in the background.', 'asae-taxonomy-organizer'), $total_items),
            'batch_id'    => $batch_id,
            'total_items' => $total_items,
            'is_batch'    => true,
        );
    }

    /**
     * Atomically save batch progress to the database.
     */
    private function save_batch_progress($batch_id, $processed_items, $api_calls_made) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'asae_to_batches',
            array(
                'processed_items' => $processed_items,
                'api_calls_made'  => $api_calls_made,
                'updated_at'      => current_time('mysql'),
            ),
            array('batch_id' => $batch_id),
            array('%d', '%d', '%s'),
            array('%s')
        );
    }

    /**
     * Mark a batch as completed and clean up.
     */
    private function complete_batch($batch_id, $batch_manager, $lock_key) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'asae_to_batches',
            array('status' => 'completed', 'updated_at' => current_time('mysql')),
            array('batch_id' => $batch_id),
            array('%s', '%s'),
            array('%s')
        );
        $batch_manager->cancel_scheduled($batch_id);
        delete_transient($lock_key);
    }

    /**
     * Resolve suggested tag strings against existing tags (fuzzy matching)
     * and assign them to a post. Creates new tags only when no match is found.
     *
     * @param int   $post_id
     * @param array $suggested_tags Array of tag name strings
     */
    private function resolve_and_assign_tags($post_id, $suggested_tags) {
        if (empty($suggested_tags)) {
            return;
        }

        // Cache existing tags for the batch
        static $existing_tags = null;
        if ($existing_tags === null) {
            $all_tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false));
            $existing_tags = is_wp_error($all_tags) ? array() : $all_tags;
        }

        $tag_ids = array();

        foreach ($suggested_tags as $tag_name) {
            $tag_name = ucwords(trim($tag_name));
            if (empty($tag_name)) {
                continue;
            }

            $match = null;
            $tag_lower = strtolower($tag_name);
            $candidate_slug = sanitize_title($tag_name);

            // 1. Exact name match (case-insensitive)
            foreach ($existing_tags as $existing) {
                if (strtolower($existing->name) === $tag_lower) {
                    $match = $existing;
                    break;
                }
            }

            // 2. Slug match (handles punctuation: "A.I." vs "AI")
            if (!$match) {
                foreach ($existing_tags as $existing) {
                    if ($existing->slug === $candidate_slug) {
                        $match = $existing;
                        break;
                    }
                }
            }

            // 3. Fuzzy match — similar_text or containment
            if (!$match) {
                $best_pct = 0;
                $best_candidate = null;

                foreach ($existing_tags as $existing) {
                    $existing_lower = strtolower($existing->name);

                    // Containment check (one is a substring of the other)
                    $contains = (strpos($existing_lower, $tag_lower) !== false ||
                                 strpos($tag_lower, $existing_lower) !== false);

                    similar_text($tag_lower, $existing_lower, $pct);

                    if (($pct >= 85 || $contains) && $pct > $best_pct) {
                        $best_pct = $pct;
                        $best_candidate = $existing;
                    }
                }

                if ($best_candidate) {
                    $match = $best_candidate;
                }
            }

            if ($match) {
                $tag_ids[] = $match->term_id;
            } else {
                $new_term = wp_insert_term($tag_name, 'post_tag');
                if (!is_wp_error($new_term)) {
                    $tag_ids[] = $new_term['term_id'];
                    // Add to cache so subsequent items can match it
                    $new_tag_obj = get_term($new_term['term_id'], 'post_tag');
                    if ($new_tag_obj && !is_wp_error($new_tag_obj)) {
                        $existing_tags[] = $new_tag_obj;
                    }
                }
            }
        }

        if (!empty($tag_ids)) {
            wp_set_object_terms($post_id, $tag_ids, 'post_tag', true); // append
        }
    }

    /**
     * Convert WP_Term array to simple arrays for JSON.
     */
    private function simplify_terms($terms) {
        return array_map(function ($t) {
            return array('term_id' => $t->term_id, 'name' => $t->name);
        }, $terms);
    }

    // =========================================================================
    // WP_Query helpers
    // =========================================================================

    private function get_posts($post_type, $taxonomy, $limit, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'ASC',
        );
        $args = $this->apply_tax_query($args, $taxonomy, $ignore_categorized, $exclude_taxonomy);
        $args = $this->apply_date_query($args, $date_from, $date_to);

        return (new WP_Query($args))->posts;
    }

    /**
     * Paginated query for chunked preview.
     */
    private function get_posts_paged($post_type, $taxonomy, $limit, $offset, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'ASC',
        );
        $args = $this->apply_tax_query($args, $taxonomy, $ignore_categorized, $exclude_taxonomy);
        $args = $this->apply_date_query($args, $date_from, $date_to);

        return (new WP_Query($args))->posts;
    }

    private function get_batch_posts($post_type, $taxonomy, $limit, $offset, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'ASC',
        );

        if ($ignore_categorized) {
            $args = $this->apply_tax_query($args, $taxonomy, true, $exclude_taxonomy);
            // No offset needed: pool shrinks as items are categorised
        } else {
            $args['offset'] = $offset;
            $args = $this->apply_tax_query($args, $taxonomy, false, $exclude_taxonomy);
        }

        $args = $this->apply_date_query($args, $date_from, $date_to);

        return (new WP_Query($args))->posts;
    }

    /**
     * Count matching posts.
     */
    public function count_posts($post_type, $taxonomy, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        $args = $this->apply_tax_query($args, $taxonomy, $ignore_categorized, $exclude_taxonomy);
        $args = $this->apply_date_query($args, $date_from, $date_to);

        return (new WP_Query($args))->found_posts;
    }

    /**
     * Shared: build tax_query from filter flags.
     */
    private function apply_tax_query($args, $taxonomy, $ignore_categorized, $exclude_taxonomy = '') {
        $tax_query = array('relation' => 'AND');

        if ($ignore_categorized) {
            $tax_query[] = array('taxonomy' => $taxonomy, 'operator' => 'NOT EXISTS');
        }
        if (!empty($exclude_taxonomy) && taxonomy_exists($exclude_taxonomy)) {
            $tax_query[] = array('taxonomy' => $exclude_taxonomy, 'operator' => 'NOT EXISTS');
        }

        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }

        return $args;
    }

    /**
     * Shared: build date_query from filter strings.
     */
    private function apply_date_query($args, $date_from, $date_to) {
        if (empty($date_from) && empty($date_to)) {
            return $args;
        }

        $date_query = array();

        if (!empty($date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $date_query['after'] = $date_from;
        }
        if (!empty($date_to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_query['before'] = $date_to . ' 23:59:59';
        }

        if (!empty($date_query)) {
            $date_query['inclusive'] = true;
            $args['date_query']     = array($date_query);
        }

        return $args;
    }
}
