<?php
/**
 * Plugin Name: ASAE Taxonomy Organizer
 * Plugin URI: https://www.asaecenter.org
 * Description: Use AI to automatically analyze WordPress content and categorize it with appropriate taxonomy terms.
 * Version: 0.2.1
 * Author: Keith M. Soares
 * Author URI: https://www.asaecenter.org
 * Author Email: ksoares@asaecenter.org
 * Company: ASAE
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: asae-taxonomy-organizer
 * 
 * =============================================================================
 * ASAE Taxonomy Organizer
 * =============================================================================
 * 
 * This plugin helps content managers automatically categorize WordPress content
 * using AI (OpenAI) or intelligent keyword matching. The goal is to reduce the
 * manual effort of organizing large content libraries while maintaining accuracy.
 * 
 * Key Design Decisions:
 * - OpenAI integration is optional - the plugin works with or without it
 * - Preview mode allows human review before any changes are saved
 * - Confidence scoring helps identify uncertain categorizations
 * - Batch processing handles large content libraries without timeouts
 * - Rejection feedback is logged for future AI training improvements
 * 
 * Security Considerations:
 * - All AJAX endpoints verify nonces and user capabilities
 * - All user inputs are sanitized before use
 * - Database queries use prepared statements
 * - API keys are stored in wp_options (consider environment variables for production)
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares <ksoares@asaecenter.org>
 * @copyright 2026 ASAE
 */

// =============================================================================
// SECURITY CHECK
// =============================================================================
// Prevent direct access to this file. All WordPress plugins should include this
// to prevent information disclosure or unauthorized execution.
if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// PLUGIN CONSTANTS
// =============================================================================
// These constants provide easy access to version info and file paths throughout
// the plugin. Using constants ensures consistency and makes updates easier.

