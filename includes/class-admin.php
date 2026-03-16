<?php
/**
 * ASAE Taxonomy Organizer - Main Admin Page
 * 
 * This class renders the primary Organizer interface where users select content
 * to categorize, configure filtering options, and review results. The design
 * focuses on making the workflow intuitive: select content -> configure options
 * -> analyze -> review and approve.
 * 
 * Key Features:
 * - Post type and taxonomy selection
 * - Date range filtering
 * - Preview mode with approval workflow
 * - Confidence threshold settings
 * - Real-time batch progress tracking
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Admin {
    
    /**
     * Render the main Organizer admin page
     * 
     * This is the primary interface for the plugin. It's organized into a main
     * content area (form + results) and a sidebar (batch status + help info).
     * The layout is responsive and works well on various screen sizes.
     */
    public function render() {
        // Gather data needed for the page
        $post_types = $this->get_post_types();
        $all_taxonomies = $this->get_all_taxonomies();
        $batch_manager = new ASAE_TO_Batch_Manager();
        $active_batches = $batch_manager->get_active_batches();
        
        // Check if AI is configured and enabled
        $use_ai = get_option('asae_to_use_ai', 'no');
        $api_key = get_option('asae_to_openai_api_key', '');
        $ai_available = ($use_ai === 'yes' && !empty($api_key));
        ?>
        <div class="wrap asae-to-wrap">
            <h1><?php _e('ASAE Taxonomy Organizer', 'asae-taxonomy-organizer'); ?></h1>
            
            <div class="asae-to-container">
                <div class="asae-to-main">
                    <!-- Status Banner: Shows current analysis mode -->
                    <div class="asae-to-status-banner <?php echo $ai_available ? 'ai-enabled' : 'keyword-mode'; ?>">
                        <?php if ($ai_available): ?>
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php _e('AI Analysis Mode - Using OpenAI for content categorization', 'asae-taxonomy-organizer'); ?>
                        <?php else: ?>
                            <span class="dashicons dashicons-editor-code"></span>
                            <?php _e('Keyword Matching Mode - ', 'asae-taxonomy-organizer'); ?>
                            <a href="<?php echo admin_url('admin.php?page=asae-taxonomy-organizer-settings'); ?>">
                                <?php _e('Enable AI for better accuracy', 'asae-taxonomy-organizer'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Main Configuration Card -->
                    <div class="asae-to-card">
                        <h2><?php _e('Content Categorization', 'asae-taxonomy-organizer'); ?></h2>
                        <p class="description">
                            <?php _e('Analyze and categorize your WordPress content automatically.', 'asae-taxonomy-organizer'); ?>
                        </p>
                        
                        <form id="asae-to-form" class="asae-to-form">
                            <table class="form-table">
                                <!-- Post Type Selection -->
                                <tr>
                                    <th scope="row">
                                        <label for="post_type"><?php _e('Post Type', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <select name="post_type" id="post_type" class="regular-text">
                                            <option value=""><?php _e('Select a post type...', 'asae-taxonomy-organizer'); ?></option>
                                            <?php foreach ($post_types as $post_type): ?>
                                                <option value="<?php echo esc_attr($post_type->name); ?>">
                                                    <?php echo esc_html($post_type->label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                
                                <!-- Taxonomy Selection (populated via AJAX) -->
                                <tr>
                                    <th scope="row">
                                        <label for="taxonomy"><?php _e('Taxonomy', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <select name="taxonomy" id="taxonomy" class="regular-text" disabled>
                                            <option value=""><?php _e('Select a post type first...', 'asae-taxonomy-organizer'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                
                                <!-- Filtering Options Section -->
                                <tr class="asae-to-section-header">
                                    <th colspan="2">
                                        <h3><?php _e('Filtering Options', 'asae-taxonomy-organizer'); ?></h3>
                                    </th>
                                </tr>
                                
                                <!-- Date Range Filter -->
                                <tr>
                                    <th scope="row">
                                        <label for="date_from"><?php _e('Date Range', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <div class="asae-to-date-range">
                                            <input type="date" name="date_from" id="date_from" class="regular-text">
                                            <span class="asae-to-date-separator"><?php _e('to', 'asae-taxonomy-organizer'); ?></span>
                                            <input type="date" name="date_to" id="date_to" class="regular-text">
                                        </div>
                                        <p class="description">
                                            <?php _e('Optional: Only process content published within this date range.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Ignore Previously Categorized -->
                                <tr>
                                    <th scope="row">
                                        <label for="ignore_categorized"><?php _e('Ignore Previously Categorized', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <label class="asae-to-toggle">
                                            <input type="checkbox" name="ignore_categorized" id="ignore_categorized" checked>
                                            <span class="asae-to-toggle-slider"></span>
                                        </label>
                                        <p class="description">
                                            <?php _e('Skip content that already has a category assigned in the selected taxonomy.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Exclude by Other Taxonomy -->
                                <tr>
                                    <th scope="row">
                                        <label for="exclude_taxonomy"><?php _e('Exclude by Existing Taxonomy', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <select name="exclude_taxonomy" id="exclude_taxonomy" class="regular-text">
                                            <option value=""><?php _e('None (include all content)', 'asae-taxonomy-organizer'); ?></option>
                                            <?php foreach ($all_taxonomies as $taxonomy): ?>
                                                <option value="<?php echo esc_attr($taxonomy->name); ?>">
                                                    <?php echo esc_html($taxonomy->label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">
                                            <?php _e('Skip content that already has ANY term assigned in this taxonomy.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Processing Options Section -->
                                <tr class="asae-to-section-header">
                                    <th colspan="2">
                                        <h3><?php _e('Processing Options', 'asae-taxonomy-organizer'); ?></h3>
                                    </th>
                                </tr>
                                
                                <!-- Analysis Method Toggle -->
                                <tr>
                                    <th scope="row">
                                        <label for="use_ai_toggle"><?php _e('Use AI Analysis', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <label class="asae-to-toggle">
                                            <input type="checkbox" name="use_ai_toggle" id="use_ai_toggle" <?php checked($ai_available); ?> <?php disabled(!$ai_available && empty($api_key)); ?>>
                                            <span class="asae-to-toggle-slider"></span>
                                        </label>
                                        <?php if (empty($api_key)): ?>
                                            <p class="description asae-to-warning">
                                                <?php _e('No API key configured.', 'asae-taxonomy-organizer'); ?>
                                                <a href="<?php echo admin_url('admin.php?page=asae-taxonomy-organizer-settings'); ?>">
                                                    <?php _e('Configure OpenAI', 'asae-taxonomy-organizer'); ?>
                                                </a>
                                            </p>
                                        <?php else: ?>
                                            <p class="description">
                                                <?php _e('When enabled, uses OpenAI for more accurate categorization. When disabled, uses keyword matching.', 'asae-taxonomy-organizer'); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Preview Mode Toggle -->
                                <tr>
                                    <th scope="row">
                                        <label for="preview_mode"><?php _e('Preview Mode', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <label class="asae-to-toggle">
                                            <input type="checkbox" name="preview_mode" id="preview_mode" checked>
                                            <span class="asae-to-toggle-slider"></span>
                                        </label>
                                        <p class="description">
                                            <?php _e('Review and approve each suggestion before saving. Maximum 100 items in preview mode.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                        <p class="description asae-to-note" id="preview-limit-note" style="display: none;">
                                            <?php _e('Note: Preview mode is limited to 100 items. Select a smaller batch or disable preview for larger sets.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Confidence Threshold -->
                                <tr>
                                    <th scope="row">
                                        <label for="confidence_threshold"><?php _e('Confidence Threshold', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <div class="asae-to-slider-container">
                                            <input type="range" name="confidence_threshold" id="confidence_threshold" 
                                                   min="0" max="100" value="0" class="asae-to-range">
                                            <span id="confidence_value" class="asae-to-range-value">0%</span>
                                        </div>
                                        <p class="description">
                                            <?php _e('Only auto-save when confidence exceeds this threshold. Items below will require manual review.', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Items to Process -->
                                <tr>
                                    <th scope="row">
                                        <label for="items_count"><?php _e('Items to Process', 'asae-taxonomy-organizer'); ?></label>
                                    </th>
                                    <td>
                                        <select name="items_count" id="items_count" class="regular-text">
                                            <option value="10" selected><?php _e('10 Items', 'asae-taxonomy-organizer'); ?></option>
                                            <option value="25"><?php _e('25 Items', 'asae-taxonomy-organizer'); ?></option>
                                            <option value="50"><?php _e('50 Items', 'asae-taxonomy-organizer'); ?></option>
                                            <option value="100"><?php _e('100 Items', 'asae-taxonomy-organizer'); ?></option>
                                            <option value="all"><?php _e('All Items (Batch Mode)', 'asae-taxonomy-organizer'); ?></option>
                                        </select>
                                        <p class="description" id="batch-notice" style="display: none;">
                                            <?php _e('Processing all items will run in batches. Preview mode is disabled for "All Items".', 'asae-taxonomy-organizer'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" id="process-btn" class="button button-primary button-large" disabled>
                                    <?php _e('Analyze Content', 'asae-taxonomy-organizer'); ?>
                                </button>
                                <span id="processing-spinner" class="spinner" style="float: none; margin-left: 10px;"></span>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Results Card (shown after processing) -->
                    <div class="asae-to-card" id="results-card" style="display: none;">
                        <div class="asae-to-results-header">
                            <h2><?php _e('Results', 'asae-taxonomy-organizer'); ?></h2>
                            <div id="results-actions" style="display: none;">
                                <button id="approve-all-btn" class="button button-primary">
                                    <?php _e('Approve All', 'asae-taxonomy-organizer'); ?>
                                </button>
                                <button id="approve-selected-btn" class="button">
                                    <?php _e('Approve Selected', 'asae-taxonomy-organizer'); ?>
                                </button>
                                <button id="reject-all-btn" class="button">
                                    <?php _e('Clear All', 'asae-taxonomy-organizer'); ?>
                                </button>
                            </div>
                        </div>
                        <div id="results-summary"></div>
                        <div id="results-container"></div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="asae-to-sidebar">
                    <!-- Active Batches Panel -->
                    <div class="asae-to-card">
                        <h3><?php _e('Active Batches', 'asae-taxonomy-organizer'); ?></h3>
                        <div id="active-batches">
                            <?php if (empty($active_batches)): ?>
                                <p class="no-batches"><?php _e('No active batch processes.', 'asae-taxonomy-organizer'); ?></p>
                            <?php else: ?>
                                <?php foreach ($active_batches as $batch): ?>
                                    <div class="batch-item" data-batch-id="<?php echo esc_attr($batch->batch_id); ?>">
                                        <div class="batch-info">
                                            <strong><?php echo esc_html($batch->post_type); ?></strong> 
                                            &rarr; <?php echo esc_html($batch->taxonomy); ?>
                                            <br>
                                            <span class="batch-progress">
                                                <?php echo intval($batch->processed_items); ?> / <?php echo intval($batch->total_items); ?>
                                            </span>
                                            <span class="batch-status status-<?php echo esc_attr($batch->status); ?>">
                                                <?php echo esc_html(ucfirst($batch->status)); ?>
                                            </span>
                                        </div>
                                        <button class="button button-small cancel-batch" 
                                                data-batch-id="<?php echo esc_attr($batch->batch_id); ?>">
                                            <?php _e('Cancel', 'asae-taxonomy-organizer'); ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button id="cancel-all-batches" class="button" <?php disabled(empty($active_batches)); ?>>
                            <?php _e('Cancel All Batches', 'asae-taxonomy-organizer'); ?>
                        </button>
                    </div>
                    
                    <!-- How It Works -->
                    <div class="asae-to-card">
                        <h3><?php _e('How It Works', 'asae-taxonomy-organizer'); ?></h3>
                        <ol>
                            <li><?php _e('Select a post type to categorize', 'asae-taxonomy-organizer'); ?></li>
                            <li><?php _e('Choose the taxonomy to use', 'asae-taxonomy-organizer'); ?></li>
                            <li><?php _e('Set date range and filters', 'asae-taxonomy-organizer'); ?></li>
                            <li><?php _e('AI/keywords analyze content', 'asae-taxonomy-organizer'); ?></li>
                            <li><?php _e('Review confidence scores', 'asae-taxonomy-organizer'); ?></li>
                            <li><?php _e('Approve or correct suggestions', 'asae-taxonomy-organizer'); ?></li>
                        </ol>
                    </div>
                    
                    <!-- Confidence Legend -->
                    <div class="asae-to-card">
                        <h3><?php _e('Confidence Levels', 'asae-taxonomy-organizer'); ?></h3>
                        <div class="confidence-legend">
                            <div class="confidence-item">
                                <span class="confidence-badge high">High</span>
                                <span>75%+ match</span>
                            </div>
                            <div class="confidence-item">
                                <span class="confidence-badge medium">Medium</span>
                                <span>50-74% match</span>
                            </div>
                            <div class="confidence-item">
                                <span class="confidence-badge low">Low</span>
                                <span>Below 50%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="asae-to-footer">
                <p>ASAE Taxonomy Organizer v<?php echo esc_html(ASAE_TO_VERSION); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get all public post types
     * 
     * Returns an array of post type objects that are public (visible on the site).
     * Excludes attachments since they're typically not categorized the same way.
     * 
     * @return array Array of WP_Post_Type objects
     */
    private function get_post_types() {
        $args = array(
            'public' => true,
        );
        
        $post_types = get_post_types($args, 'objects');
        
        // Remove attachments - they're handled differently
        unset($post_types['attachment']);
        
        return $post_types;
    }
    
    /**
     * Get all public taxonomies
     * 
     * Returns an array of taxonomy objects that are public. Used for the
     * "exclude by existing taxonomy" dropdown.
     * 
     * @return array Array of WP_Taxonomy objects
     */
    private function get_all_taxonomies() {
        $args = array(
            'public' => true,
        );
        
        return get_taxonomies($args, 'objects');
    }
}
