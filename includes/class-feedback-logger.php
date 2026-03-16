<?php
/**
 * ASAE Taxonomy Organizer - Feedback Logger
 * 
 * This class handles logging rejection feedback when users override AI suggestions.
 * The idea here is to capture why categorizations were rejected so we can potentially
 * use this data to improve prompts or train better models in the future.
 * 
 * The feedback is stored in a custom database table and can be reviewed later
 * to identify patterns in incorrect categorizations.
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_Feedback_Logger {
    
    /**
     * Log a rejection with the new category and notes
     * 
     * This is called when a user rejects an AI suggestion and optionally provides
     * a corrected category and/or notes explaining why the suggestion was wrong.
     * This data is invaluable for understanding where the AI falls short.
     * 
     * @param int    $post_id            The post that was being categorized
     * @param int    $suggested_term_id  The term the AI originally suggested
     * @param int    $selected_term_id   The term the user selected instead (or null)
     * @param string $notes              User's explanation for the rejection
     * @param string $taxonomy           The taxonomy being used
     * @return bool Whether the log was successfully saved
     */
    public function log_rejection($post_id, $suggested_term_id, $selected_term_id, $notes, $taxonomy) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_feedback';
        
        // Sanitize all inputs - never trust user data
        $post_id = intval($post_id);
        $suggested_term_id = intval($suggested_term_id);
        $selected_term_id = $selected_term_id ? intval($selected_term_id) : null;
        $notes = sanitize_textarea_field($notes);
        $taxonomy = sanitize_key($taxonomy);
        
        // Handle nullable selected_term_id - wpdb doesn't handle NULL well with %d
        // So we use a manual query for proper NULL handling
        if ($selected_term_id === null || $selected_term_id === 0) {
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name (post_id, suggested_term_id, selected_term_id, notes, taxonomy, user_id, created_at) 
                 VALUES (%d, %d, NULL, %s, %s, %d, %s)",
                $post_id,
                $suggested_term_id,
                $notes,
                $taxonomy,
                get_current_user_id(),
                current_time('mysql')
            ));
        } else {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'post_id' => $post_id,
                    'suggested_term_id' => $suggested_term_id,
                    'selected_term_id' => $selected_term_id,
                    'notes' => $notes,
                    'taxonomy' => $taxonomy,
                    'user_id' => get_current_user_id(),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%s', '%s', '%d', '%s')
            );
        }
        
        return $result !== false;
    }
    
    /**
     * Get feedback entries for analysis
     * 
     * Retrieves stored feedback entries, optionally filtered by taxonomy.
     * This is useful for reviewing patterns in rejections and improving
     * the categorization system over time.
     * 
     * @param string $taxonomy Optional taxonomy filter
     * @param int    $limit    Maximum number of entries to retrieve
     * @return array Array of feedback objects
     */
    public function get_feedback($taxonomy = '', $limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'asae_to_feedback';
        
        $limit = min(1000, max(1, intval($limit)));
        
        if (!empty($taxonomy)) {
            $taxonomy = sanitize_key($taxonomy);
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE taxonomy = %s ORDER BY created_at DESC LIMIT %d",
                $taxonomy,
                $limit
            ));
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
                $limit
            ));
        }
        
        return $results;
    }
}
