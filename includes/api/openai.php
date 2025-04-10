<?php
/**
 * OpenAI API integration for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Summarizes text using OpenAI's API.
 * 
 * @param string $text The text to summarize.
 * @param string $ctx_prompt The context prompt for OpenAI.
 * @param string $title_prompt The title prompt for OpenAI.
 * @param string $api_key The OpenAI API key.
 * @return array|null Array with title and summary or null on failure.
 */
function sumai_summarize_text( string $text, string $ctx_prompt, string $title_prompt, string $api_key ): ?array {
    if (empty($text)) {
        sumai_handle_error('Empty text provided to summarize_text function', SUMAI_ERROR_DATA);
        return null;
    }
    
    // Truncate content if too long
    if (mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) {
        $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);
        sumai_log_event('Text truncated to ' . SUMAI_MAX_INPUT_CHARS . ' characters');
    }
    
    // Prepare messages for API call
    $messages = [
        [
            'role' => 'system',
            'content' => "Output valid JSON {\"title\":\"...\",\"summary\":\"...\"}. Context: " . 
                         ($ctx_prompt ?: "Summarize concisely.") . 
                         " Title: " . ($title_prompt ?: "Generate unique title.")
        ],
        [
            'role' => 'user',
            'content' => "Text:\n\n" . $text
        ]
    ];
    
    // Prepare request body
    $body = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 1500,
        'temperature' => 0.6,
        'response_format' => ['type' => 'json_object']
    ];
    
    // Prepare request arguments
    $args = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode($body),
        'method' => 'POST',
        'timeout' => 90
    ];
    
    // Use retry mechanism for API call
    $result = sumai_with_retry(
        'sumai_make_openai_request',
        ['https://api.openai.com/v1/chat/completions', $args],
        3, // max attempts
        2, // initial delay
        1.5, // backoff factor
        ['http_request_failed', 'timeout', 'internal_server_error', 'rate_limited'] // retry on these errors
    );
    
    return $result;
}

/**
 * Makes an OpenAI API request.
 * This function is used by the retry mechanism.
 * 
 * @param string $url The API endpoint URL.
 * @param array $args The request arguments.
 * @return array|null Array with title and summary or null on failure.
 */
function sumai_make_openai_request($url, $args) {
    // Make API request
    $resp = wp_remote_post($url, $args);
    
    // Handle WP error
    if (is_wp_error($resp)) {
        $error_code = $resp->get_error_code();
        $error_message = $resp->get_error_message();
        
        sumai_handle_error(
            "OpenAI WP Error: {$error_message}",
            SUMAI_ERROR_API,
            ['code' => $error_code, 'url' => $url]
        );
        
        return null;
    }
    
    // Check HTTP status
    $status = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    
    if ($status !== 200) {
        $error_data = json_decode($body, true);
        $error_type = $error_data['error']['type'] ?? 'unknown';
        $error_message = $error_data['error']['message'] ?? 'Unknown error';
        
        // Determine if this error is retryable
        $retryable = in_array($status, [429, 500, 502, 503, 504]);
        
        sumai_handle_error(
            "OpenAI HTTP Error: {$status}. Message: {$error_message}",
            SUMAI_ERROR_API,
            [
                'status' => $status,
                'type' => $error_type,
                'retryable' => $retryable,
                'body' => substr($body, 0, 500) // Limit log size
            ],
            $status >= 500 // Notify admin for server errors
        );
        
        // For rate limiting, throw specific error to trigger retry
        if ($status === 429) {
            return new WP_Error('rate_limited', 'OpenAI API rate limit exceeded');
        }
        
        // For server errors, throw specific error to trigger retry
        if ($status >= 500) {
            return new WP_Error('internal_server_error', "OpenAI server error: {$status}");
        }
        
        return null;
    }
    
    // Parse response
    $data = json_decode($body, true);
    $json_str = $data['choices'][0]['message']['content'] ?? null;
    
    if (!is_string($json_str)) {
        sumai_handle_error(
            'Invalid response format from OpenAI API',
            SUMAI_ERROR_API,
            ['response' => substr(json_encode($data), 0, 500)]
        );
        return null;
    }
    
    // Parse JSON from response
    $parsed = json_decode($json_str, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || 
        !is_array($parsed) || 
        empty($parsed['title']) || 
        !isset($parsed['summary'])) {
        
        sumai_handle_error(
            'Error parsing API JSON response',
            SUMAI_ERROR_API,
            ['json_error' => json_last_error_msg(), 'raw' => substr($json_str, 0, 500)]
        );
        return null;
    }
    
    // Return success
    return [
        'title' => trim($parsed['title']),
        'content' => trim($parsed['summary'])
    ];
}

/**
 * Summarizes text using OpenAI's API with caching.
 * This is a wrapper around sumai_summarize_text that adds caching.
 * 
 * @param string $text The text to summarize.
 * @param string $ctx_prompt The context prompt for OpenAI.
 * @param string $title_prompt The title prompt for OpenAI.
 * @param string $api_key The OpenAI API key.
 * @param bool $force_refresh Whether to force a new API call ignoring cache.
 * @return array|null Array with title and summary or null on failure.
 */
function sumai_get_summary(
    string $text, 
    string $ctx_prompt, 
    string $title_prompt, 
    string $api_key,
    bool $force_refresh = false
): ?array {
    // Use the cached summary function if available
    if (function_exists('sumai_get_cached_summary')) {
        return sumai_get_cached_summary($text, $ctx_prompt, $title_prompt, $api_key, $force_refresh);
    }
    
    // Fall back to direct API call if caching is not available
    return sumai_summarize_text($text, $ctx_prompt, $title_prompt, $api_key);
}