define('ASAE_TO_VERSION', '0.2.1');
define('ASAE_TO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASAE_TO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASAE_TO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 * 
 * This is the core class that bootstraps the entire plugin. It uses the singleton
 * pattern to ensure only one instance exists, which prevents duplicate hook
 * registrations and other potential issues.
 * 
 * The class is responsible for:
 * - Loading all dependency classes
 * - Registering WordPress hooks (actions and filters)
 * - Handling AJAX requests
 * - Managing plugin activation/deactivation
 * 
 * @since 0.0.1
 */
class ASAE_Taxonomy_Organizer {
    
    /**
     * Singleton instance
     * 
     * @var ASAE_Taxonomy_Organizer|null
     */
    private static $instance = null;

    /**
     * Admin page hook suffixes for asset enqueuing
     *
     * @var string
     */
    private $organizer_hook = '';
    private $settings_hook = '';
    
    /**
     * Get the singleton instance
     * 
     * This ensures we only have one instance of the plugin running at a time.
     * All access to the plugin should go through this method.
     * 
     * @return ASAE_Taxonomy_Organizer
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize the plugin
     * 
     * Private to enforce singleton pattern. Loads dependencies and sets up hooks.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required class files
     * 
     * All the plugin's functionality is split into separate classes for better
     * organization and maintainability. Each class has a single responsibility.
     */
    private function load_dependencies() {
        // Admin page rendering
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-admin.php';
        
        // OpenAI settings page
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-settings.php';
        
        // Content processing logic
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-processor.php';
        
        // Background batch job management
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-batch-manager.php';
        
        // AI/keyword analysis engine
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-ai-analyzer.php';
        
        // Rejection feedback logging
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-feedback-logger.php';
    }
    
    /**
     * Register all WordPress hooks
     * 
     * This method sets up all the connections between WordPress events and our
     * plugin's functionality. Organized by type for easier maintenance.
     */
    private function init_hooks() {
        // Admin menu (priority 20: runs after ASAE Explore's priority 10)
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers for the Organizer page
        add_action('wp_ajax_asae_to_get_taxonomies', array($this, 'ajax_get_taxonomies'));
        add_action('wp_ajax_asae_to_process_content', array($this, 'ajax_process_content'));
        add_action('wp_ajax_asae_to_cancel_batch', array($this, 'ajax_cancel_batch'));
        add_action('wp_ajax_asae_to_get_batch_status', array($this, 'ajax_get_batch_status'));
        add_action('wp_ajax_asae_to_save_items', array($this, 'ajax_save_items'));
        add_action('wp_ajax_asae_to_get_terms', array($this, 'ajax_get_terms'));
        add_action('wp_ajax_asae_to_log_rejection', array($this, 'ajax_log_rejection'));
        
        // AJAX handlers for chunked preview and cost estimate
        add_action('wp_ajax_asae_to_process_preview_chunk', array($this, 'ajax_process_preview_chunk'));
        add_action('wp_ajax_asae_to_get_cost_estimate', array($this, 'ajax_get_cost_estimate'));

        // AJAX handlers for the Settings page
        add_action('wp_ajax_asae_to_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_asae_to_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_asae_to_reset_usage', array($this, 'ajax_reset_usage'));
        add_action('wp_ajax_asae_to_get_batch_progress', array($this, 'ajax_get_batch_progress'));
        
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Plugin activation handler
     * 
     * Called when the plugin is activated. Creates necessary database tables
     * and flushes rewrite rules to ensure everything is set up correctly.
     */
    public function activate() {
        $this->create_batch_table();
        $this->create_feedback_table();
        $this->maybe_migrate_batch_table();
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation handler
     * 
     * Cleans up scheduled events when the plugin is deactivated to prevent
     * orphaned cron jobs from running after the plugin is gone.
     */
    public function deactivate() {
        wp_clear_scheduled_hook('asae_to_process_batch');
    }
    
    /**
     * Create the batch processing table
     * 
     * This table tracks background processing jobs, including their progress
     * and configuration. Uses dbDelta for safe table creation/updates.
     * 
     * Security Note: Table/column names are hardcoded, not user-supplied,
     * so SQL injection is not a concern here.
     */
    private function create_batch_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        $charset_collate = $wpdb->get_charset_collate();
        
        // SQL for batch tracking table
        // All column names are predefined, not from user input
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            post_type varchar(100) NOT NULL,
            taxonomy varchar(100) NOT NULL,
            total_items int(11) NOT NULL DEFAULT 0,
            processed_items int(11) NOT NULL DEFAULT 0,
            api_calls_made int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            ignore_categorized tinyint(1) NOT NULL DEFAULT 1,
            date_from date DEFAULT NULL,
            date_to date DEFAULT NULL,
            exclude_taxonomy varchar(100) DEFAULT NULL,
            confidence_threshold int(11) NOT NULL DEFAULT 0,
            next_retry_at datetime DEFAULT NULL,
            pause_reason varchar(50) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY batch_id (batch_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create the feedback logging table
     * 
     * This table stores rejection feedback when users override AI suggestions.
     * The data can be used to identify patterns in incorrect categorizations
     * and potentially improve AI prompts over time.
     */
    private function create_feedback_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_feedback';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            suggested_term_id bigint(20) NOT NULL,
            selected_term_id bigint(20) DEFAULT NULL,
            notes text,
            taxonomy varchar(100) NOT NULL,
            user_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY taxonomy (taxonomy)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add columns if upgrading from a version that lacks them.
     */
    private function maybe_migrate_batch_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_batches';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name", 0);
        if (empty($columns)) {
            return;
        }
        if (!in_array('api_calls_made', $columns, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN api_calls_made int(11) NOT NULL DEFAULT 0 AFTER processed_items");
        }
        if (!in_array('next_retry_at', $columns, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN next_retry_at datetime DEFAULT NULL AFTER confidence_threshold");
        }
        if (!in_array('pause_reason', $columns, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN pause_reason varchar(50) DEFAULT NULL AFTER next_retry_at");
        }
    }

    /**
     * Register admin menu pages
     *
     * Places plugin pages under the shared "ASAE" top-level menu created by
     * ASAE Explore. If Explore is not active, a fallback top-level ASAE menu
     * is created so the plugin remains accessible.
     */
    public function add_admin_menu() {
        // If the ASAE top-level menu doesn't exist yet (Explore not active),
        // create a fallback so our submenu pages have a parent.
        global $admin_page_hooks;
        if (empty($admin_page_hooks['asae'])) {
            add_menu_page(
                __('ASAE', 'asae-taxonomy-organizer'),
                __('ASAE', 'asae-taxonomy-organizer'),
                'manage_options',
                'asae',
                array($this, 'render_admin_page'),
                'dashicons-building',
                30
            );
        }

        // Main Taxonomy Organizer page under ASAE menu
        $this->organizer_hook = add_submenu_page(
            'asae',
            __('Taxonomy Organizer', 'asae-taxonomy-organizer'),
            __('Taxonomy Organizer', 'asae-taxonomy-organizer'),
            'manage_options',
            'asae-taxonomy-organizer',
            array($this, 'render_admin_page')
        );

        // Settings page (hidden from menu, accessible via direct link)
        $this->settings_hook = add_submenu_page(
            null,
            __('Taxonomy Organizer - OpenAI Settings', 'asae-taxonomy-organizer'),
            __('OpenAI Settings', 'asae-taxonomy-organizer'),
            'manage_options',
            'asae-taxonomy-organizer-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Enqueue admin CSS and JavaScript
     * 
     * Only loads assets on our plugin's pages to avoid conflicts with other
     * plugins and to minimize page load impact elsewhere in admin.
     * 
     * @param string $hook The current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if ($hook !== $this->organizer_hook && $hook !== $this->settings_hook) {
            return;
        }
        
        // Admin stylesheet
        wp_enqueue_style(
            'asae-to-admin',
            ASAE_TO_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            ASAE_TO_VERSION
        );
        
        // Admin JavaScript
        wp_enqueue_script(
            'asae-to-admin',
            ASAE_TO_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            ASAE_TO_VERSION,
            true
        );
        
        // Pass data to JavaScript
        // This includes AJAX URL, nonce, and current settings for the JS to use
        $batch_manager = new ASAE_TO_Batch_Manager();
        $running_batch = $batch_manager->get_running_batch();

        wp_localize_script('asae-to-admin', 'asaeToAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asae_to_nonce'),
            'version' => ASAE_TO_VERSION,
            'useAI' => get_option('asae_to_use_ai', 'no'),
            'runningBatchId' => $running_batch ? $running_batch->batch_id : '',
            'runningBatchProcessed' => $running_batch ? intval($running_batch->processed_items) : 0,
            'runningBatchTotal' => $running_batch ? intval($running_batch->total_items) : 0,
        ));
    }
    
    /**
     * Render the main Organizer admin page.
     * Also triggers stall detection for stuck batches.
     */
    public function render_admin_page() {
        // Detect and requeue any stalled batch jobs
        $batch_manager = new ASAE_TO_Batch_Manager();
        $batch_manager->detect_and_requeue_stalled();

        $admin = new ASAE_TO_Admin();
        $admin->render();
    }
    
    /**
     * Render the OpenAI Settings page
     */
    public function render_settings_page() {
        $settings = new ASAE_TO_Settings();
        $settings->render();
    }
    
    /**
     * AJAX: Get taxonomies for a post type
     * 
     * Returns the list of taxonomies associated with a given post type.
     * Used to populate the taxonomy dropdown when a post type is selected.
     */
    public function ajax_get_taxonomies() {
        // Security: Verify nonce to prevent CSRF attacks
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        // Security: Verify user has admin capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Sanitize input
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
        
        if (empty($post_type)) {
            wp_send_json_error('Post type required');
        }
        
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        
        $result = array();
        foreach ($taxonomies as $taxonomy) {
            $result[] = array(
                'name' => $taxonomy->name,
                'label' => $taxonomy->label
            );
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get terms for a taxonomy
     * 
     * Returns all terms in a taxonomy. Used for the category selection dropdown
     * when rejecting an AI suggestion and choosing a different category.
     */
    public function ajax_get_terms() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        
        if (empty($taxonomy)) {
            wp_send_json_error('Taxonomy required');
        }
        
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms)) {
            wp_send_json_error($terms->get_error_message());
        }
        
        $result = array();
        foreach ($terms as $term) {
            $result[] = array(
                'term_id' => $term->term_id,
                'name' => $term->name
            );
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Process content for categorization
     * 
     * Main entry point for content analysis. Validates inputs, then delegates
     * to the Processor class for the actual work.
     */
    public function ajax_process_content() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $processor = new ASAE_TO_Processor();
        $result = $processor->process($_POST);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Cancel a batch process
     * 
     * Stops an in-progress batch job and clears its scheduled events.
     */
    public function ajax_cancel_batch() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error('Batch ID required');
        }
        
        $batch_manager = new ASAE_TO_Batch_Manager();
        $result = $batch_manager->cancel_batch($batch_id);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Get current batch status
     * 
     * Returns the list of active batch jobs for the status panel.
     */
    public function ajax_get_batch_status() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $batch_manager = new ASAE_TO_Batch_Manager();
        $batches = $batch_manager->get_active_batches();
        
        wp_send_json_success($batches);
    }
    
    /**
     * AJAX: Save approved items
     * 
     * Saves the taxonomy terms for items that the user has approved in preview mode.
     */
    public function ajax_save_items() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : array();
        
        if (empty($taxonomy)) {
            wp_send_json_error('Taxonomy required');
        }
        
        $processor = new ASAE_TO_Processor();
        $result = $processor->save_approved_items(array(
            'taxonomy' => $taxonomy,
            'items' => $items
        ));
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Log rejection feedback
     * 
     * Records when a user rejects an AI suggestion, along with their chosen
     * category and notes explaining why. This data helps improve the system.
     */
    public function ajax_log_rejection() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $suggested_term_id = isset($_POST['suggested_term_id']) ? intval($_POST['suggested_term_id']) : 0;
        $selected_term_id = isset($_POST['selected_term_id']) ? intval($_POST['selected_term_id']) : null;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        
        if (empty($post_id) || empty($taxonomy)) {
            wp_send_json_error('Missing required fields');
        }
        
        $logger = new ASAE_TO_Feedback_Logger();
        $result = $logger->log_rejection($post_id, $suggested_term_id, $selected_term_id, $notes, $taxonomy);
        
        wp_send_json(array(
            'success' => $result,
            'message' => $result ? __('Feedback logged successfully.', 'asae-taxonomy-organizer') : __('Failed to log feedback.', 'asae-taxonomy-organizer')
        ));
    }
    
    /**
     * AJAX: Process a single preview chunk (chunked preview mode)
     */
    public function ajax_process_preview_chunk() {
        check_ajax_referer('asae_to_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $processor = new ASAE_TO_Processor();
        $result = $processor->process_preview_chunk($_POST);

        wp_send_json($result);
    }

    /**
     * AJAX: Get cost estimate before processing
     */
    public function ajax_get_cost_estimate() {
        check_ajax_referer('asae_to_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $processor = new ASAE_TO_Processor();
        $estimate = $processor->get_cost_estimate($_POST);

        wp_send_json_success($estimate);
    }

    /**
     * AJAX: Reset monthly usage counter
     */
    public function ajax_reset_usage() {
        check_ajax_referer('asae_to_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        ASAE_TO_AI_Analyzer::reset_monthly_usage();

        wp_send_json_success(array('message' => __('Usage counter reset.', 'asae-taxonomy-organizer')));
    }

    /**
     * AJAX: Get batch progress (polled by JS during batch runs)
     */
    public function ajax_get_batch_progress() {
        check_ajax_referer('asae_to_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';

        if (empty($batch_id)) {
            wp_send_json_error('Batch ID required');
        }

        $batch_manager = new ASAE_TO_Batch_Manager();
        $batch = $batch_manager->get_batch($batch_id);

        if (!$batch) {
            wp_send_json_error('Batch not found');
        }

        wp_send_json_success(array(
            'batch_id'        => $batch->batch_id,
            'status'          => $batch->status,
            'processed_items' => intval($batch->processed_items),
            'total_items'     => intval($batch->total_items),
            'api_calls_made'  => intval($batch->api_calls_made),
            'next_retry_at'   => isset($batch->next_retry_at) ? $batch->next_retry_at : null,
            'pause_reason'    => isset($batch->pause_reason) ? $batch->pause_reason : null,
            'is_complete'     => in_array($batch->status, array('completed', 'cancelled', 'failed'), true),
        ));
    }

    /**
     * AJAX: Save OpenAI settings
     * 
     * Saves the API key and model selection to WordPress options.
     * 
     * Security Note: API keys are stored in wp_options which is NOT encrypted by default.
     * For production environments with higher security requirements, consider using
     * environment variables or a dedicated secrets management solution.
     */
    public function ajax_save_settings() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Sanitize inputs
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-4o-mini';
        $use_ai = isset($_POST['use_ai']) && $_POST['use_ai'] === 'yes' ? 'yes' : 'no';
        
        // Validate model is in allowed list
        $settings = new ASAE_TO_Settings();
        $allowed_models = array_keys($settings->get_available_models());
        if (!in_array($model, $allowed_models)) {
            $model = 'gpt-4o-mini';
        }
        
        // Cost control settings
        $monthly_limit = isset($_POST['monthly_api_limit']) ? max(0, intval($_POST['monthly_api_limit'])) : 0;
        $api_delay     = isset($_POST['api_delay']) ? max(0, min(5000, intval($_POST['api_delay']))) : 200;
        $retry_delay   = isset($_POST['retry_delay']) ? max(1, min(1440, intval($_POST['retry_delay']))) : 60;

        // Save settings
        update_option('asae_to_openai_api_key', $api_key);
        update_option('asae_to_openai_model', $model);
        update_option('asae_to_use_ai', $use_ai);
        update_option('asae_to_monthly_api_call_limit', $monthly_limit);
        update_option('asae_to_api_call_delay_ms', $api_delay);
        update_option('asae_to_api_retry_delay_minutes', $retry_delay);
        
        wp_send_json(array(
            'success' => true,
            'message' => __('Settings saved successfully.', 'asae-taxonomy-organizer')
        ));
    }
    
    /**
     * AJAX: Test OpenAI connection
     * 
     * Makes a simple API call to verify the API key works and the selected
     * model is accessible. This gives users confidence before they start
     * processing content.
     */
    public function ajax_test_connection() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-4o-mini';
        
        if (empty($api_key)) {
            wp_send_json(array(
                'success' => false,
                'message' => __('Please enter an API key.', 'asae-taxonomy-organizer')
            ));
        }
        
        // Make a minimal test request
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => 'Say "test successful" in exactly those words.'
                    )
                ),
                'max_tokens' => 10,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json(array(
                'success' => false,
                'message' => sprintf(__('Connection failed: %s', 'asae-taxonomy-organizer'), $response->get_error_message())
            ));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200) {
            wp_send_json(array(
                'success' => true,
                'message' => sprintf(__('Connection successful! Model "%s" is accessible.', 'asae-taxonomy-organizer'), $model)
            ));
        } elseif ($code === 401) {
            wp_send_json(array(
                'success' => false,
                'message' => __('Invalid API key. Please check your credentials.', 'asae-taxonomy-organizer')
            ));
        } elseif ($code === 404) {
            wp_send_json(array(
                'success' => false,
                'message' => sprintf(__('Model "%s" not found or not accessible with your API key.', 'asae-taxonomy-organizer'), $model)
            ));
        } else {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : __('Unknown error', 'asae-taxonomy-organizer');
            wp_send_json(array(
                'success' => false,
                'message' => sprintf(__('API error: %s', 'asae-taxonomy-organizer'), $error_msg)
            ));
        }
    }
}

/**
 * Get the plugin instance
 * 
 * Global function to access the plugin instance from anywhere.
 * 
 * @return ASAE_Taxonomy_Organizer
 */
function asae_taxonomy_organizer() {
    return ASAE_Taxonomy_Organizer::get_instance();
}

// Initialize the plugin when WordPress is ready
add_action('plugins_loaded', 'asae_taxonomy_organizer');
