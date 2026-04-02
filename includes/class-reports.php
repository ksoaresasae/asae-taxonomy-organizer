<?php
/**
 * ASAE Taxonomy Organizer - Reports
 *
 * Provides category/tag breakdown data for charts and renders report UI.
 * All queries are cached via WordPress transients (1 hour TTL) and
 * invalidated when posts or terms change.
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Reports {

    /**
     * Colorblind-safe palette (Wong 2011).
     */
    private static $palette = array(
        '#648FFF', '#785EF0', '#DC267F', '#FE6100',
        '#FFB000', '#009E73', '#56B4E9', '#E69F00',
    );

    /**
     * Render the Reports tab HTML container.
     */
    public static function render() {
        $post_types = get_post_types(array('public' => true), 'objects');
        unset($post_types['attachment']);
        ?>
        <div class="asae-to-container">
            <div class="asae-to-main">
                <div class="asae-to-card">
                    <div class="asae-to-report-header">
                        <h2 id="asae-to-report-title"><?php _e('Content by Category', 'asae-taxonomy-organizer'); ?></h2>
                        <div class="asae-to-report-controls">
                            <select id="asae-to-report-post-type" class="regular-text" aria-label="<?php esc_attr_e('Select post type', 'asae-taxonomy-organizer'); ?>">
                                <?php foreach ($post_types as $pt): ?>
                                    <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($pt->name, 'post'); ?>>
                                        <?php echo esc_html($pt->label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="asae-to-report-back" class="button" style="display: none;">
                                <?php _e('Back to Categories', 'asae-taxonomy-organizer'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="asae-to-report-body" aria-live="polite">
                        <div class="asae-to-chart-wrap">
                            <div class="asae-to-chart-spinner" id="asae-to-chart-spinner">
                                <span class="spinner is-active"></span>
                            </div>
                            <canvas id="asae-to-report-chart"
                                    role="img"
                                    aria-label="<?php esc_attr_e('Category distribution chart', 'asae-taxonomy-organizer'); ?>"
                                    style="display: none;"></canvas>
                        </div>
                        <div class="asae-to-report-table-wrap" id="asae-to-report-table-wrap">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render compact dashboard widget HTML.
     */
    public static function render_dashboard_widget() {
        ?>
        <div class="asae-to-dashboard-report" aria-live="polite">
            <div class="asae-to-chart-spinner" id="asae-to-dash-spinner">
                <span class="spinner is-active"></span>
            </div>
            <canvas id="asae-to-dash-chart"
                    role="img"
                    aria-label="<?php esc_attr_e('Category distribution chart', 'asae-taxonomy-organizer'); ?>"
                    style="display: none; max-height: 260px;"></canvas>
            <div id="asae-to-dash-table-wrap" class="asae-to-report-table-wrap"></div>
            <div class="asae-to-dash-controls" style="display: none;" id="asae-to-dash-controls">
                <button type="button" id="asae-to-dash-back" class="button button-small">
                    <?php _e('Back to Categories', 'asae-taxonomy-organizer'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Get category breakdown for a post type.
     *
     * @param string $post_type
     * @return array
     */
    public static function get_category_data($post_type) {
        $cache_key = self::transient_key($post_type, 'cats');
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $taxonomy = self::get_primary_taxonomy($post_type);
        if (!$taxonomy) {
            return array('post_type' => $post_type, 'total_posts' => 0, 'categories' => array(), 'uncategorized_count' => 0);
        }

        // Get all terms with counts
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
        ));

        if (is_wp_error($terms)) {
            $terms = array();
        }

        $total_published = wp_count_posts($post_type);
        $total = isset($total_published->publish) ? intval($total_published->publish) : 0;

        $categories = array();
        $categorized_count = 0;
        $i = 0;

        foreach ($terms as $term) {
            // Count posts of this specific post type in this term
            $count = self::count_posts_in_term($post_type, $taxonomy, $term->term_id);
            if ($count === 0) {
                continue;
            }
            $categories[] = array(
                'term_id' => $term->term_id,
                'name'    => $term->name,
                'count'   => $count,
                'color'   => self::$palette[$i % count(self::$palette)],
            );
            $categorized_count += $count;
            $i++;
        }

        // Sort by count descending
        usort($categories, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        // Re-assign colors after sort
        foreach ($categories as $idx => &$cat) {
            $cat['color'] = self::$palette[$idx % count(self::$palette)];
        }
        unset($cat);

        $uncategorized = max(0, $total - $categorized_count);

        $result = array(
            'post_type'          => $post_type,
            'taxonomy'           => $taxonomy,
            'total_posts'        => $total,
            'categories'         => $categories,
            'uncategorized_count' => $uncategorized,
        );

        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        return $result;
    }

    /**
     * Get top 20 tags for posts in a given category.
     *
     * @param string $post_type
     * @param int    $category_term_id
     * @return array
     */
    public static function get_tag_data($post_type, $category_term_id) {
        $cache_key = self::transient_key($post_type, 'tags', $category_term_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;

        $taxonomy = self::get_primary_taxonomy($post_type);
        $category = get_term($category_term_id, $taxonomy);
        $category_name = ($category && !is_wp_error($category)) ? $category->name : '';

        // Count total posts in this category
        $total_in_cat = self::count_posts_in_term($post_type, $taxonomy, $category_term_id);

        // Single SQL query: get top 21 tags for posts in this category
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, COUNT(DISTINCT p.ID) as cnt
             FROM {$wpdb->term_relationships} tr1
             JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
                 AND tt1.taxonomy = %s AND tt1.term_id = %d
             JOIN {$wpdb->posts} p ON tr1.object_id = p.ID
                 AND p.post_type = %s AND p.post_status = 'publish'
             JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
             JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
                 AND tt2.taxonomy = 'post_tag'
             JOIN {$wpdb->terms} t ON tt2.term_id = t.term_id
             GROUP BY t.term_id, t.name
             ORDER BY cnt DESC
             LIMIT 21",
            $taxonomy,
            $category_term_id,
            $post_type
        ));

        $tags = array();
        $top20_count = 0;
        $i = 0;

        foreach ($results as $idx => $row) {
            if ($idx >= 20) {
                break;
            }
            $tags[] = array(
                'term_id' => intval($row->term_id),
                'name'    => $row->name,
                'count'   => intval($row->cnt),
                'color'   => self::$palette[$i % count(self::$palette)],
            );
            $top20_count += intval($row->cnt);
            $i++;
        }

        // If there's a 21st row, calculate "Other"
        $other_count = 0;
        if (count($results) > 20) {
            // Sum all tags beyond top 20
            for ($j = 20; $j < count($results); $j++) {
                $other_count += intval($results[$j]->cnt);
            }
        }

        $data = array(
            'category_name'    => $category_name,
            'category_term_id' => $category_term_id,
            'total_in_category' => $total_in_cat,
            'tags'             => $tags,
            'other_count'      => $other_count,
        );

        set_transient($cache_key, $data, HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * Invalidate report caches when content changes.
     *
     * @param int $post_id
     */
    public static function invalidate_caches($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }
        self::delete_transients_by_prefix('asae_to_report_' . $post->post_type);
    }

    /**
     * Invalidate all report caches when terms change.
     */
    public static function invalidate_all_caches() {
        self::delete_transients_by_prefix('asae_to_report_');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Get the primary hierarchical taxonomy for a post type.
     */
    private static function get_primary_taxonomy($post_type) {
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        // Prefer 'category' if available
        if (isset($taxonomies['category'])) {
            return 'category';
        }

        // Otherwise, first hierarchical taxonomy
        foreach ($taxonomies as $tax) {
            if ($tax->hierarchical) {
                return $tax->name;
            }
        }

        // Fallback: first taxonomy
        foreach ($taxonomies as $tax) {
            return $tax->name;
        }

        return null;
    }

    /**
     * Count published posts of a specific type in a specific term.
     */
    private static function count_posts_in_term($post_type, $taxonomy, $term_id) {
        $query = new WP_Query(array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => $taxonomy,
                    'terms'    => $term_id,
                ),
            ),
        ));
        return $query->found_posts;
    }

    /**
     * Build a transient key.
     */
    private static function transient_key($post_type, $context, $term_id = null) {
        $key = 'asae_to_report_' . $post_type . '_' . $context;
        if ($term_id !== null) {
            $key .= '_' . $term_id;
        }
        return $key;
    }

    /**
     * Delete all transients matching a prefix.
     */
    private static function delete_transients_by_prefix($prefix) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $wpdb->esc_like($prefix) . '%',
            '_transient_timeout_' . $wpdb->esc_like($prefix) . '%'
        ));
    }
}
