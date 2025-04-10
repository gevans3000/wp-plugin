<?php
/**
 * Fallback mechanisms for the Sumai plugin.
 * Provides fallback functionality when dependencies are missing.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides a fallback for OpenAI API when it's not available.
 * Returns a generic summary for testing purposes.
 *
 * @param string $content The content to summarize
 * @param string $prompt The prompt to use
 * @return array Fallback response with summary
 */
function sumai_openai_api_fallback($content, $prompt) {
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Using OpenAI API fallback due to missing API key or connection issue', true);
    }
    
    return [
        'success' => true,
        'fallback' => true,
        'summary' => 'This is a fallback summary generated because the OpenAI API was not available. ' .
                    'Please check your API key and connection settings.',
        'title' => 'Fallback Summary - API Unavailable',
        'tokens_used' => 0,
        'model' => 'fallback'
    ];
}

/**
 * Provides a fallback for Action Scheduler when it's not available.
 * Processes content synchronously instead of in the background.
 *
 * @param array $args The arguments for content processing
 * @return bool Success or failure
 */
function sumai_action_scheduler_fallback($args) {
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Using Action Scheduler fallback - processing synchronously', true);
    }
    
    // Extract required arguments
    $content = $args['content'] ?? '';
    $context_prompt = $args['context_prompt'] ?? '';
    $title_prompt = $args['title_prompt'] ?? '';
    $draft_mode = $args['draft_mode'] ?? false;
    $post_signature = $args['post_signature'] ?? '';
    $articles = $args['articles'] ?? [];
    $status_id = $args['status_id'] ?? '';
    
    // Check if we have the necessary functions
    if (!function_exists('sumai_process_content_helper')) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event('Fallback failed: sumai_process_content_helper function not available', true);
        }
        return false;
    }
    
    // Process content directly
    try {
        return sumai_process_content_helper(
            $content,
            $context_prompt,
            $title_prompt,
            $draft_mode,
            $post_signature,
            $articles,
            $status_id
        );
    } catch (Exception $e) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event('Fallback processing failed: ' . $e->getMessage(), true);
        }
        return false;
    }
}

/**
 * Fallback for logging when the logging system is not available.
 * Writes to error_log as a last resort.
 *
 * @param string $message The message to log
 * @param bool $is_error Whether this is an error message
 */
function sumai_logging_fallback($message, $is_error = false) {
    $prefix = $is_error ? '[SUMAI ERROR] ' : '[SUMAI INFO] ';
    error_log($prefix . $message);
}

/**
 * Fallback for database operations when they fail.
 * Attempts to use transients as temporary storage.
 *
 * @param string $option_name The option name that failed
 * @param mixed $value The value to store
 * @param int $expiration Expiration time in seconds
 * @return bool Success or failure
 */
function sumai_database_fallback($option_name, $value, $expiration = DAY_IN_SECONDS) {
    // Try to use transients as a fallback
    return set_transient('sumai_fallback_' . $option_name, $value, $expiration);
}

/**
 * Retrieves a value from the fallback storage.
 *
 * @param string $option_name The option name to retrieve
 * @param mixed $default Default value if not found
 * @return mixed The retrieved value or default
 */
function sumai_get_fallback_value($option_name, $default = false) {
    return get_transient('sumai_fallback_' . $option_name) ?: $default;
}

/**
 * Provides a fallback mechanism for feed fetching when wp_remote_get fails.
 * Attempts to use file_get_contents as a last resort.
 *
 * @param string $url The URL to fetch
 * @return string|false The fetched content or false on failure
 */
function sumai_feed_fetch_fallback($url) {
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Using feed fetch fallback for URL: ' . $url, true);
    }
    
    // Check if allow_url_fopen is enabled
    if (ini_get('allow_url_fopen')) {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'Mozilla/5.0 (compatible; Sumai/1.0; +http://sumai.example.com)'
                ]
            ]);
            
            $content = file_get_contents($url, false, $context);
            if ($content !== false) {
                return $content;
            }
        } catch (Exception $e) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event('Feed fetch fallback failed: ' . $e->getMessage(), true);
            }
        }
    }
    
    return false;
}
