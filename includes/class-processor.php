<?php
/**
 * ASAE Taxonomy Organizer - Content Processor
 * 
 * This class handles the core content processing logic. It orchestrates the
 * workflow of fetching content, running it through the analyzer, and either
 * returning results for preview or saving categorizations directly.
 * 
 * Key Responsibilities:
 * - Validating and sanitizing input parameters
 * - Fetching content based on filters (date range, existing categories, etc.)
 * - Coordinating with the AI Analyzer for categorization
 * - Managing batch processing for large content sets
 * - Saving approved categorizations
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Processor {
    
    /**
     * Number of items to process per batch chunk
     * 
     * This is set to 20 to balance processing speed with server timeout limits.
     * Processing too many items at once risks hitting PHP's max_execution_time.
     * 
     * @var int
     */
    private $batch_size = 20;
    
    /**
     * Maximum items allowed in preview mode
     * 
     * Preview mode is memory-intensive since we hold all results for display.
     * Limiting to 100 prevents memory exhaustion and keeps the UI responsive.
     * 
     * @var int
     */
    private $preview_limit = 100;
    
    /**
     * Process content based on provided parameters
     * 
     * This is the main entry point called from the AJAX handler. It validates
     * inputs, determines the processing mode (preview vs batch), and delegates
     * to the appropriate method.
     * 
     * @param array $params Request parameters from the admin form
     * @return array Response array with success status, message, and results
     */
    public function process($params) {
        // Sanitize all input parameters
        // This is critical for security - never trust user input
        $post_type = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : '';
        $taxonomy = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';
        $items_count = isset($params['items_count']) ? sanitize_text_field($params['items_count']) : '10';
        $ignore_categorized = isset($params['ignore_categorized']) && $params['ignore_categorized'] === 'true';
        $preview_mode = isset($params['preview_mode']) && $params['preview_mode'] === 'true';
        $confidence_threshold = isset($params['confidence_threshold']) ? intval($params['confidence_threshold']) : 0;
        
        // Optional filters
        $date_from = isset($params['date_from']) ? sanitize_text_field($params['date_from']) : '';
        $date_to = isset($params['date_to']) ? sanitize_text_field($params['date_to']) : '';
        $exclude_taxonomy = isset($params['exclude_taxonomy']) ? sanitize_text_field($params['exclude_taxonomy']) : '';
        
        // Validate required fields
        if (empty($post_type) || empty($taxonomy)) {
            return array(
                'success' => false,
                'message' => __('Please select both a post type and taxonomy.', 'asae-taxonomy-organizer')
            );
        }
        
        // Validate post type and taxonomy exist
        if (!post_type_exists($post_type)) {
            return array(
                'success' => false,
                'message' => __('Invalid post type selected.', 'asae-taxonomy-organizer')
            );
        }
        
        if (!taxonomy_exists($taxonomy)) {
            return array(
                'success' => false,
                'message' => __('Invalid taxonomy selected.', 'asae-taxonomy-organizer')
            );
        }
        
        // Determine processing limit
        $limit = ($items_count === 'all') ? -1 : intval($items_count);
        
        // Enforce preview mode limit and disable preview for "all"
        if ($items_count === 'all') {
            // Can't do preview for "all" - force batch mode
            $preview_mode = false;
            
            // Start batch processing in background
            return $this->start_batch_process(
                $post_type, 
                $taxonomy, 
                $ignore_categorized, 
                $date_from, 
                $date_to, 
                $exclude_taxonomy, 
                $confidence_threshold
            );
        }
        
        // Enforce preview limit
        if ($preview_mode && $limit > $this->preview_limit) {
            $limit = $this->preview_limit;
        }
        
        // Process items directly
        return $this->process_items(
            $post_type, 
            $taxonomy, 
            $limit, 
            $preview_mode, 
            $ignore_categorized, 
            $confidence_threshold, 
            $date_from, 
            $date_to, 
            $exclude_taxonomy
        );
    }
    
    /**
     * Process a set of items for categorization
     * 
     * Fetches posts matching the criteria, runs each through the analyzer,
     * and either returns results for preview or saves categorizations directly
     * based on confidence threshold.
     * 
     * @param string $post_type            Post type to process
     * @param string $taxonomy             Taxonomy to use for categorization
     * @param int    $limit                Maximum items to process
     * @param bool   $preview_mode         Whether to preview instead of save
     * @param bool   $ignore_categorized   Skip already categorized items
     * @param int    $confidence_threshold Minimum confidence for auto-save
     * @param string $date_from            Optional start date filter
     * @param string $date_to              Optional end date filter
     * @param string $exclude_taxonomy     Optional taxonomy exclusion filter
     * @return array Processing results
     */
    private function process_items($post_type, $taxonomy, $limit, $preview_mode, $ignore_categorized, $confidence_threshold, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        // Fetch posts matching criteria
        $posts = $this->get_posts($post_type, $taxonomy, $limit, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);
        
        // Handle empty results
        if (empty($posts)) {
            return array(
                'success' => true,
                'message' => __('No content found matching your criteria.', 'asae-taxonomy-organizer'),
                'results' => array(),
                'preview_mode' => $preview_mode
            );
        }
        
        // Initialize the analyzer and get available terms
        $ai_analyzer = new ASAE_TO_AI_Analyzer();
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ));
        
        // Validate we have terms to work with
        if (is_wp_error($terms) || empty($terms)) {
            return array(
                'success' => false,
                'message' => __('No terms found in the selected taxonomy.', 'asae-taxonomy-organizer')
            );
        }
        
        $results = array();
        $auto_saved = 0;
        $needs_review = 0;
        
        // Process each post
        foreach ($posts as $post) {
            // Run content through the analyzer
            $analysis = $ai_analyzer->analyze_content($post, $terms);
            
            // Build result object with all relevant data
            $result = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'post_date' => $post->post_date,
                'suggested_category' => $analysis ? $analysis['term']->name : __('Unable to determine', 'asae-taxonomy-organizer'),
                'term_id' => $analysis ? $analysis['term']->term_id : null,
                'confidence' => $analysis ? $analysis['confidence'] : 0,
                'confidence_level' => $analysis ? $analysis['confidence_level'] : 'low',
                'saved' => false,
                'needs_review' => true
            );
            
            // In non-preview mode, auto-save items above confidence threshold
            if (!$preview_mode && $analysis) {
                if ($analysis['confidence'] >= $confidence_threshold) {
                    // Append term to post (true = append, not replace)
                    $set_result = wp_set_object_terms($post->ID, $analysis['term']->term_id, $taxonomy, true);
                    $result['saved'] = !is_wp_error($set_result);
                    $result['needs_review'] = false;
                    $auto_saved++;
                } else {
                    $needs_review++;
                }
            } else {
                $needs_review++;
            }
            
            $results[] = $result;
        }
        
        // Construct appropriate message based on mode and results
        $message = '';
        if ($preview_mode) {
            $message = sprintf(
                __('Analysis complete. %d items ready for review.', 'asae-taxonomy-organizer'), 
                count($results)
            );
        } else {
            if ($auto_saved > 0 && $needs_review > 0) {
                $message = sprintf(
                    __('%d items auto-saved (above %d%% confidence). %d items need review.', 'asae-taxonomy-organizer'), 
                    $auto_saved, 
                    $confidence_threshold, 
                    $needs_review
                );
            } elseif ($auto_saved > 0) {
                $message = sprintf(
                    __('%d items categorized successfully.', 'asae-taxonomy-organizer'), 
                    $auto_saved
                );
            } else {
                $message = sprintf(
                    __('%d items analyzed. All need review (below %d%% confidence threshold).', 'asae-taxonomy-organizer'), 
                    count($results), 
                    $confidence_threshold
                );
            }
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'results' => $results,
            'preview_mode' => $preview_mode,
            'auto_saved' => $auto_saved,
            'needs_review' => $needs_review,
            'taxonomy' => $taxonomy,
            'all_terms' => array_map(function($t) {
                return array('term_id' => $t->term_id, 'name' => $t->name);
            }, $terms)
        );
    }
    
    /**
     * Save items that the user has approved
     * 
     * Called from preview mode when the user approves one or more categorizations.
     * Each item is assigned to its taxonomy term.
     * 
     * @param array $params Contains 'items' array and 'taxonomy' string
     * @return array Result with count of saved items
     */
    public function save_approved_items($params) {
        $items = isset($params['items']) ? $params['items'] : array();
        $taxonomy = isset($params['taxonomy']) ? sanitize_text_field($params['taxonomy']) : '';
        
        // Validate inputs
        if (empty($items) || empty($taxonomy)) {
            return array(
                'success' => false,
                'message' => __('No items to save.', 'asae-taxonomy-organizer')
            );
        }
        
        if (!taxonomy_exists($taxonomy)) {
            return array(
                'success' => false,
                'message' => __('Invalid taxonomy.', 'asae-taxonomy-organizer')
            );
        }
        
        $saved = 0;
        $failed = 0;
        
        // Process each approved item
        foreach ($items as $item) {
            // Validate and sanitize item data
            $post_id = isset($item['post_id']) ? intval($item['post_id']) : 0;
            $term_id = isset($item['term_id']) ? intval($item['term_id']) : 0;
            
            if ($post_id > 0 && $term_id > 0) {
                // Verify post exists
                if (get_post_status($post_id) !== false) {
                    // Verify term exists in this taxonomy
                    $term = get_term($term_id, $taxonomy);
                    if (!is_wp_error($term) && $term) {
                        $result = wp_set_object_terms($post_id, $term_id, $taxonomy, true);
                        if (!is_wp_error($result)) {
                            $saved++;
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
            'saved' => $saved,
            'failed' => $failed
        );
    }
    
    /**
     * Start a batch processing job
     * 
     * For large content sets, we use background processing via WP-Cron to avoid
     * timeouts. This method creates the batch record and schedules the first chunk.
     * 
     * @param string $post_type            Post type to process
     * @param string $taxonomy             Target taxonomy
     * @param bool   $ignore_categorized   Skip categorized items
     * @param string $date_from            Date range start
     * @param string $date_to              Date range end
     * @param string $exclude_taxonomy     Taxonomy to exclude
     * @param int    $confidence_threshold Auto-save threshold
     * @return array Response with batch info
     */
    private function start_batch_process($post_type, $taxonomy, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '', $confidence_threshold = 0) {
        $batch_manager = new ASAE_TO_Batch_Manager();
        
        // Count total items to process
        $total_items = $this->count_posts($post_type, $taxonomy, $ignore_categorized, $date_from, $date_to, $exclude_taxonomy);
        
        if ($total_items === 0) {
            return array(
                'success' => true,
                'message' => __('No content found matching your criteria.', 'asae-taxonomy-organizer'),
                'results' => array(),
                'preview_mode' => false
            );
        }
        
        // Create the batch record
        $batch_id = $batch_manager->create_batch(
            $post_type, 
            $taxonomy, 
            $total_items, 
            $ignore_categorized, 
            $date_from, 
            $date_to, 
            $exclude_taxonomy, 
            $confidence_threshold
        );
        
        // Schedule the first processing chunk
        $batch_manager->schedule_batch($batch_id);
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Batch process started. %d items will be processed in the background.', 'asae-taxonomy-organizer'),
                $total_items
            ),
            'batch_id' => $batch_id,
            'total_items' => $total_items,
            'is_batch' => true
        );
    }
    
    /**
     * Get posts matching the specified criteria
     * 
     * Builds and executes a WP_Query with all the filtering options. This is
     * used for direct processing (not batch mode).
     * 
     * @param string $post_type          Post type
     * @param string $taxonomy           Target taxonomy
     * @param int    $limit              Maximum posts to return
     * @param bool   $ignore_categorized Skip categorized items
     * @param string $date_from          Date range start
     * @param string $date_to            Date range end
     * @param string $exclude_taxonomy   Taxonomy to exclude
     * @return array Array of WP_Post objects
     */
    private function get_posts($post_type, $taxonomy, $limit, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'ASC', // Process oldest first
        );
        
        // Build taxonomy query for filtering
        $tax_query = array('relation' => 'AND');
        
        if ($ignore_categorized) {
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'operator' => 'NOT EXISTS', // Posts without any term in this taxonomy
            );
        }
        
        if (!empty($exclude_taxonomy) && taxonomy_exists($exclude_taxonomy)) {
            $tax_query[] = array(
                'taxonomy' => $exclude_taxonomy,
                'operator' => 'NOT EXISTS',
            );
        }
        
        // Only add tax_query if we have conditions
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }
        
        // Build date query for date range filtering
        if (!empty($date_from) || !empty($date_to)) {
            $date_query = array();
            
            if (!empty($date_from)) {
                // Validate date format
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                    $date_query['after'] = $date_from;
                }
            }
            
            if (!empty($date_to)) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                    $date_query['before'] = $date_to . ' 23:59:59';
                }
            }
            
            if (!empty($date_query)) {
                $date_query['inclusive'] = true;
                $args['date_query'] = array($date_query);
            }
        }
        
        $query = new WP_Query($args);
        
        return $query->posts;
    }
    
    /**
     * Count posts matching criteria (for batch total)
     * 
     * @param string $post_type          Post type
     * @param string $taxonomy           Target taxonomy
     * @param bool   $ignore_categorized Skip categorized items
     * @param string $date_from          Date range start
     * @param string $date_to            Date range end
     * @param string $exclude_taxonomy   Taxonomy to exclude
     * @return int Number of matching posts
     */
    private function count_posts($post_type, $taxonomy, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids', // Only fetch IDs for efficiency
        );
        
        $tax_query = array('relation' => 'AND');
        
        if ($ignore_categorized) {
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'operator' => 'NOT EXISTS',
            );
        }
        
        if (!empty($exclude_taxonomy) && taxonomy_exists($exclude_taxonomy)) {
            $tax_query[] = array(
                'taxonomy' => $exclude_taxonomy,
                'operator' => 'NOT EXISTS',
            );
        }
        
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }
        
        if (!empty($date_from) || !empty($date_to)) {
            $date_query = array();
            
            if (!empty($date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                $date_query['after'] = $date_from;
            }
            
            if (!empty($date_to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                $date_query['before'] = $date_to . ' 23:59:59';
            }
            
            if (!empty($date_query)) {
                $date_query['inclusive'] = true;
                $args['date_query'] = array($date_query);
            }
        }
        
        $query = new WP_Query($args);
        
        return $query->found_posts;
    }
    
    /**
     * Process a single chunk of a batch job
     * 
     * This is called by WP-Cron to process the next set of items in a batch.
     * After processing, it either marks the batch complete or schedules the
     * next chunk.
     * 
     * @param string $batch_id The batch identifier
     * @return bool True on success
     */
    public function process_batch_chunk($batch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        
        // Fetch batch record using prepared statement for security
        $batch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE batch_id = %s AND status IN ('pending', 'processing')",
            $batch_id
        ));
        
        if (!$batch) {
            return false;
        }
        
        // Update status to processing
        $wpdb->update(
            $table_name,
            array('status' => 'processing', 'updated_at' => current_time('mysql')),
            array('batch_id' => $batch_id),
            array('%s', '%s'),
            array('%s')
        );
        
        // Extract batch configuration
        $ignore_categorized = (bool) $batch->ignore_categorized;
        $current_processed = intval($batch->processed_items);
        $confidence_threshold = isset($batch->confidence_threshold) ? intval($batch->confidence_threshold) : 0;
        
        // Fetch next chunk of posts
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
        
        // If no more posts, mark batch complete
        if (empty($posts)) {
            $wpdb->update(
                $table_name,
                array('status' => 'completed', 'updated_at' => current_time('mysql')),
                array('batch_id' => $batch_id),
                array('%s', '%s'),
                array('%s')
            );
            return true;
        }
        
        // Initialize analyzer and get terms
        $ai_analyzer = new ASAE_TO_AI_Analyzer();
        $terms = get_terms(array(
            'taxonomy' => $batch->taxonomy,
            'hide_empty' => false,
        ));
        
        // Process each post in the chunk
        $processed = 0;
        foreach ($posts as $post) {
            $analysis = $ai_analyzer->analyze_content($post, $terms);
            
            // Only save if analysis succeeded and confidence meets threshold
            if ($analysis && $analysis['confidence'] >= $confidence_threshold) {
                wp_set_object_terms($post->ID, $analysis['term']->term_id, $batch->taxonomy, true);
            }
            
            $processed++;
        }
        
        // Update progress
        $new_processed = $current_processed + $processed;
        $wpdb->update(
            $table_name,
            array(
                'processed_items' => $new_processed,
                'updated_at' => current_time('mysql')
            ),
            array('batch_id' => $batch_id),
            array('%d', '%s'),
            array('%s')
        );
        
        // Check if we're done
        if ($ignore_categorized) {
            // When ignoring categorized, the pool shrinks as we process
            $remaining = $this->count_posts(
                $batch->post_type, 
                $batch->taxonomy, 
                true,
                isset($batch->date_from) ? $batch->date_from : '',
                isset($batch->date_to) ? $batch->date_to : '',
                isset($batch->exclude_taxonomy) ? $batch->exclude_taxonomy : ''
            );
            
            if ($remaining === 0) {
                $wpdb->update(
                    $table_name,
                    array(
                        'status' => 'completed',
                        'total_items' => $new_processed,
                        'updated_at' => current_time('mysql')
                    ),
                    array('batch_id' => $batch_id),
                    array('%s', '%d', '%s'),
                    array('%s')
                );
                return true;
            }
        } else {
            // Fixed pool size
            if ($new_processed >= $batch->total_items) {
                $wpdb->update(
                    $table_name,
                    array('status' => 'completed', 'updated_at' => current_time('mysql')),
                    array('batch_id' => $batch_id),
                    array('%s', '%s'),
                    array('%s')
                );
                return true;
            }
        }
        
        // Schedule next chunk
        $batch_manager = new ASAE_TO_Batch_Manager();
        $batch_manager->schedule_batch($batch_id);
        
        return true;
    }
    
    /**
     * Get posts for batch processing
     * 
     * Similar to get_posts but handles offset for batch pagination.
     * 
     * @param string $post_type          Post type
     * @param string $taxonomy           Target taxonomy
     * @param int    $limit              Chunk size
     * @param int    $offset             Current offset
     * @param bool   $ignore_categorized Skip categorized
     * @param string $date_from          Date range start
     * @param string $date_to            Date range end
     * @param string $exclude_taxonomy   Taxonomy to exclude
     * @return array Array of WP_Post objects
     */
    private function get_batch_posts($post_type, $taxonomy, $limit, $offset, $ignore_categorized, $date_from = '', $date_to = '', $exclude_taxonomy = '') {
        $args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
        );
        
        $tax_query = array('relation' => 'AND');
        
        if ($ignore_categorized) {
            // When ignoring categorized, we always get the first N uncategorized
            // (no offset needed since pool shrinks)
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'operator' => 'NOT EXISTS',
            );
        } else {
            // Fixed pool, use offset for pagination
            $args['offset'] = $offset;
        }
        
        if (!empty($exclude_taxonomy) && taxonomy_exists($exclude_taxonomy)) {
            $tax_query[] = array(
                'taxonomy' => $exclude_taxonomy,
                'operator' => 'NOT EXISTS',
            );
        }
        
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        } elseif ($ignore_categorized) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => $taxonomy,
                    'operator' => 'NOT EXISTS',
                ),
            );
        }
        
        if (!empty($date_from) || !empty($date_to)) {
            $date_query = array();
            
            if (!empty($date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
                $date_query['after'] = $date_from;
            }
            
            if (!empty($date_to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
                $date_query['before'] = $date_to . ' 23:59:59';
            }
            
            if (!empty($date_query)) {
                $date_query['inclusive'] = true;
                $args['date_query'] = array($date_query);
            }
        }
        
        $query = new WP_Query($args);
        
        return $query->posts;
    }
}
