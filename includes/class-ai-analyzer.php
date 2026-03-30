<?php
/**
 * ASAE Taxonomy Organizer - AI Content Analyzer
 *
 * Analyzes content and determines the best matching category using either
 * OpenAI's GPT models or intelligent keyword matching as a fallback.
 *
 * Includes cost controls: budget checking, usage tracking, rate limiting,
 * and 429 (rate-limit) error handling with backoff signaling.
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASAE_TO_AI_Analyzer {

    /**
     * Estimated cost per API call by model (USD).
     * Based on ~700 input tokens + ~50 output tokens per request.
     *
     * @var array
     */
    private static $estimated_cost_per_call = array(
        'gpt-4.1-nano'  => 0.00009,
        'gpt-4o-mini'   => 0.00014,
        'gpt-5.4-nano'  => 0.0002,
        'gpt-4.1-mini'  => 0.00036,
        'gpt-5.4-mini'  => 0.00075,
        'gpt-4.1'       => 0.0018,
        'gpt-4o'        => 0.0023,
        'gpt-5.4'       => 0.0025,
    );

    /**
     * Status of the last analyze_content() call.
     * Callers should check this after a null return to decide whether to retry.
     *
     * Values: 'ok', 'rate_limited', 'budget_exceeded', 'error'
     *
     * @var string
     */
    public $last_status = 'ok';

    /**
     * Analyze content and return the best matching category with confidence.
     *
     * @param WP_Post $post  The post to analyze
     * @param array   $terms Array of WP_Term objects
     * @return array|null Analysis result or null
     */
    public function analyze_content($post, $terms) {
        $content = $this->prepare_content($post);
        $term_data = $this->prepare_terms($terms);

        $result = $this->get_best_category_with_confidence($content, $term_data);

        if ($result && $result['term_id']) {
            foreach ($terms as $term) {
                if ($term->term_id === $result['term_id']) {
                    return array(
                        'term'             => $term,
                        'confidence'       => $result['confidence'],
                        'confidence_level' => $this->get_confidence_level($result['confidence']),
                        'tags'             => isset($result['tags']) ? $result['tags'] : array(),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Legacy method for backward compatibility.
     *
     * @param WP_Post $post
     * @param array   $terms
     * @return WP_Term|null
     */
    public function analyze_content_legacy($post, $terms) {
        $result = $this->analyze_content($post, $terms);
        return $result ? $result['term'] : null;
    }

    // =========================================================================
    // Cost / Budget helpers (static – usable without an instance)
    // =========================================================================

    /**
     * Return the estimated cost per API call for a model.
     *
     * @param string|null $model Model id. Defaults to the saved setting.
     * @return float
     */
    public static function get_estimated_cost_per_call($model = null) {
        if ($model === null) {
            $model = get_option('asae_to_openai_model', 'gpt-4.1-mini');
        }
        return isset(self::$estimated_cost_per_call[$model])
            ? self::$estimated_cost_per_call[$model]
            : 0.002; // conservative fallback
    }

    /**
     * Estimate total cost for a given number of items.
     *
     * @param int         $item_count
     * @param string|null $model
     * @return array { cost: float, model: string, per_call: float }
     */
    public static function estimate_cost($item_count, $model = null) {
        if ($model === null) {
            $model = get_option('asae_to_openai_model', 'gpt-4.1-mini');
        }
        $per_call = self::get_estimated_cost_per_call($model);
        return array(
            'cost'     => round($per_call * $item_count, 4),
            'model'    => $model,
            'per_call' => $per_call,
        );
    }

    /**
     * Check whether the monthly API-call budget still allows another call.
     *
     * @return bool true if a call is allowed
     */
    public static function check_budget() {
        $limit = intval(get_option('asae_to_monthly_api_call_limit', 0));
        if ($limit <= 0) {
            return true; // 0 = unlimited
        }

        self::maybe_reset_month();
        $used = intval(get_option('asae_to_api_calls_count', 0));
        return $used < $limit;
    }

    /**
     * Increment the monthly API-call counter by one.
     */
    public static function track_api_call() {
        self::maybe_reset_month();
        $count = intval(get_option('asae_to_api_calls_count', 0));
        update_option('asae_to_api_calls_count', $count + 1, false);
    }

    /**
     * Return current monthly usage stats.
     *
     * @return array { used: int, limit: int, month: string }
     */
    public static function get_monthly_usage() {
        self::maybe_reset_month();
        return array(
            'used'  => intval(get_option('asae_to_api_calls_count', 0)),
            'limit' => intval(get_option('asae_to_monthly_api_call_limit', 0)),
            'month' => get_option('asae_to_api_calls_reset_month', ''),
        );
    }

    /**
     * Manually reset the monthly counter to zero.
     */
    public static function reset_monthly_usage() {
        update_option('asae_to_api_calls_count', 0, false);
        update_option('asae_to_api_calls_reset_month', gmdate('Y-m'), false);
    }

    /**
     * Auto-reset the counter when a new calendar month starts.
     */
    private static function maybe_reset_month() {
        $current_month = gmdate('Y-m');
        $stored_month  = get_option('asae_to_api_calls_reset_month', '');
        if ($stored_month !== $current_month) {
            update_option('asae_to_api_calls_count', 0, false);
            update_option('asae_to_api_calls_reset_month', $current_month, false);
        }
    }

    // =========================================================================
    // Private analysis methods
    // =========================================================================

    /**
     * @param int $confidence
     * @return string
     */
    private function get_confidence_level($confidence) {
        if ($confidence >= 75) {
            return 'high';
        } elseif ($confidence >= 50) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * @param WP_Post $post
     * @return array
     */
    private function prepare_content($post) {
        $content = array(
            'title'   => $post->post_title,
            'content' => wp_strip_all_tags($post->post_content),
            'excerpt' => $post->post_excerpt,
        );
        $content['content'] = substr($content['content'], 0, 5000);
        return $content;
    }

    /**
     * @param array $terms
     * @return array
     */
    private function prepare_terms($terms) {
        $term_data = array();
        foreach ($terms as $term) {
            $term_data[] = array(
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
            );
        }
        return $term_data;
    }

    /**
     * Decide between AI and keyword matching.
     *
     * When AI is enabled, keyword matching is NEVER used as a fallback.
     * Any API refusal (budget, rate limit, error) returns null so the
     * caller can pause and retry later.
     *
     * @param array $content
     * @param array $terms
     * @return array|null
     */
    private function get_best_category_with_confidence($content, $terms) {
        $this->last_status = 'ok';

        $use_ai  = get_option('asae_to_use_ai', 'no');
        $api_key = get_option('asae_to_openai_api_key', '');

        // Keyword matching only when user explicitly chose it
        if ($use_ai !== 'yes' || empty($api_key)) {
            return $this->keyword_matching_with_confidence($content, $terms);
        }

        // Budget gate — do not fall back, signal caller to pause
        if (!self::check_budget()) {
            $this->last_status = 'budget_exceeded';
            error_log('ASAE Taxonomy Organizer: Monthly API call budget exceeded — pausing for retry');
            return null;
        }

        $model  = get_option('asae_to_openai_model', 'gpt-4.1-mini');
        $result = $this->call_openai_api_with_confidence($content, $terms, $api_key, $model);

        if ($result) {
            return $result;
        }

        // last_status is already set by call_openai_api_with_confidence
        // ('rate_limited' or 'error') — caller should pause and retry
        return null;
    }

    /**
     * Call OpenAI Chat Completions API.
     *
     * Includes: configurable delay between calls, 429 handling, usage tracking.
     *
     * @param array  $content
     * @param array  $terms
     * @param string $api_key
     * @param string $model
     * @return array|null
     */
    private function call_openai_api_with_confidence($content, $terms, $api_key, $model = 'gpt-4.1-mini') {
        // Rate-limiting delay (configurable, default 200 ms)
        $delay_ms = intval(get_option('asae_to_api_call_delay_ms', 200));
        if ($delay_ms > 0) {
            usleep($delay_ms * 1000);
        }

        // Build prompt
        $term_list = array_map(function ($term) {
            $desc = !empty($term['description']) ? ' - ' . substr($term['description'], 0, 100) : '';
            return $term['name'] . ' (ID: ' . $term['term_id'] . ')' . $desc;
        }, $terms);

        $prompt  = "Analyze the following content and:\n";
        $prompt .= "1. Select the single most appropriate category from the list provided.\n";
        $prompt .= "2. Suggest up to 3 short keyword tags (1-3 words each) that describe the content's key topics. Tags should be general enough to apply across multiple articles.\n\n";
        $prompt .= "Content Title: " . $content['title'] . "\n\n";
        $prompt .= "Content Body:\n" . substr($content['content'], 0, 3000) . "\n\n";
        $prompt .= "Available Categories:\n" . implode("\n", $term_list) . "\n\n";
        $prompt .= "Respond ONLY with valid JSON in this exact format: {\"term_id\": <number>, \"confidence\": <0-100>, \"tags\": [\"tag1\", \"tag2\", \"tag3\"]}\n";
        $prompt .= "The confidence should reflect how well the content matches the category:\n";
        $prompt .= "- 90-100: Perfect, unambiguous match\n";
        $prompt .= "- 70-89: Strong match with clear relevance\n";
        $prompt .= "- 50-69: Moderate match, reasonable choice\n";
        $prompt .= "- Below 50: Weak match, unsure\n";
        $prompt .= "Always include the tags array, even if empty. Do not include any explanation, just the JSON.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model'       => $model,
                'messages'    => array(
                    array('role' => 'system', 'content' => 'You are a content categorization and tagging assistant. Analyze content, select the most appropriate category, and suggest up to 3 keyword tags. Always respond in valid JSON format with term_id, confidence, and tags fields. No explanations.'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'max_tokens'  => 100,
                'temperature' => 0.3,
            )),
            'timeout' => 30,
        ));

        // Track the call regardless of outcome (cost is incurred on send)
        self::track_api_call();

        if (is_wp_error($response)) {
            $this->last_status = 'error';
            error_log('ASAE Taxonomy Organizer: OpenAI API error - ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);

        // Handle 429 – rate-limited by OpenAI
        if ($code === 429) {
            $this->last_status = 'rate_limited';
            error_log('ASAE Taxonomy Organizer: Rate limited by OpenAI API (429)');
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['choices'][0]['message']['content'])) {
            $ai_response = trim($body['choices'][0]['message']['content']);
            $ai_response = preg_replace('/^```json\s*/', '', $ai_response);
            $ai_response = preg_replace('/\s*```$/', '', $ai_response);
            $ai_response = preg_replace('/^```\s*/', '', $ai_response);

            $parsed = json_decode($ai_response, true);

            if ($parsed && isset($parsed['term_id']) && isset($parsed['confidence'])) {
                $term_id    = intval($parsed['term_id']);
                $confidence = min(100, max(0, intval($parsed['confidence'])));
                $tags       = isset($parsed['tags']) && is_array($parsed['tags']) ? array_slice($parsed['tags'], 0, 3) : array();

                foreach ($terms as $term) {
                    if ($term['term_id'] === $term_id) {
                        return array(
                            'term_id'    => $term_id,
                            'confidence' => $confidence,
                            'tags'       => $tags,
                        );
                    }
                }

                error_log('ASAE Taxonomy Organizer: AI returned invalid term_id: ' . $term_id);
            }
        }

        if (isset($body['error'])) {
            error_log('ASAE Taxonomy Organizer: OpenAI API error - ' . $body['error']['message']);
        }

        $this->last_status = 'error';
        return null;
    }

    /**
     * Keyword matching fallback.
     *
     * @param array $content
     * @param array $terms
     * @return array|null
     */
    private function keyword_matching_with_confidence($content, $terms) {
        $full_content = strtolower(
            $content['title'] . ' ' .
            $content['content'] . ' ' .
            $content['excerpt']
        );

        $content_word_count = str_word_count($full_content);

        $best_match   = null;
        $highest_score = 0;
        $scores       = array();

        foreach ($terms as $term) {
            $score = 0;

            $name_words = explode(' ', strtolower($term['name']));
            foreach ($name_words as $word) {
                if (strlen($word) > 2) {
                    $score += substr_count($full_content, $word) * 3;
                }
            }

            if (!empty($term['slug'])) {
                $slug_words = explode('-', strtolower($term['slug']));
                foreach ($slug_words as $word) {
                    if (strlen($word) > 2) {
                        $score += substr_count($full_content, $word) * 2;
                    }
                }
            }

            if (!empty($term['description'])) {
                $desc_words = explode(' ', strtolower($term['description']));
                foreach ($desc_words as $word) {
                    if (strlen($word) > 3) {
                        $score += substr_count($full_content, $word);
                    }
                }
            }

            $scores[$term['term_id']] = $score;

            if ($score > $highest_score) {
                $highest_score = $score;
                $best_match    = $term;
            }
        }

        if ($highest_score > 0 && $best_match) {
            $total_scores = array_sum($scores);
            $dominance    = ($total_scores > 0) ? ($highest_score / $total_scores) : 0;
            $density      = $highest_score / max(1, ($content_word_count / 100));
            $confidence   = round(($dominance * 100 + $density * 10) / 2);
            $confidence   = max(15, min(85, $confidence));

            return array(
                'term_id'    => $best_match['term_id'],
                'confidence' => $confidence,
                'tags'       => array(),
            );
        }

        if (!empty($terms)) {
            return array(
                'term_id'    => $terms[0]['term_id'],
                'confidence' => 10,
                'tags'       => array(),
            );
        }

        return null;
    }
}
