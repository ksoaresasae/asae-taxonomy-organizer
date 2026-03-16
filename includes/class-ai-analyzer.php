<?php
/**
 * ASAE Taxonomy Organizer - AI Content Analyzer
 * 
 * This is the brain of the categorization system. It analyzes content and determines
 * the best matching category using either OpenAI's GPT models or intelligent keyword
 * matching as a fallback. The dual-method approach ensures the plugin works even
 * without API access, though AI is significantly more accurate.
 * 
 * The confidence scoring system helps users understand how certain the analysis is:
 * - High (75%+): Strong match, likely correct
 * - Medium (50-74%): Reasonable match, should review
 * - Low (<50%): Uncertain, definitely needs review
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
     * Analyze content and return the best matching category with confidence
     * 
     * This is the main entry point for content analysis. It prepares the content,
     * calls the appropriate analysis method (AI or keyword), and returns a
     * structured result with the matched term and confidence metrics.
     * 
     * @param WP_Post $post  The post to analyze
     * @param array   $terms Array of WP_Term objects representing available categories
     * @return array|null Analysis result with term, confidence, and level, or null if no match
     */
    public function analyze_content($post, $terms) {
        // Prepare content for analysis - extract and clean text
        $content = $this->prepare_content($post);
        $term_data = $this->prepare_terms($terms);
        
        // Get the best category match with confidence score
        $result = $this->get_best_category_with_confidence($content, $term_data);
        
        // If we got a result, find the full term object and return it
        if ($result && $result['term_id']) {
            foreach ($terms as $term) {
                if ($term->term_id === $result['term_id']) {
                    return array(
                        'term' => $term,
                        'confidence' => $result['confidence'],
                        'confidence_level' => $this->get_confidence_level($result['confidence'])
                    );
                }
            }
        }
        
        return null;
    }
    
    /**
     * Legacy method for backward compatibility
     * 
     * Some older code might call this method expecting just the term object.
     * This wrapper maintains that interface.
     * 
     * @param WP_Post $post  The post to analyze
     * @param array   $terms Available terms
     * @return WP_Term|null The matched term or null
     */
    public function analyze_content_legacy($post, $terms) {
        $result = $this->analyze_content($post, $terms);
        return $result ? $result['term'] : null;
    }
    
    /**
     * Convert numeric confidence to human-readable level
     * 
     * The thresholds here are based on practical testing. 75%+ is where we see
     * consistently accurate matches, 50-74% is usable but should be reviewed,
     * and below 50% is essentially a guess.
     * 
     * @param int $confidence Confidence percentage (0-100)
     * @return string 'high', 'medium', or 'low'
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
     * Prepare post content for analysis
     * 
     * Extracts the relevant text from a post and cleans it for analysis.
     * We limit content length to avoid excessive API costs and processing time.
     * 
     * @param WP_Post $post The post to prepare
     * @return array Cleaned content with title, body, and excerpt
     */
    private function prepare_content($post) {
        $content = array(
            'title' => $post->post_title,
            'content' => wp_strip_all_tags($post->post_content),
            'excerpt' => $post->post_excerpt,
        );
        
        // Limit content to 5000 chars to control API costs and processing time
        // This is usually enough to understand the topic
        $content['content'] = substr($content['content'], 0, 5000);
        
        return $content;
    }
    
    /**
     * Prepare terms for analysis
     * 
     * Converts WP_Term objects into simple arrays with just the data we need.
     * This makes it easier to work with and reduces memory usage.
     * 
     * @param array $terms Array of WP_Term objects
     * @return array Array of simplified term data
     */
    private function prepare_terms($terms) {
        $term_data = array();
        
        foreach ($terms as $term) {
            $term_data[] = array(
                'term_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
            );
        }
        
        return $term_data;
    }
    
    /**
     * Get the best category match with confidence score
     * 
     * This is the decision point between AI and keyword matching. We check if
     * AI is enabled and configured, try the AI approach first, and fall back
     * to keywords if AI fails or isn't available.
     * 
     * @param array $content Prepared content array
     * @param array $terms   Prepared terms array
     * @return array|null Result with term_id and confidence, or null
     */
    private function get_best_category_with_confidence($content, $terms) {
        // Check if AI analysis is enabled and configured
        $use_ai = get_option('asae_to_use_ai', 'no');
        $api_key = get_option('asae_to_openai_api_key', '');
        
        // Try AI analysis first if enabled and configured
        if ($use_ai === 'yes' && !empty($api_key)) {
            $model = get_option('asae_to_openai_model', 'gpt-4o-mini');
            $result = $this->call_openai_api_with_confidence($content, $terms, $api_key, $model);
            if ($result) {
                return $result;
            }
            // If AI fails, log it but continue to keyword fallback
            error_log('ASAE Taxonomy Organizer: AI analysis failed, falling back to keyword matching');
        }
        
        // Fall back to keyword matching
        return $this->keyword_matching_with_confidence($content, $terms);
    }
    
    /**
     * Analyze content using OpenAI API
     * 
     * Sends the content to OpenAI's chat completion API with a carefully crafted
     * prompt that asks for categorization with a confidence score. The prompt
     * is designed to get consistent, parseable JSON responses.
     * 
     * @param array  $content  Prepared content
     * @param array  $terms    Available terms
     * @param string $api_key  OpenAI API key
     * @param string $model    Model to use (e.g., 'gpt-4o-mini')
     * @return array|null Result with term_id and confidence, or null on failure
     */
    private function call_openai_api_with_confidence($content, $terms, $api_key, $model = 'gpt-4o-mini') {
        // Build the list of available categories for the prompt
        $term_list = array_map(function($term) {
            $desc = !empty($term['description']) ? " - " . substr($term['description'], 0, 100) : '';
            return $term['name'] . ' (ID: ' . $term['term_id'] . ')' . $desc;
        }, $terms);
        
        // Construct a clear, structured prompt
        // I've tuned this prompt through trial and error to get consistent results
        $prompt = "Analyze the following content and select the single most appropriate category from the list provided.\n\n";
        $prompt .= "Content Title: " . $content['title'] . "\n\n";
        $prompt .= "Content Body:\n" . substr($content['content'], 0, 3000) . "\n\n";
        $prompt .= "Available Categories:\n" . implode("\n", $term_list) . "\n\n";
        $prompt .= "Respond ONLY with valid JSON in this exact format: {\"term_id\": <number>, \"confidence\": <0-100>}\n";
        $prompt .= "The confidence should reflect how well the content matches the category:\n";
        $prompt .= "- 90-100: Perfect, unambiguous match\n";
        $prompt .= "- 70-89: Strong match with clear relevance\n";
        $prompt .= "- 50-69: Moderate match, reasonable choice\n";
        $prompt .= "- Below 50: Weak match, unsure\n";
        $prompt .= "Do not include any explanation, just the JSON.";
        
        // Make the API request
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are a content categorization assistant. Analyze content and select the most appropriate category. Always respond in valid JSON format with term_id and confidence fields only. No explanations.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 50,
                'temperature' => 0.3, // Lower temperature for more consistent results
            )),
            'timeout' => 30,
        ));
        
        // Handle connection errors
        if (is_wp_error($response)) {
            error_log('ASAE Taxonomy Organizer: OpenAI API error - ' . $response->get_error_message());
            return null;
        }
        
        // Parse the response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            $ai_response = trim($body['choices'][0]['message']['content']);
            
            // Clean up any markdown formatting the API might have added
            $ai_response = preg_replace('/^```json\s*/', '', $ai_response);
            $ai_response = preg_replace('/\s*```$/', '', $ai_response);
            $ai_response = preg_replace('/^```\s*/', '', $ai_response);
            
            // Parse the JSON response
            $parsed = json_decode($ai_response, true);
            
            if ($parsed && isset($parsed['term_id']) && isset($parsed['confidence'])) {
                $term_id = intval($parsed['term_id']);
                $confidence = min(100, max(0, intval($parsed['confidence'])));
                
                // Validate the term_id exists in our list
                foreach ($terms as $term) {
                    if ($term['term_id'] === $term_id) {
                        return array(
                            'term_id' => $term_id,
                            'confidence' => $confidence
                        );
                    }
                }
                
                // If the AI returned an invalid term_id, log it
                error_log('ASAE Taxonomy Organizer: AI returned invalid term_id: ' . $term_id);
            }
        }
        
        // Log unexpected response for debugging
        if (isset($body['error'])) {
            error_log('ASAE Taxonomy Organizer: OpenAI API error - ' . $body['error']['message']);
        }
        
        return null;
    }
    
    /**
     * Analyze content using keyword matching
     * 
     * This is the fallback method when AI isn't available. It's simpler but less
     * accurate. The algorithm counts keyword matches from term names, slugs, and
     * descriptions, then calculates a relative confidence score.
     * 
     * The confidence calculation considers:
     * 1. How dominant the top match is compared to others
     * 2. The density of keyword matches relative to content length
     * 
     * @param array $content Prepared content
     * @param array $terms   Available terms
     * @return array|null Result with term_id and confidence
     */
    private function keyword_matching_with_confidence($content, $terms) {
        // Combine all content into one searchable string
        $full_content = strtolower(
            $content['title'] . ' ' . 
            $content['content'] . ' ' . 
            $content['excerpt']
        );
        
        $content_word_count = str_word_count($full_content);
        
        $best_match = null;
        $highest_score = 0;
        $scores = array();
        
        // Score each term based on keyword matches
        foreach ($terms as $term) {
            $score = 0;
            
            // Check term name words (weighted highest - 3 points per match)
            $name_words = explode(' ', strtolower($term['name']));
            foreach ($name_words as $word) {
                if (strlen($word) > 2) { // Ignore very short words
                    $count = substr_count($full_content, $word);
                    $score += $count * 3;
                }
            }
            
            // Check slug words (weighted medium - 2 points per match)
            if (!empty($term['slug'])) {
                $slug_words = explode('-', strtolower($term['slug']));
                foreach ($slug_words as $word) {
                    if (strlen($word) > 2) {
                        $count = substr_count($full_content, $word);
                        $score += $count * 2;
                    }
                }
            }
            
            // Check description words (weighted lowest - 1 point per match)
            if (!empty($term['description'])) {
                $desc_words = explode(' ', strtolower($term['description']));
                foreach ($desc_words as $word) {
                    if (strlen($word) > 3) { // Slightly higher threshold for descriptions
                        $count = substr_count($full_content, $word);
                        $score += $count;
                    }
                }
            }
            
            $scores[$term['term_id']] = $score;
            
            if ($score > $highest_score) {
                $highest_score = $score;
                $best_match = $term;
            }
        }
        
        // Calculate confidence based on score distribution
        if ($highest_score > 0 && $best_match) {
            $total_scores = array_sum($scores);
            
            // Base confidence on how dominant the top match is
            $dominance = ($total_scores > 0) ? ($highest_score / $total_scores) : 0;
            
            // Factor in keyword density
            $density = $highest_score / max(1, ($content_word_count / 100));
            
            // Combine factors (weighted average)
            $confidence = round(($dominance * 100 + $density * 10) / 2);
            
            // Clamp to reasonable range - keyword matching is inherently less certain
            // so we cap at 85% and floor at 15%
            $confidence = max(15, min(85, $confidence));
            
            return array(
                'term_id' => $best_match['term_id'],
                'confidence' => $confidence
            );
        }
        
        // If no keywords matched, return the first term with very low confidence
        // This ensures we always return something, but flags it for review
        if (!empty($terms)) {
            return array(
                'term_id' => $terms[0]['term_id'],
                'confidence' => 10
            );
        }
        
        return null;
    }
}
