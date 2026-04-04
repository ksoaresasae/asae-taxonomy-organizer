<?php
/**
 * ASAE Taxonomy Organizer - Main Admin Page
 *
 * Renders the plugin's admin interface with tab navigation:
 * - Organizer tab: content selection, filtering, analysis, and results
 * - Settings tab: OpenAI API configuration, model selection, cost controls
 *
 * Tab pattern matches ASAE Content Ingestor: standard WordPress nav-tab-wrapper
 * with full-page links using &tab= query parameter.
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
     * Render the admin page with tab navigation
     */
    public function render() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'reports';
        $page_slug  = 'asae-taxonomy-organizer';
        ?>
        <div class="wrap asae-to-wrap">
            <h1><?php _e('ASAE Taxonomy Organizer', 'asae-taxonomy-organizer'); ?>
                <span class="asae-to-version">v<?php echo esc_html(ASAE_TO_VERSION); ?></span>
            </h1>

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Taxonomy Organizer navigation', 'asae-taxonomy-organizer'); ?>">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug)); ?>"
                   class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>"
                   <?php echo $active_tab === 'reports' ? 'aria-current="page"' : ''; ?>>
                    <?php _e('Reports', 'asae-taxonomy-organizer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=organizer')); ?>"
                   class="nav-tab <?php echo $active_tab === 'organizer' ? 'nav-tab-active' : ''; ?>"
                   <?php echo $active_tab === 'organizer' ? 'aria-current="page"' : ''; ?>>
                    <?php _e('Organizer', 'asae-taxonomy-organizer'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=settings')); ?>"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>"
                   <?php echo $active_tab === 'settings' ? 'aria-current="page"' : ''; ?>>
                    <?php _e('Settings', 'asae-taxonomy-organizer'); ?>
                </a>
            </nav>

            <?php
            if ($active_tab === 'organizer') {
                $this->render_organizer_tab();
            } elseif ($active_tab === 'settings') {
                $this->render_settings_tab();
            } else {
                $this->render_reports_tab();
            }
            ?>

            <div class="asae-to-footer">
                <p>ASAE Taxonomy Organizer</p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Reports tab content
     */
    private function render_reports_tab() {
        ASAE_TO_Reports::render();
        $this->render_ga4_views_chart();
    }

    /**
     * Render the GA4 pageviews by category chart section.
     */
    private function render_ga4_views_chart() {
        $ga4_configured = !empty(get_option('ato_ga4_property_id', '')) && !empty(get_option('ato_ga4_service_account_json', ''));
        ?>
        <div class="asae-to-container" style="margin-top: 20px;">
            <div class="asae-to-main">
                <div class="asae-to-card">
                    <div class="asae-to-report-header">
                        <h2 id="asae-to-ga4-title"><?php _e('Pageviews by Category', 'asae-taxonomy-organizer'); ?></h2>
                        <?php if ($ga4_configured): ?>
                        <div class="asae-to-report-controls">
                            <select id="asae-to-ga4-window" aria-label="<?php esc_attr_e('Time window', 'asae-taxonomy-organizer'); ?>">
                                <option value="3mo" selected><?php _e('Last 3 Months', 'asae-taxonomy-organizer'); ?></option>
                                <option value="12mo"><?php _e('Last 12 Months', 'asae-taxonomy-organizer'); ?></option>
                                <option value="all"><?php _e('All Time', 'asae-taxonomy-organizer'); ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$ga4_configured): ?>
                        <div class="asae-to-report-empty">
                            <?php printf(
                                __('GA4 credentials are not configured. <a href="%s">Set up GA4</a> on the Settings tab to enable this report.', 'asae-taxonomy-organizer'),
                                esc_url(admin_url('admin.php?page=asae-taxonomy-organizer&tab=settings#ga4-settings'))
                            ); ?>
                        </div>
                    <?php else: ?>
                        <div class="asae-to-report-body" aria-live="polite">
                            <div id="asae-to-ga4-spinner" class="asae-to-chart-spinner">
                                <span class="spinner is-active"></span>
                            </div>
                            <canvas id="asae-to-ga4-chart"
                                    role="img"
                                    aria-label="<?php esc_attr_e('Pageviews by category chart', 'asae-taxonomy-organizer'); ?>"
                                    style="display: none;"></canvas>
                            <div id="asae-to-ga4-table-wrap" class="asae-to-report-table-wrap"></div>
                        </div>
                        <p id="asae-to-ga4-footer" class="description" style="display: none; margin-top: 10px; font-size: 11px; color: #888;">
                            <?php _e('Posts assigned to multiple categories are counted in each. Data refreshed daily from Google Analytics 4.', 'asae-taxonomy-organizer'); ?>
                            <span id="asae-to-ga4-updated"></span>
                        </p>
                        <div id="asae-to-ga4-error" style="display: none;">
                            <p class="error-message" id="asae-to-ga4-error-msg"></p>
                            <button type="button" id="asae-to-ga4-retry" class="button button-small"><?php _e('Retry', 'asae-taxonomy-organizer'); ?></button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Organizer tab content
     */
    private function render_organizer_tab() {
        $post_types = $this->get_post_types();
        $all_taxonomies = $this->get_all_taxonomies();

        $use_ai = get_option('asae_to_use_ai', 'no');
        $api_key = get_option('asae_to_openai_api_key', '');
        $ai_available = ($use_ai === 'yes' && !empty($api_key));
        ?>
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
                        <a href="<?php echo esc_url(admin_url('admin.php?page=asae-taxonomy-organizer&tab=settings')); ?>">
                            <?php _e('Enable AI for better accuracy', 'asae-taxonomy-organizer'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Resume Banner (shown when a running batch is detected on page load) -->
                <?php
                $batch_manager = new ASAE_TO_Batch_Manager();
                $running_batch = $batch_manager->get_running_batch();
                ?>
                <div id="asae-to-resume-banner" class="asae-to-card asae-to-resume-banner" style="display: none;" role="alert">
                    <p id="asae-to-resume-text">
                        <strong><?php _e('A running batch was found.', 'asae-taxonomy-organizer'); ?></strong>
                        <span id="asae-to-resume-detail"></span>
                    </p>
                    <div class="asae-to-resume-actions">
                        <button type="button" id="asae-to-resume-btn" class="button button-primary">
                            <?php _e('Resume', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <button type="button" id="asae-to-cancel-running-btn" class="button">
                            <?php _e('Cancel Job', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <button type="button" id="asae-to-cancel-all-btn" class="button" title="<?php esc_attr_e('Cancel all pending, processing, and paused batches', 'asae-taxonomy-organizer'); ?>">
                            <?php _e('Cancel All Jobs', 'asae-taxonomy-organizer'); ?>
                        </button>
                    </div>
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
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=asae-taxonomy-organizer&tab=settings')); ?>">
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

                <!-- Inline Progress Panel (shown during batch/processing runs) -->
                <div class="asae-to-card" id="asae-to-progress-panel" style="display: none;" aria-live="polite">
                    <h2 id="asae-to-progress-heading"><?php _e('Processing', 'asae-taxonomy-organizer'); ?></h2>
                    <div class="asae-to-status-line">
                        <strong><?php _e('Status:', 'asae-taxonomy-organizer'); ?></strong>
                        <span id="asae-to-phase-label"><?php _e('Starting…', 'asae-taxonomy-organizer'); ?></span>
                    </div>
                    <div class="asae-to-progress-section">
                        <p class="asae-to-progress-label">
                            <span id="asae-to-processed-count">0</span> / <span id="asae-to-total-count">0</span>
                            <?php _e('items processed', 'asae-taxonomy-organizer'); ?>
                        </p>
                        <div class="asae-to-progress-bar-wrap" role="progressbar"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                             aria-label="<?php esc_attr_e('Processing progress', 'asae-taxonomy-organizer'); ?>"
                             id="asae-to-progress-bar-wrap">
                            <div class="asae-to-progress-bar" id="asae-to-progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                    <p id="asae-to-progress-complete" style="display: none;" class="asae-to-complete-notice"></p>
                    <p class="asae-to-diagnostics" id="asae-to-diagnostics"></p>
                    <p>
                        <button type="button" id="asae-to-cancel-batch-btn" class="button">
                            <?php _e('Cancel', 'asae-taxonomy-organizer'); ?>
                        </button>
                    </p>
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
        <?php
    }

    /**
     * Render the Settings tab content
     */
    private function render_settings_tab() {
        $api_key = get_option('asae_to_openai_api_key', '');
        $selected_model = get_option('asae_to_openai_model', 'gpt-4.1-mini');
        $use_ai = get_option('asae_to_use_ai', 'no');
        $monthly_limit = get_option('asae_to_monthly_api_call_limit', 0);
        $api_delay = get_option('asae_to_api_call_delay_ms', 200);
        $retry_delay = get_option('asae_to_api_retry_delay_minutes', 60);
        $usage = ASAE_TO_AI_Analyzer::get_monthly_usage();

        $settings = new ASAE_TO_Settings();
        $available_models = $settings->get_available_models();
        ?>
        <div class="asae-to-container">
            <div class="asae-to-main">
                <div class="asae-to-card">
                    <h2><?php _e('API Configuration', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Configure your OpenAI API credentials for AI-powered content categorization. Without these settings, the plugin will use keyword matching instead.', 'asae-taxonomy-organizer'); ?>
                    </p>

                    <form id="asae-to-settings-form" class="asae-to-form">
                        <?php wp_nonce_field('asae_to_settings_nonce', 'settings_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="openai_api_key"><?php _e('OpenAI API Key', 'asae-taxonomy-organizer'); ?></label>
                                </th>
                                <td>
                                    <div class="asae-to-password-field">
                                        <input type="password"
                                               name="openai_api_key"
                                               id="openai_api_key"
                                               class="regular-text"
                                               value="<?php echo esc_attr($api_key); ?>"
                                               autocomplete="off">
                                        <button type="button" id="toggle-api-key" class="button" aria-label="<?php esc_attr_e('Show API key', 'asae-taxonomy-organizer'); ?>">
                                            <span class="dashicons dashicons-visibility"></span>
                                            <?php _e('Show', 'asae-taxonomy-organizer'); ?>
                                        </button>
                                    </div>
                                    <p class="description">
                                        <?php _e('Your OpenAI API key. Get one from', 'asae-taxonomy-organizer'); ?>
                                        <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="openai_model"><?php _e('OpenAI Model', 'asae-taxonomy-organizer'); ?></label>
                                </th>
                                <td>
                                    <select name="openai_model" id="openai_model" class="regular-text">
                                        <?php foreach ($available_models as $model_id => $model_name): ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" <?php selected($selected_model, $model_id); ?>>
                                                <?php echo esc_html($model_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php _e('Select which OpenAI model to use for content analysis. More capable models are more accurate but cost more.', 'asae-taxonomy-organizer'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="button" id="test-connection-btn" class="button">
                                <?php _e('Test Connection', 'asae-taxonomy-organizer'); ?>
                            </button>
                            <button type="submit" id="save-settings-btn" class="button button-primary">
                                <?php _e('Save Settings', 'asae-taxonomy-organizer'); ?>
                            </button>
                            <span id="settings-spinner" class="spinner" style="float: none; margin-left: 10px;"></span>
                        </p>

                        <div id="connection-result" style="display: none;"></div>
                    </form>
                </div>

                <div class="asae-to-card">
                    <h2><?php _e('Analysis Method', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Choose whether to use AI-powered analysis or fall back to keyword matching.', 'asae-taxonomy-organizer'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="use_ai"><?php _e('Use OpenAI for Analysis', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <label class="asae-to-toggle">
                                    <input type="checkbox" name="use_ai" id="use_ai" <?php checked($use_ai, 'yes'); ?>>
                                    <span class="asae-to-toggle-slider"></span>
                                </label>
                                <p class="description">
                                    <?php _e('When enabled, content will be analyzed using OpenAI. When disabled, the plugin uses intelligent keyword matching (less accurate but free).', 'asae-taxonomy-organizer'); ?>
                                </p>
                                <div id="ai-status" class="asae-to-status-indicator">
                                    <?php if ($use_ai === 'yes' && !empty($api_key)): ?>
                                        <span class="status-active"><?php _e('AI Analysis Active', 'asae-taxonomy-organizer'); ?></span>
                                    <?php elseif ($use_ai === 'yes' && empty($api_key)): ?>
                                        <span class="status-warning"><?php _e('AI Enabled but No API Key', 'asae-taxonomy-organizer'); ?></span>
                                    <?php else: ?>
                                        <span class="status-inactive"><?php _e('Using Keyword Matching', 'asae-taxonomy-organizer'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="asae-to-card">
                    <h2><?php _e('Cost Controls', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Manage API spending with monthly limits and rate controls.', 'asae-taxonomy-organizer'); ?>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="monthly_api_limit"><?php _e('Monthly API Call Limit', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="monthly_api_limit" id="monthly_api_limit"
                                       class="small-text" min="0" step="1"
                                       value="<?php echo esc_attr($monthly_limit); ?>">
                                <p class="description">
                                    <?php _e('Maximum API calls per month. Set to 0 for unlimited. When exceeded, batch processing pauses until the limit resets.', 'asae-taxonomy-organizer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_delay"><?php _e('Delay Between API Calls', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="api_delay" id="api_delay"
                                       class="small-text" min="0" max="5000" step="50"
                                       value="<?php echo esc_attr($api_delay); ?>">
                                <span><?php _e('milliseconds', 'asae-taxonomy-organizer'); ?></span>
                                <p class="description">
                                    <?php _e('Pause between each API call to avoid rate limits. Recommended: 200ms.', 'asae-taxonomy-organizer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="retry_delay"><?php _e('Retry Delay When Blocked', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="retry_delay" id="retry_delay"
                                       class="small-text" min="1" max="1440" step="1"
                                       value="<?php echo esc_attr($retry_delay); ?>">
                                <span><?php _e('minutes', 'asae-taxonomy-organizer'); ?></span>
                                <p class="description">
                                    <?php _e('When the API refuses requests (rate limit, budget, or error), batch processing pauses for this duration before retrying. Default: 60 minutes.', 'asae-taxonomy-organizer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php _e('Usage This Month', 'asae-taxonomy-organizer'); ?>
                            </th>
                            <td>
                                <div class="asae-to-usage-display">
                                    <strong id="usage-count"><?php echo intval($usage['used']); ?></strong>
                                    <?php if ($usage['limit'] > 0): ?>
                                        / <strong><?php echo intval($usage['limit']); ?></strong>
                                    <?php endif; ?>
                                    <span><?php _e('API calls', 'asae-taxonomy-organizer'); ?></span>
                                    <?php if ($usage['limit'] > 0): ?>
                                        <?php
                                        $pct = round(($usage['used'] / $usage['limit']) * 100);
                                        $bar_class = $pct > 90 ? 'usage-danger' : ($pct > 70 ? 'usage-warning' : 'usage-ok');
                                        ?>
                                        <div class="usage-bar" role="progressbar" aria-valuenow="<?php echo intval($usage['used']); ?>" aria-valuemin="0" aria-valuemax="<?php echo intval($usage['limit']); ?>" aria-label="<?php esc_attr_e('Monthly API usage', 'asae-taxonomy-organizer'); ?>">
                                            <div class="usage-bar-fill <?php echo $bar_class; ?>" style="width: <?php echo min(100, $pct); ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="reset-usage-btn" class="button button-small" style="margin-top: 8px;">
                                    <?php _e('Reset Counter', 'asae-taxonomy-organizer'); ?>
                                </button>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="asae-to-card">
                    <h2><?php _e('Reports', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Configure how report charts display tag data.', 'asae-taxonomy-organizer'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="report_ignored_tags"><?php _e('Ignored Tags', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <textarea name="report_ignored_tags" id="report_ignored_tags" rows="3" class="large-text"><?php echo esc_textarea(get_option('asae_to_report_ignored_tags', 'article, AssociationsNow, ASAEcenter, podcast, video')); ?></textarea>
                                <p class="description">
                                    <?php _e('Comma-separated list of tag names to exclude from report charts. These are typically structural or format tags, not content topics.', 'asae-taxonomy-organizer'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="button" id="save-report-settings-btn" class="button button-primary">
                            <?php _e('Save Report Settings', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <span id="report-settings-result" style="margin-left: 10px;"></span>
                    </p>
                </div>

                <div class="asae-to-card" id="ga4-settings">
                    <h2><?php _e('Google Analytics 4 Configuration', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Configure GA4 access for the Pageviews by Category report. Requires a Google service account with Viewer access to the GA4 property.', 'asae-taxonomy-organizer'); ?>
                    </p>
                    <?php
                    $ga4_property = get_option('ato_ga4_property_id', '');
                    $ga4_has_creds = !empty(get_option('ato_ga4_service_account_json', ''));
                    $ga4_client_email = get_option('ato_ga4_client_email', '');

                    // Auto-detect from Site Kit if not yet configured
                    $sitekit_detected = '';
                    if (empty($ga4_property)) {
                        $sitekit_options = get_option('googlesitekit_analytics-4', array());
                        if (!empty($sitekit_options['propertyID'])) {
                            $sitekit_detected = $sitekit_options['propertyID'];
                        }
                    }
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_property_id"><?php _e('GA4 Property ID', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="ga4_property_id" class="regular-text"
                                       value="<?php echo esc_attr($ga4_property ?: $sitekit_detected); ?>"
                                       placeholder="properties/123456789">
                                <p class="description">
                                    <?php _e('The GA4 property ID (e.g., properties/123456789).', 'asae-taxonomy-organizer'); ?>
                                    <?php if ($sitekit_detected && empty($ga4_property)): ?>
                                        <br><em><?php _e('Auto-detected from Google Site Kit.', 'asae-taxonomy-organizer'); ?></em>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_service_account_json"><?php _e('Service Account JSON Key', 'asae-taxonomy-organizer'); ?></label>
                            </th>
                            <td>
                                <?php if ($ga4_has_creds && $ga4_client_email): ?>
                                    <div id="ga4-creds-status">
                                        <span class="status-active"><?php echo esc_html(sprintf(__('Service account configured: %s', 'asae-taxonomy-organizer'), $ga4_client_email)); ?></span>
                                        <button type="button" id="ga4-replace-creds-btn" class="button button-small" style="margin-left: 8px;">
                                            <?php _e('Replace', 'asae-taxonomy-organizer'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <div id="ga4-creds-input" <?php echo ($ga4_has_creds && $ga4_client_email) ? 'style="display:none;"' : ''; ?>>
                                    <textarea id="ga4_service_account_json" rows="8" class="large-text" style="font-family: monospace; font-size: 12px;"
                                              placeholder="<?php esc_attr_e('Paste the full contents of your Google service account JSON key file here...', 'asae-taxonomy-organizer'); ?>"></textarea>
                                </div>
                                <p class="description">
                                    <?php _e('The service account must have Viewer access to the GA4 property above.', 'asae-taxonomy-organizer'); ?>
                                </p>
                                <div id="ga4-creds-error" style="display: none;" class="error-message"></div>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="button" id="save-ga4-settings-btn" class="button button-primary">
                            <?php _e('Save GA4 Settings', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <button type="button" id="test-ga4-btn" class="button">
                            <?php _e('Test Connection', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <span id="ga4-settings-result" style="margin-left: 10px;"></span>
                    </p>
                </div>

                <div class="asae-to-card">
                    <h2><?php _e('Maintenance Tools', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('Remove tags from posts where the tag name matches one of the post\'s assigned category names. This eliminates redundant tagging (e.g., a post in the "Membership" category also tagged "Membership").', 'asae-taxonomy-organizer'); ?>
                    </p>
                    <p>
                        <button type="button" id="cleanup-redundant-tags-btn" class="button">
                            <?php _e('Remove Redundant Tags', 'asae-taxonomy-organizer'); ?>
                        </button>
                    </p>
                    <div id="cleanup-results" style="display: none;">
                        <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e('Cleanup progress', 'asae-taxonomy-organizer'); ?>">
                            <div class="progress-fill" id="cleanup-progress-fill" style="width: 0%"></div>
                        </div>
                        <span class="progress-text" id="cleanup-progress-text" role="status" aria-live="polite"></span>
                    </div>
                    <div id="cleanup-summary" style="display: none;"></div>
                </div>

                <div class="asae-to-card">
                    <h2><?php _e('Plugin Updates', 'asae-taxonomy-organizer'); ?></h2>
                    <p class="description">
                        <?php _e('This plugin checks GitHub for new releases automatically. Use the button below to check immediately.', 'asae-taxonomy-organizer'); ?>
                    </p>
                    <p>
                        <button type="button" id="check-updates-btn" class="button">
                            <?php _e('Check for Updates Now', 'asae-taxonomy-organizer'); ?>
                        </button>
                        <span id="update-check-result" style="margin-left: 10px;"></span>
                    </p>
                </div>
            </div>

            <div class="asae-to-sidebar">
                <div class="asae-to-card">
                    <h3><?php _e('About OpenAI Integration', 'asae-taxonomy-organizer'); ?></h3>
                    <p><?php _e('Using OpenAI for content analysis provides significantly more accurate categorization compared to keyword matching.', 'asae-taxonomy-organizer'); ?></p>
                    <h4><?php _e('Benefits:', 'asae-taxonomy-organizer'); ?></h4>
                    <ul>
                        <li><?php _e('Understands content context', 'asae-taxonomy-organizer'); ?></li>
                        <li><?php _e('Higher accuracy categorization', 'asae-taxonomy-organizer'); ?></li>
                        <li><?php _e('Better confidence scoring', 'asae-taxonomy-organizer'); ?></li>
                        <li><?php _e('Handles ambiguous content', 'asae-taxonomy-organizer'); ?></li>
                    </ul>
                </div>

                <div class="asae-to-card">
                    <h3><?php _e('Security Note', 'asae-taxonomy-organizer'); ?></h3>
                    <p><?php _e('Your API key is stored in the WordPress database (wp_options table). It is never exposed in page source and is only transmitted to OpenAI.', 'asae-taxonomy-organizer'); ?></p>
                    <p><small><?php _e('For production environments with higher security requirements, consider using environment variables instead.', 'asae-taxonomy-organizer'); ?></small></p>
                </div>

                <div class="asae-to-card">
                    <h3><?php _e('Model Recommendations', 'asae-taxonomy-organizer'); ?></h3>
                    <div class="model-recommendations">
                        <div class="model-rec">
                            <strong>GPT-4.1 Nano</strong>
                            <span><?php _e('Cheapest option, great for simple classification', 'asae-taxonomy-organizer'); ?></span>
                        </div>
                        <div class="model-rec">
                            <strong>GPT-4.1 Mini</strong>
                            <span><?php _e('Best balance of cost and accuracy (recommended)', 'asae-taxonomy-organizer'); ?></span>
                        </div>
                        <div class="model-rec">
                            <strong>GPT-5.4</strong>
                            <span><?php _e('Most capable, highest accuracy for complex content', 'asae-taxonomy-organizer'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get all public post types (excluding attachments)
     */
    private function get_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);
        return $post_types;
    }

    /**
     * Get all public taxonomies
     */
    private function get_all_taxonomies() {
        return get_taxonomies(array('public' => true), 'objects');
    }
}
