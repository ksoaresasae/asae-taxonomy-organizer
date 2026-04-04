<?php
/**
 * Plugin Name: ASAE Taxonomy Organizer
 * Plugin URI: https://www.asaecenter.org
 * Description: Use AI to automatically analyze WordPress content and categorize it with appropriate taxonomy terms.
 * Version: 1.3.6
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

define('ASAE_TO_VERSION', '1.3.6');
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

        // Reports data and rendering
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-reports.php';

        // GA4 pageviews report
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-ga4-reports.php';


        // Self-hosted GitHub updater
        require_once ASAE_TO_PLUGIN_DIR . 'includes/class-github-updater.php';
    }
    
    /**
     * Register all WordPress hooks
     * 
     * This method sets up all the connections between WordPress events and our
     * plugin's functionality. Organized by type for easier maintenance.
     */
    private function init_hooks() {
        // Custom cron schedule for watchdog
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        // Auto-upgrade DB schema when plugin version changes
        add_action('admin_init', array($this, 'maybe_upgrade_db'));

        // Admin menu (priority 20: runs after ASAE Explore's priority 10)
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers for the Organizer page
        add_action('wp_ajax_asae_to_get_taxonomies', array($this, 'ajax_get_taxonomies'));
        add_action('wp_ajax_asae_to_process_content', array($this, 'ajax_process_content'));
        add_action('wp_ajax_asae_to_cancel_batch', array($this, 'ajax_cancel_batch'));
        add_action('wp_ajax_asae_to_cancel_all_batches', array($this, 'ajax_cancel_all_batches'));
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
        add_action('wp_ajax_asae_to_heartbeat', array($this, 'ajax_heartbeat'));
        add_action('wp_ajax_asae_to_check_updates', array($this, 'ajax_check_updates'));
        add_action('wp_ajax_asae_to_save_report_settings', array($this, 'ajax_save_report_settings'));
        add_action('wp_ajax_asae_to_cleanup_redundant_tags', array($this, 'ajax_cleanup_redundant_tags'));

        // GA4 report handlers
        add_action('wp_ajax_asae_to_save_ga4_settings', array($this, 'ajax_save_ga4_settings'));
        add_action('wp_ajax_asae_to_test_ga4_connection', array($this, 'ajax_test_ga4_connection'));

        // GA4 REST endpoint and cron
        ASAE_TO_GA4_Reports::init();

        // AJAX handlers for Reports
        add_action('wp_ajax_asae_to_get_report_categories', array($this, 'ajax_get_report_categories'));
        add_action('wp_ajax_asae_to_get_report_tags', array($this, 'ajax_get_report_tags'));
        add_action('wp_ajax_asae_to_get_report_all_tags', array($this, 'ajax_get_report_all_tags'));

        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));

        // Cache invalidation for reports
        add_action('save_post', array('ASAE_TO_Reports', 'invalidate_caches'));
        add_action('delete_post', array('ASAE_TO_Reports', 'invalidate_caches'));
        add_action('set_object_terms', function($object_id) { ASAE_TO_Reports::invalidate_caches($object_id); });
        add_action('created_term', function() { ASAE_TO_Reports::invalidate_all_caches(); });
        add_action('delete_term', function() { ASAE_TO_Reports::invalidate_all_caches(); });

        // Self-hosted update checker (GitHub Releases)
        new ASAE_TO_GitHub_Updater();

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

        // Schedule the batch watchdog (every 5 minutes)
        if (!wp_next_scheduled('asae_to_batch_watchdog')) {
            wp_schedule_event(time(), 'asae_to_five_minutes', 'asae_to_batch_watchdog');
        }

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
        wp_clear_scheduled_hook('asae_to_batch_watchdog');
        ASAE_TO_GA4_Reports::deactivate();
    }
    
    /**
     * Run DB schema updates when the plugin version changes.
     */
    /**
     * Register custom cron schedules.
     */
    public function add_cron_schedules($schedules) {
        $schedules['asae_to_five_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'asae-taxonomy-organizer'),
        );
        return $schedules;
    }

    public function maybe_upgrade_db() {
        $installed_version = get_option('asae_to_db_version', '0');
        if (version_compare($installed_version, ASAE_TO_VERSION, '<')) {
            $this->create_batch_table();
            $this->create_feedback_table();
            update_option('asae_to_db_version', ASAE_TO_VERSION);

            // Ensure watchdog cron is registered on upgrades (not just fresh activation)
            if (!wp_next_scheduled('asae_to_batch_watchdog')) {
                wp_schedule_event(time(), 'asae_to_five_minutes', 'asae_to_batch_watchdog');
            }

            // Clear report caches on upgrade so new filtering/logic takes effect
            ASAE_TO_Reports::invalidate_all_caches();
        }

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
        // Load reports assets on our plugin page and the dashboard
        $is_plugin_page = ($hook === $this->organizer_hook);
        $is_dashboard = ($hook === 'index.php');

        if ($is_plugin_page || $is_dashboard) {
            wp_enqueue_style(
                'asae-to-reports',
                ASAE_TO_PLUGIN_URL . 'admin/css/reports.css',
                array(),
                ASAE_TO_VERSION
            );
            wp_enqueue_script(
                'asae-to-chartjs',
                ASAE_TO_PLUGIN_URL . 'admin/js/lib/chart.min.js',
                array(),
                '4.4.7',
                true
            );
            wp_enqueue_script(
                'asae-to-reports',
                ASAE_TO_PLUGIN_URL . 'admin/js/reports.js',
                array('jquery', 'asae-to-chartjs'),
                ASAE_TO_VERSION,
                true
            );
            wp_localize_script('asae-to-reports', 'asaeToReports', array(
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('asae_to_nonce'),
                'restUrl'  => rest_url('ato/v1/'),
                'restNonce' => wp_create_nonce('wp_rest'),
            ));
        }

        // Only load organizer/settings assets on our plugin page
        if (!$is_plugin_page) {
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
            'runningBatchStatus' => $running_batch ? $running_batch->status : '',
            'runningBatchPauseReason' => $running_batch && isset($running_batch->pause_reason) ? $running_batch->pause_reason : '',
            'runningBatchNextRetry' => $running_batch && isset($running_batch->next_retry_at) ? $running_batch->next_retry_at : '',
            'pluginsUrl' => admin_url('plugins.php'),
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
     * AJAX: Cancel all active batches
     */
    public function ajax_cancel_all_batches() {
        check_ajax_referer('asae_to_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $batch_manager = new ASAE_TO_Batch_Manager();
        $result = $batch_manager->cancel_all_batches();

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

        // Diagnostics: is a cron event scheduled? Is the lock held?
        $cron_scheduled = wp_next_scheduled('asae_to_process_batch', array($batch->batch_id));
        $lock_held = (bool) get_transient('asae_to_lock_' . $batch->batch_id);

        // How long since the batch record was last touched?
        $updated_ts = isset($batch->updated_at) ? strtotime($batch->updated_at) : 0;
        $idle_seconds = $updated_ts > 0 ? time() - $updated_ts : 0;

        // Direct execution fallback: if batch is idle and unlocked, cron
        // is clearly not working on this host.  Run the processor directly
        // from this AJAX request instead of waiting.
        $ran_directly = false;
        if (
            in_array($batch->status, array('pending', 'processing', 'paused'), true)
            && !$lock_held
            && $idle_seconds > 60
        ) {
            // Clear the stale cron event
            $batch_manager->cancel_scheduled($batch->batch_id);

            // Run one chunk synchronously
            $processor = new ASAE_TO_Processor();
            $processor->process_batch_chunk($batch->batch_id);

            // Re-read batch state after processing
            $batch = $batch_manager->get_batch($batch_id);
            $cron_scheduled = wp_next_scheduled('asae_to_process_batch', array($batch->batch_id));
            $lock_held = (bool) get_transient('asae_to_lock_' . $batch->batch_id);
            $updated_ts = isset($batch->updated_at) ? strtotime($batch->updated_at) : 0;
            $idle_seconds = $updated_ts > 0 ? time() - $updated_ts : 0;
            $ran_directly = true;
        } else {
            // Try spawn_cron as usual
            if (!defined('DOING_CRON') && !wp_doing_cron()) {
                spawn_cron();
            }
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
            'updated_at'      => isset($batch->updated_at) ? $batch->updated_at : null,
            'idle_seconds'    => $idle_seconds,
            'cron_scheduled'  => $cron_scheduled ? true : false,
            'cron_due_in'     => $cron_scheduled ? max(0, $cron_scheduled - time()) : null,
            'lock_held'       => $lock_held,
            'ran_directly'    => $ran_directly,
        ));
    }

    /**
     * AJAX: Save report settings (ignored tags) — separate from API settings.
     */
    public function ajax_save_report_settings() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $ignored_tags = isset($_POST['report_ignored_tags']) ? sanitize_textarea_field($_POST['report_ignored_tags']) : '';
        update_option('asae_to_report_ignored_tags', $ignored_tags);
        ASAE_TO_Reports::invalidate_all_caches();

        wp_send_json_success(array('message' => __('Report settings saved.', 'asae-taxonomy-organizer')));
    }

    /**
     * AJAX: Save GA4 settings (property ID and service account JSON).
     */
    public function ajax_save_ga4_settings() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $property_id = isset($_POST['property_id']) ? sanitize_text_field($_POST['property_id']) : '';
        $json_raw = isset($_POST['service_account_json']) ? wp_unslash($_POST['service_account_json']) : '';

        // Save property ID
        update_option('ato_ga4_property_id', $property_id);

        // Validate and save service account JSON if provided
        if (!empty($json_raw)) {
            $parsed = json_decode($json_raw, true);
            if ($parsed === null) {
                wp_send_json_error('Invalid JSON. Please check the format.');
            }

            $required_keys = array('type', 'project_id', 'private_key', 'client_email');
            foreach ($required_keys as $key) {
                if (empty($parsed[$key])) {
                    wp_send_json_error('Missing required key: ' . $key);
                }
            }

            // Encrypt and store
            $encrypted = ASAE_TO_GA4_Reports::encrypt_credentials($json_raw);
            update_option('ato_ga4_service_account_json', $encrypted);
            update_option('ato_ga4_client_email', sanitize_email($parsed['client_email']));

            // Clear caches since config changed
            ASAE_TO_GA4_Reports::clear_caches();
        }

        wp_send_json_success(array('message' => __('GA4 settings saved.', 'asae-taxonomy-organizer')));
    }

    /**
     * AJAX: Test GA4 connection.
     */
    public function ajax_test_ga4_connection() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = ASAE_TO_GA4_Reports::test_connection();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $property_id = get_option('ato_ga4_property_id', '');
        wp_send_json_success(array(
            'message' => sprintf(__('Connected successfully to GA4 property %s.', 'asae-taxonomy-organizer'), $property_id),
        ));
    }

    /**
     * AJAX: Process one chunk of redundant tag cleanup.
     */
    public function ajax_cleanup_redundant_tags() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
        $chunk_size = isset($_POST['chunk_size']) ? min(200, max(1, intval($_POST['chunk_size']))) : 100;

        $processor = new ASAE_TO_Processor();

        // On first call, include total count
        $result = $processor->cleanup_redundant_tags_chunk($offset, $chunk_size);
        if ($offset === 0) {
            $result['total'] = $processor->count_posts_with_categories_and_tags();
        }

        wp_send_json_success($result);
    }

    /**
     * Register the dashboard widget.
     */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'asae_to_report_widget',
            __('Content Categories', 'asae-taxonomy-organizer'),
            array('ASAE_TO_Reports', 'render_dashboard_widget')
        );
    }

    /**
     * AJAX: Get category breakdown for reports.
     */
    public function ajax_get_report_categories() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'all';
        $data = ASAE_TO_Reports::get_category_data($post_type, $date_range);
        wp_send_json_success($data);
    }

    /**
     * AJAX: Get tag breakdown for a category (drill-down).
     */
    public function ajax_get_report_tags() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $term_id = isset($_POST['category_term_id']) ? intval($_POST['category_term_id']) : 0;

        if ($term_id <= 0) {
            wp_send_json_error('Invalid category');
        }

        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'all';
        $data = ASAE_TO_Reports::get_tag_data($post_type, $term_id, $date_range);
        wp_send_json_success($data);
    }

    /**
     * AJAX: Get ALL tags for a category (no limit, for the "Other" drill-down).
     */
    public function ajax_get_report_all_tags() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $term_id = isset($_POST['category_term_id']) ? intval($_POST['category_term_id']) : 0;

        if ($term_id <= 0) {
            wp_send_json_error('Invalid category');
        }

        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'all';
        $data = ASAE_TO_Reports::get_all_tag_data($post_type, $term_id, $date_range);
        wp_send_json_success($data);
    }

    /**
     * AJAX: Lightweight heartbeat to keep the browser tab alive during processing.
     */
    public function ajax_heartbeat() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        // Keep cron alive while the user has the page open
        if (!defined('DOING_CRON') && !wp_doing_cron()) {
            spawn_cron();
        }
        wp_send_json_success();
    }

    /**
     * AJAX: Clear the GitHub release cache and force WordPress to re-check for updates.
     */
    public function ajax_check_updates() {
        check_ajax_referer('asae_to_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Clear the cached GitHub release data
        delete_transient('asae_to_github_release');

        // Force WordPress to re-check plugin updates
        delete_site_transient('update_plugins');

        // Fetch fresh release info
        $updater_class = 'ASAE_TO_GitHub_Updater';
        if (class_exists($updater_class)) {
            $updater = new $updater_class();
            wp_update_plugins();
        }

        // Check if an update is now available
        $update_plugins = get_site_transient('update_plugins');
        $has_update = isset($update_plugins->response[ASAE_TO_PLUGIN_BASENAME]);
        $new_version = $has_update ? $update_plugins->response[ASAE_TO_PLUGIN_BASENAME]->new_version : null;

        wp_send_json_success(array(
            'current_version' => ASAE_TO_VERSION,
            'has_update'      => $has_update,
            'new_version'     => $new_version,
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
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-4.1-mini';
        $use_ai = isset($_POST['use_ai']) && $_POST['use_ai'] === 'yes' ? 'yes' : 'no';
        
        // Validate model is in allowed list
        $settings = new ASAE_TO_Settings();
        $allowed_models = array_keys($settings->get_available_models());
        if (!in_array($model, $allowed_models)) {
            $model = 'gpt-4.1-mini';
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
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-4.1-mini';
        
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
