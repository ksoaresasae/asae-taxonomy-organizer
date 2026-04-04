<?php
/**
 * ASAE Taxonomy Organizer - GA4 Category Views Report
 *
 * Fetches pageview data from the GA4 Data API using direct HTTP calls
 * (no Composer dependencies). Resolves paths to posts, aggregates views
 * by category, and caches the result. Provides a REST endpoint for the
 * front-end chart.
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_GA4_Reports {

    private static $windows = array(
        '3mo'  => array('days' => 90,   'ttl' => DAY_IN_SECONDS,     'key' => 'ato_ga4_views_90d'),
        '12mo' => array('days' => 365,  'ttl' => 7 * DAY_IN_SECONDS, 'key' => 'ato_ga4_views_1y'),
        'all'  => array('days' => 1825, 'ttl' => 7 * DAY_IN_SECONDS, 'key' => 'ato_ga4_views_5y'),
    );

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));
        add_action('ato_ga4_daily_refresh', array(__CLASS__, 'refresh_3mo_cache'));
        if (!wp_next_scheduled('ato_ga4_daily_refresh')) {
            wp_schedule_event(time(), 'daily', 'ato_ga4_daily_refresh');
        }
    }

    public static function register_rest_route() {
        register_rest_route('ato/v1', '/reports/category-views', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'rest_category_views'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array(
                'window' => array(
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => array('3mo', '12mo', 'all'),
                ),
            ),
        ));
    }

    public static function rest_category_views($request) {
        $window = $request->get_param('window');

        $property_id = get_option('ato_ga4_property_id', '');
        $creds_json = self::get_decrypted_credentials();

        if (empty($property_id)) {
            return new WP_Error('no_property_id', 'GA4 Property ID not configured.', array('status' => 400));
        }
        if (empty($creds_json)) {
            return new WP_Error('no_credentials', 'GA4 service account credentials not configured.', array('status' => 400));
        }

        $win = self::$windows[$window];
        $cached = get_transient($win['key']);
        if ($cached !== false) {
            return rest_ensure_response($cached);
        }

        $result = self::fetch_and_aggregate($window);
        if (is_wp_error($result)) {
            return $result;
        }

        set_transient($win['key'], $result, $win['ttl']);
        return rest_ensure_response($result);
    }

    public static function refresh_3mo_cache() {
        $property_id = get_option('ato_ga4_property_id', '');
        $creds_json = self::get_decrypted_credentials();
        if (empty($property_id) || empty($creds_json)) {
            return;
        }
        $result = self::fetch_and_aggregate('3mo');
        if (!is_wp_error($result)) {
            set_transient(self::$windows['3mo']['key'], $result, self::$windows['3mo']['ttl']);
        }
    }

    public static function test_connection() {
        $property_id = get_option('ato_ga4_property_id', '');
        $creds_json = self::get_decrypted_credentials();

        if (empty($property_id)) {
            return new WP_Error('no_property_id', 'GA4 Property ID not configured.');
        }
        if (empty($creds_json)) {
            return new WP_Error('no_credentials', 'Service account credentials not configured.');
        }

        $access_token = self::get_access_token($creds_json);
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $property = self::normalize_property_id($property_id);
        $body = array(
            'dateRanges' => array(array('startDate' => '1daysAgo', 'endDate' => 'today')),
            'metrics'    => array(array('name' => 'screenPageViews')),
            'limit'      => 1,
        );

        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/' . $property . ':runReport',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('ga4_api_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $resp_body = json_decode(wp_remote_retrieve_body($response), true);
            $msg = isset($resp_body['error']['message']) ? $resp_body['error']['message'] : 'HTTP ' . $code;
            return new WP_Error('ga4_api_error', $msg);
        }

        return true;
    }

    public static function fetch_and_aggregate($window) {
        $property_id = get_option('ato_ga4_property_id', '');
        $creds_json = self::get_decrypted_credentials();

        if (empty($property_id) || empty($creds_json)) {
            return new WP_Error('not_configured', 'GA4 credentials not configured.');
        }

        $access_token = self::get_access_token($creds_json);
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $win = self::$windows[$window];
        $property = self::normalize_property_id($property_id);

        // GA4 runReport call
        $body = array(
            'dateRanges' => array(array(
                'startDate' => $win['days'] . 'daysAgo',
                'endDate'   => 'today',
            )),
            'dimensions' => array(array('name' => 'pagePath')),
            'metrics'    => array(array('name' => 'screenPageViews')),
            'orderBys'   => array(array(
                'metric' => array('metricName' => 'screenPageViews'),
                'desc'   => true,
            )),
            'limit' => 10000,
        );

        $response = wp_remote_post(
            'https://analyticsdata.googleapis.com/v1beta/' . $property . ':runReport',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 30,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('ga4_api_error', 'GA4 API error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $resp_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($resp_body['error']['message']) ? $resp_body['error']['message'] : 'HTTP ' . $code;
            return new WP_Error('ga4_api_error', 'GA4 API error: ' . $msg);
        }

        // Parse: path => views
        $path_views = array();
        if (!empty($resp_body['rows'])) {
            foreach ($resp_body['rows'] as $row) {
                $path = $row['dimensionValues'][0]['value'];
                $views = intval($row['metricValues'][0]['value']);
                $path_views[$path] = ($path_views[$path] ?? 0) + $views;
            }
        }

        if (empty($path_views)) {
            return array(
                'categories'   => array(),
                'generated_at' => current_time('mysql'),
                'window'       => $window,
                'total_views'  => 0,
            );
        }

        // Path-to-post-ID resolution
        $slugs = array();
        $slug_to_path = array();
        foreach ($path_views as $path => $views) {
            $trimmed = rtrim($path, '/');
            $parts = explode('/', $trimmed);
            $slug = end($parts);
            if (!empty($slug) && !isset($slug_to_path[$slug])) {
                $slugs[] = $slug;
                $slug_to_path[$slug] = $path;
            }
        }

        if (empty($slugs)) {
            return array(
                'categories'   => array(),
                'generated_at' => current_time('mysql'),
                'window'       => $window,
                'total_views'  => 0,
            );
        }

        global $wpdb;

        $slug_to_post_id = array();
        foreach (array_chunk($slugs, 500) as $slug_batch) {
            $placeholders = implode(',', array_fill(0, count($slug_batch), '%s'));
            $query = $wpdb->prepare(
                "SELECT ID, post_name FROM {$wpdb->posts}
                 WHERE post_name IN ($placeholders)
                 AND post_status = 'publish'
                 AND post_type = 'post'",
                ...$slug_batch
            );
            $rows = $wpdb->get_results($query);
            foreach ($rows as $row) {
                $slug_to_post_id[$row->post_name] = intval($row->ID);
            }
        }

        // Build post_id => views
        $post_views = array();
        foreach ($slug_to_path as $slug => $path) {
            if (isset($slug_to_post_id[$slug])) {
                $pid = $slug_to_post_id[$slug];
                $post_views[$pid] = ($post_views[$pid] ?? 0) + $path_views[$path];
            }
        }

        if (empty($post_views)) {
            return array(
                'categories'   => array(),
                'generated_at' => current_time('mysql'),
                'window'       => $window,
                'total_views'  => 0,
            );
        }

        // Category aggregation
        $post_ids = array_keys($post_views);
        $category_views = array();
        $total_views = 0;

        foreach (array_chunk($post_ids, 500) as $id_batch) {
            $id_placeholders = implode(',', array_map('intval', $id_batch));
            $cat_rows = $wpdb->get_results(
                "SELECT t.name AS category_name, tr.object_id AS post_id
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = 'category'
                 AND tr.object_id IN ($id_placeholders)"
            );

            foreach ($cat_rows as $row) {
                $pid = intval($row->post_id);
                if (isset($post_views[$pid])) {
                    $cat_name = $row->category_name;
                    $category_views[$cat_name] = ($category_views[$cat_name] ?? 0) + $post_views[$pid];
                }
            }
        }

        foreach ($post_views as $v) {
            $total_views += $v;
        }

        arsort($category_views);

        $palette = array('#648FFF', '#785EF0', '#DC267F', '#FE6100', '#FFB000', '#009E73', '#56B4E9', '#E69F00');
        $categories = array();
        $i = 0;
        foreach ($category_views as $name => $views) {
            $categories[] = array(
                'name'  => $name,
                'views' => $views,
                'color' => $palette[$i % count($palette)],
            );
            $i++;
        }

        return array(
            'categories'   => $categories,
            'generated_at' => current_time('mysql'),
            'window'       => $window,
            'total_views'  => $total_views,
        );
    }

    public static function clear_caches() {
        foreach (self::$windows as $win) {
            delete_transient($win['key']);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('ato_ga4_daily_refresh');
    }

    // =========================================================================
    // Authentication (JWT-based, no Composer dependencies)
    // =========================================================================

    /**
     * Get an OAuth2 access token using the service account credentials.
     * Caches the token in a transient until it expires.
     *
     * @param string $creds_json Raw JSON string of service account key
     * @return string|WP_Error
     */
    private static function get_access_token($creds_json) {
        $cached_token = get_transient('ato_ga4_access_token');
        if ($cached_token) {
            return $cached_token;
        }

        $creds = json_decode($creds_json, true);
        if (!$creds || empty($creds['private_key']) || empty($creds['client_email']) || empty($creds['token_uri'])) {
            return new WP_Error('invalid_credentials', 'Service account JSON is missing required fields.');
        }

        // Build JWT
        $now = time();
        $header = self::base64url_encode(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claim = self::base64url_encode(wp_json_encode(array(
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud'   => $creds['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        )));

        $signing_input = $header . '.' . $claim;

        $private_key = openssl_pkey_get_private($creds['private_key']);
        if ($private_key === false) {
            return new WP_Error('invalid_key', 'Failed to parse service account private key.');
        }

        $signature = '';
        if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('signing_failed', 'Failed to sign JWT.');
        }

        $jwt = $signing_input . '.' . self::base64url_encode($signature);

        // Exchange JWT for access token
        $response = wp_remote_post($creds['token_uri'], array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('token_error', 'Token request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $err = isset($body['error_description']) ? $body['error_description'] : 'Unknown token error';
            return new WP_Error('token_error', $err);
        }

        // Cache token (expire 5 min before actual expiry)
        $expires_in = isset($body['expires_in']) ? intval($body['expires_in']) : 3600;
        set_transient('ato_ga4_access_token', $body['access_token'], max(60, $expires_in - 300));

        return $body['access_token'];
    }

    private static function normalize_property_id($property_id) {
        $property_id = trim($property_id);
        if (strpos($property_id, 'properties/') === 0) {
            return $property_id;
        }
        return 'properties/' . $property_id;
    }

    private static function get_decrypted_credentials() {
        $stored = get_option('ato_ga4_service_account_json', '');
        if (empty($stored)) {
            return '';
        }

        $key = defined('AUTH_KEY') ? AUTH_KEY : 'ato-ga4-fallback-key';
        $decoded = base64_decode($stored);
        if ($decoded === false) {
            return $stored;
        }

        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($decoded) <= $iv_length) {
            return $stored;
        }

        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    public static function encrypt_credentials($json) {
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'ato-ga4-fallback-key';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $ciphertext = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
