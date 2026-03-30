<?php
/**
 * ASAE Taxonomy Organizer - GitHub Self-Hosted Updater
 *
 * Checks the plugin's GitHub repository for new releases and integrates
 * with WordPress's built-in update system. When a newer version is found
 * as a GitHub Release, the standard "Update available" notice appears in
 * the Plugins list and one-click update pulls the zip asset from GitHub.
 *
 * Requirements:
 * - GitHub Releases must be tagged (e.g. v0.4.0)
 * - Each release must have the plugin zip attached as a release asset
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_GitHub_Updater {

    /** @var string GitHub owner/repo */
    private $repo = 'ksoaresasae/asae-taxonomy-organizer';

    /** @var string Plugin basename (e.g. asae-taxonomy-organizer/asae-taxonomy-organizer.php) */
    private $basename;

    /** @var string Plugin slug (e.g. asae-taxonomy-organizer) */
    private $slug;

    /** @var string Current plugin version */
    private $version;

    /** @var object|null Cached GitHub release data */
    private $github_release = null;

    /**
     * Initialize the updater and hook into WordPress update system.
     */
    public function __construct() {
        $this->basename = ASAE_TO_PLUGIN_BASENAME;
        $this->slug     = dirname($this->basename);
        $this->version  = ASAE_TO_VERSION;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
    }

    /**
     * Check GitHub for a newer release and inject it into WordPress update transient.
     *
     * @param object $transient The update_plugins transient data.
     * @return object Modified transient.
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = ltrim($release->tag_name, 'vV');
        if (version_compare($remote_version, $this->version, '>')) {
            $download_url = $this->get_release_zip_url($release);
            if ($download_url) {
                $transient->response[$this->basename] = (object) array(
                    'slug'        => $this->slug,
                    'plugin'      => $this->basename,
                    'new_version' => $remote_version,
                    'url'         => 'https://github.com/' . $this->repo,
                    'package'     => $download_url,
                    'icons'       => array(),
                    'banners'     => array(),
                    'tested'      => '',
                    'requires'    => '5.0',
                    'requires_php'=> '7.4',
                );
            }
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View details" modal in the Plugins list.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (!$release) {
            return $result;
        }

        $remote_version = ltrim($release->tag_name, 'vV');
        $download_url   = $this->get_release_zip_url($release);

        return (object) array(
            'name'            => 'ASAE Taxonomy Organizer',
            'slug'            => $this->slug,
            'version'         => $remote_version,
            'author'          => '<a href="https://www.asaecenter.org">Keith M. Soares</a>',
            'homepage'        => 'https://github.com/' . $this->repo,
            'download_link'   => $download_url,
            'requires'        => '5.0',
            'requires_php'    => '7.4',
            'tested'          => '',
            'sections'        => array(
                'description' => 'Use AI to automatically analyze WordPress content and categorize it with appropriate taxonomy terms.',
                'changelog'   => nl2br(esc_html($release->body)),
            ),
            'last_updated'    => $release->published_at,
        );
    }

    /**
     * Ensure the installed folder name matches the plugin slug after update.
     *
     * WordPress extracts the zip into a folder named after the zip's root directory.
     * If GitHub names it differently (e.g. repo-name-tag), rename it.
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function post_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $result;
        }

        global $wp_filesystem;

        $install_dir = $result['destination'];
        $proper_dir  = WP_PLUGIN_DIR . '/' . $this->slug;

        // If the extracted folder doesn't match our slug, move it
        if ($install_dir !== $proper_dir) {
            $wp_filesystem->move($install_dir, $proper_dir);
            $result['destination']      = $proper_dir;
            $result['destination_name'] = $this->slug;
        }

        // Re-activate the plugin after update
        activate_plugin($this->basename);

        return $result;
    }

    /**
     * Fetch the latest release from GitHub API. Cached for 6 hours via transient.
     *
     * @return object|null Release data or null on failure.
     */
    private function get_latest_release() {
        if ($this->github_release !== null) {
            return $this->github_release;
        }

        $transient_key = 'asae_to_github_release';
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            $this->github_release = $cached;
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ASAE-Taxonomy-Organizer/' . $this->version,
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Cache the failure for 1 hour to avoid hammering the API
            set_transient($transient_key, null, HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!$body || !isset($body->tag_name)) {
            return null;
        }

        set_transient($transient_key, $body, 6 * HOUR_IN_SECONDS);
        $this->github_release = $body;

        return $body;
    }

    /**
     * Extract the zip download URL from a GitHub release.
     *
     * Looks for a .zip asset first (our build artifact), then falls back
     * to the GitHub-generated source zipball.
     *
     * @param object $release GitHub release data.
     * @return string|null Download URL.
     */
    private function get_release_zip_url($release) {
        // Prefer a .zip release asset (our build-zip.php output)
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback: GitHub's auto-generated source zip
        if (!empty($release->zipball_url)) {
            return $release->zipball_url;
        }

        return null;
    }
}
