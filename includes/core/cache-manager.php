<?php
/**
 * Cache management functionality for the Sumai plugin.
 * Provides utilities for caching and retrieving data.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gets a cached value or computes it if not available.
 * 
 * @param string $key Cache key.
 * @param callable $callback Function to generate value if not cached.
 * @param int $expiration Expiration time in seconds.
 * @param bool $use_transient Whether to use transient or object cache.
 * @return mixed The cached or computed value.
 */
function sumai_get_cached(string $key, callable $callback, int $expiration = 3600, bool $use_transient = true) {
    $cache_key = 'sumai_' . md5($key);
    $cached_value = null;
    
    // Try to get from object cache first if available
    if (wp_using_ext_object_cache() && !$use_transient) {
        $cached_value = wp_cache_get($cache_key, 'sumai');
    } else {
        $cached_value = get_transient($cache_key);
    }
    
    // Return cached value if available
    if ($cached_value !== false && $cached_value !== null) {
        return $cached_value;
    }
    
    // Generate value using callback
    $value = call_user_func($callback);
    
    // Cache the value
    if (wp_using_ext_object_cache() && !$use_transient) {
        wp_cache_set($cache_key, $value, 'sumai', $expiration);
    } else {
        set_transient($cache_key, $value, $expiration);
    }
    
    return $value;
}

/**
 * Invalidates a cached value.
 * 
 * @param string $key Cache key.
 * @param bool $use_transient Whether to use transient or object cache.
 * @return bool True if successful, false otherwise.
 */
function sumai_invalidate_cache(string $key, bool $use_transient = true): bool {
    $cache_key = 'sumai_' . md5($key);
    
    if (wp_using_ext_object_cache() && !$use_transient) {
        return wp_cache_delete($cache_key, 'sumai');
    } else {
        return delete_transient($cache_key);
    }
}

/**
 * Gets cached plugin settings or loads them from the database.
 * 
 * @param bool $force_refresh Whether to force a refresh from the database.
 * @return array Plugin settings.
 */
function sumai_get_cached_settings(bool $force_refresh = false): array {
    static $settings_cache = null;
    
    // Return cached settings if available and not forcing refresh
    if (!$force_refresh && $settings_cache !== null) {
        return $settings_cache;
    }
    
    // Get settings from cache or database
    $settings = sumai_get_cached('settings', function() {
        $settings = get_option(SUMAI_SETTINGS_OPTION, []);
        
        // Set defaults if missing
        return array_merge([
            'feed_urls' => '',
            'context_prompt' => 'Summarize the key points concisely.',
            'title_prompt' => 'Generate a compelling and unique title.',
            'draft_mode' => 0,
            'schedule_time' => '03:00',
            'post_signature' => '',
            'retention_period' => 30
        ], $settings);
    }, 3600); // Cache for 1 hour
    
    // Update static cache
    $settings_cache = $settings;
    
    return $settings;
}

/**
 * Invalidates the settings cache when settings are updated.
 * 
 * @param mixed $old_value The old option value.
 * @param mixed $new_value The new option value.
 * @param string $option The option name.
 */
function sumai_invalidate_settings_cache($old_value, $new_value, $option): void {
    if ($option === SUMAI_SETTINGS_OPTION) {
        sumai_invalidate_cache('settings');
    }
}

/**
 * Gets cached feed data or fetches it from the source.
 * 
 * @param string $feed_url The feed URL.
 * @param int $cache_time Cache time in seconds (default: 30 minutes).
 * @param bool $force_refresh Whether to force a refresh from the source.
 * @return array|WP_Error Feed data or error.
 */
function sumai_get_cached_feed(string $feed_url, int $cache_time = 1800, bool $force_refresh = false) {
    if ($force_refresh) {
        sumai_invalidate_cache('feed_' . $feed_url);
    }
    
    return sumai_get_cached('feed_' . $feed_url, function() use ($feed_url) {
        return fetch_feed($feed_url);
    }, $cache_time);
}

/**
 * Gets cached API response or makes a new API call.
 * 
 * @param string $text The text to summarize.
 * @param string $ctx_prompt The context prompt for OpenAI.
 * @param string $title_prompt The title prompt for OpenAI.
 * @param string $api_key The OpenAI API key.
 * @param bool $force_refresh Whether to force a new API call.
 * @return array|null Array with title and summary or null on failure.
 */
function sumai_get_cached_summary(
    string $text, 
    string $ctx_prompt, 
    string $title_prompt, 
    string $api_key,
    bool $force_refresh = false
): ?array {
    // Generate a unique key for this request
    $content_hash = md5($text . $ctx_prompt . $title_prompt);
    
    if ($force_refresh) {
        sumai_invalidate_cache('api_' . $content_hash);
    }
    
    // Cache for 7 days since the same content should generate the same summary
    return sumai_get_cached('api_' . $content_hash, function() use ($text, $ctx_prompt, $title_prompt, $api_key) {
        return sumai_summarize_text($text, $ctx_prompt, $title_prompt, $api_key);
    }, 7 * DAY_IN_SECONDS);
}

/**
 * Clears all Sumai plugin caches.
 * 
 * @return int Number of caches cleared.
 */
function sumai_clear_all_caches(): int {
    global $wpdb;
    
    $count = 0;
    
    // Clear transients
    $transients = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_sumai_') . '%'
        )
    );
    
    foreach ($transients as $transient) {
        $transient_name = str_replace('_transient_', '', $transient);
        if (delete_transient($transient_name)) {
            $count++;
        }
    }
    
    // Clear object cache if available
    if (wp_using_ext_object_cache()) {
        wp_cache_flush();
        $count++;
    }
    
    return $count;
}

// Register hooks
add_action('update_option', 'sumai_invalidate_settings_cache', 10, 3);
