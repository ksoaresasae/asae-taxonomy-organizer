<?php
/**
 * ASAE Taxonomy Organizer - Settings Admin Page
 * 
 * This class handles the OpenAI settings administration page, providing a secure
 * interface for managing API credentials and model selection. The design philosophy
 * here is to make AI configuration accessible without exposing sensitive data
 * unnecessarily - hence the password field with show/hide toggle.
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Settings {
    
    /**
     * Available OpenAI models for content analysis
     * 
     * I've curated this list based on what's actually useful for categorization tasks.
     * The newer models tend to be more accurate but cost more. GPT-4o-mini is a solid
     * balance of accuracy and cost for most content categorization needs.
     * 
     * @var array
     */
    private $available_models = array(
        'gpt-4.1-nano'  => 'GPT-4.1 Nano (Cheapest)',
        'gpt-4o-mini'   => 'GPT-4o Mini',
        'gpt-5.4-nano'  => 'GPT-5.4 Nano',
        'gpt-4.1-mini'  => 'GPT-4.1 Mini (Recommended)',
        'gpt-5.4-mini'  => 'GPT-5.4 Mini',
        'gpt-4.1'       => 'GPT-4.1',
        'gpt-4o'        => 'GPT-4o',
        'gpt-5.4'       => 'GPT-5.4 (Most Capable)',
    );
    
    /**
     * Render the OpenAI Settings page
     * 
     * This page is intentionally kept simple - we don't want to overwhelm users with
     * options. The key elements are the API key (secured), model selection, and a
     * test button to verify everything works before they start processing content.
     */
    public function render() {
        // Fetch current settings from WordPress options table
        // Note: wp_options does NOT encrypt values by default. The API key is stored in plain text.
        // For production environments with higher security requirements, consider using
        // environment variables (getenv/wp-config.php constants) instead of database storage.
        $api_key = get_option('asae_to_openai_api_key', '');
        $selected_model = get_option('asae_to_openai_model', 'gpt-4.1-mini');
        $use_ai = get_option('asae_to_use_ai', 'no');
        $monthly_limit = get_option('asae_to_monthly_api_call_limit', 0);
        $api_delay = get_option('asae_to_api_call_delay_ms', 200);
        $retry_delay = get_option('asae_to_api_retry_delay_minutes', 60);
        $usage = ASAE_TO_AI_Analyzer::get_monthly_usage();
        ?>
        <div class="wrap asae-to-wrap">
            <h1><?php _e('OpenAI Settings', 'asae-taxonomy-organizer'); ?></h1>
            
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
                                            <?php foreach ($this->available_models as $model_id => $model_name): ?>
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
                                        <?php _e('Maximum API calls per month. Set to 0 for unlimited. When exceeded, the plugin falls back to keyword matching.', 'asae-taxonomy-organizer'); ?>
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
                                <strong>GPT-4o Mini</strong>
                                <span><?php _e('Best balance of cost and accuracy', 'asae-taxonomy-organizer'); ?></span>
                            </div>
                            <div class="model-rec">
                                <strong>GPT-4o</strong>
                                <span><?php _e('Highest accuracy for complex content', 'asae-taxonomy-organizer'); ?></span>
                            </div>
                            <div class="model-rec">
                                <strong>GPT-3.5 Turbo</strong>
                                <span><?php _e('Fastest and cheapest option', 'asae-taxonomy-organizer'); ?></span>
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
     * Get the list of available models
     * 
     * This is exposed as a public method so other parts of the plugin can
     * access the model list if needed (e.g., for validation).
     * 
     * @return array Associative array of model_id => display_name
     */
    public function get_available_models() {
        return $this->available_models;
    }
}
