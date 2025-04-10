<?php
/**
 * Feed processing functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates a hash for article content to help with duplicate detection.
 * 
 * @param string $content The article content to hash.
 * @return string MD5 hash of the normalized content.
 */
function sumai_generate_content_hash(string $content): string {
    // Normalize content by removing whitespace and converting to lowercase
    $normalized = strtolower(preg_replace('/\s+/', '', wp_strip_all_tags($content)));
    
    // Generate MD5 hash
    return md5($normalized);
}

/**
 * Fetches new articles from configured RSS feeds.
 * 
 * @param array $feed_urls Array of feed URLs to process.
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before.
 * @return array Array of feed data with articles.
 */
function sumai_fetch_new_articles_content(array $feed_urls, bool $force_fetch = false): array {
    $result = [];
    
    // Get data in a single query instead of multiple calls
    $processed_data = sumai_get_processed_data();
    $processed_guids = $processed_data['guids'];
    $processed_hashes = $processed_data['hashes'];
    
    // Get custom retention period from settings
    $settings = sumai_get_cached_settings();
    $retention_days = isset($settings['retention_period']) ? intval($settings['retention_period']) : 30;
    $retention_seconds = $retention_days * DAY_IN_SECONDS;
    
    // Initialize result structure
    foreach ($feed_urls as $url) {
        $result[$url] = [
            'url' => $url,
            'articles' => [],
            'error' => false,
            'error_message' => '',
        ];
    }
    
    // Process each feed
    foreach ($feed_urls as $url) {
        sumai_log_event("Processing feed: $url");
        
        // Fetch feed with caching
        $feed = sumai_get_cached_feed($url, 1800, $force_fetch);
        
        // Check for errors
        if (is_wp_error($feed)) {
            $result[$url]['error'] = true;
            $result[$url]['error_message'] = $feed->get_error_message();
            sumai_log_event("Feed error: " . $feed->get_error_message(), true);
            continue;
        }
        
        // Get feed items
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT);
        
        if (empty($items)) {
            $result[$url]['error'] = true;
            $result[$url]['error_message'] = 'No items found in feed';
            sumai_log_event("No items found in feed: $url", true);
            continue;
        }
        
        // Track how many unused articles we've found
        $unused_count = 0;
        
        // Process each item
        foreach ($items as $item) {
            $guid = $item->get_id();
            
            // Get content
            $content = $item->get_content();
            $content = wp_strip_all_tags($content);
            
            // Generate content hash for duplicate detection
            $content_hash = sumai_generate_content_hash($content);
            
            // Skip if already processed by GUID or content hash and not forcing
            if (!$force_fetch && 
                ((isset($processed_guids[$guid]) && $processed_guids[$guid] > (time() - $retention_seconds)) ||
                 (isset($processed_hashes[$content_hash]) && $processed_hashes[$content_hash] > (time() - $retention_seconds)))) {
                continue;
            }
            
            // Add to result
            $result[$url]['articles'][] = [
                'title' => $item->get_title(),
                'link' => $item->get_permalink(),
                'guid' => $guid,
                'content' => $content,
                'content_hash' => $content_hash,
                'date' => $item->get_date('U'),
            ];
            
            $unused_count++;
            
            // Limit to 3 unused articles per feed for optimization
            if (!$force_fetch && $unused_count >= 3) {
                break;
            }
        }
        
        sumai_log_event("Found " . count($result[$url]['articles']) . " unused articles in feed: $url");
    }
    
    return $result;
}

/**
 * Gets all processed data (GUIDs and content hashes) in a single database query.
 * 
 * @return array Array with 'guids' and 'hashes' keys containing processed data.
 */
function sumai_get_processed_data(): array {
    static $cache = null;
    
    // Return cached data if available
    if ($cache !== null) {
        return $cache;
    }
    
    $result = [
        'guids' => get_option(SUMAI_PROCESSED_GUIDS_OPTION, []),
        'hashes' => get_option(SUMAI_PROCESSED_HASHES_OPTION, [])
    ];
    
    // Cache the result
    $cache = $result;
    
    return $result;
}

/**
 * Gets a list of article GUIDs that have already been processed.
 * 
 * @return array Array of processed GUIDs with timestamps.
 */
function sumai_get_processed_guids(): array {
    $data = sumai_get_processed_data();
    return $data['guids'];
}

/**
 * Gets a list of content hashes that have already been processed.
 * 
 * @return array Array of processed content hashes with timestamps.
 */
function sumai_get_processed_hashes(): array {
    $data = sumai_get_processed_data();
    return $data['hashes'];
}

/**
 * Marks article GUIDs and content hashes as processed.
 * 
 * @param array $articles Array of articles to mark as processed.
 * @return bool Whether the update was successful.
 */
function sumai_mark_articles_as_processed(array $articles): bool {
    if (empty($articles)) {
        return true;
    }
    
    $processed_data = sumai_get_processed_data();
    $guids = $processed_data['guids'];
    $hashes = $processed_data['hashes'];
    $now = time();
    $updated = false;
    
    // Batch process all articles
    foreach ($articles as $article) {
        if (isset($article['guid'])) {
            $guids[$article['guid']] = $now;
            $updated = true;
        }
        
        if (isset($article['content_hash'])) {
            $hashes[$article['content_hash']] = $now;
            $updated = true;
        }
    }
    
    if (!$updated) {
        return true;
    }
    
    // Prune old entries before saving
    $guids = sumai_prune_processed_items($guids);
    $hashes = sumai_prune_processed_items($hashes);
    
    // Update options in a single transaction if possible
    if (function_exists('wp_cache_get_multiple')) {
        return update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids) && 
               update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
    } else {
        // Fall back to separate updates
        $guid_result = update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
        $hash_result = update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
        return $guid_result && $hash_result;
    }
}

/**
 * Marks article GUIDs as processed.
 * 
 * @param array $guids Array of GUIDs to mark as processed.
 * @return bool Whether the update was successful.
 */
function sumai_mark_guids_as_processed(array $guids): bool {
    $processed = sumai_get_processed_guids();
    $now = time();
    
    foreach ($guids as $guid) {
        $processed[$guid] = $now;
    }
    
    // Prune old entries (older than TTL)
    $processed = sumai_prune_processed_items($processed);
    
    return update_option(SUMAI_PROCESSED_GUIDS_OPTION, $processed);
}

/**
 * Prunes processed items that are older than the retention period.
 * 
 * @param array $items Array of items with timestamps.
 * @return array Pruned array of items.
 */
function sumai_prune_processed_items(array $items): array {
    $now = time();
    
    // Get custom retention period from settings
    $settings = get_option(SUMAI_SETTINGS_OPTION, []);
    $retention_days = isset($settings['retention_period']) ? intval($settings['retention_period']) : 30;
    $retention_seconds = $retention_days * DAY_IN_SECONDS;
    
    foreach ($items as $key => $timestamp) {
        if ($timestamp < ($now - $retention_seconds)) {
            unset($items[$key]);
        }
    }
    
    return $items;
}

/**
 * Tests the RSS feeds in the settings.
 * 
 * @param array $feed_urls Array of feed URLs to test.
 * @return array Results of the feed tests.
 */
function sumai_test_feeds(array $feed_urls): array {
    $results = [];
    
    // Test each feed
    foreach ($feed_urls as $url) {
        $start_time = microtime(true);
        
        // Initialize result
        $results[$url] = [
            'url' => $url,
            'success' => false,
            'item_count' => 0,
            'time' => 0,
            'message' => '',
        ];
        
        // Fetch feed
        $feed = fetch_feed($url);
        
        // Calculate elapsed time
        $elapsed = round(microtime(true) - $start_time, 2);
        $results[$url]['time'] = $elapsed;
        
        // Check for errors
        if (is_wp_error($feed)) {
            $results[$url]['message'] = 'Error: ' . $feed->get_error_message();
            continue;
        }
        
        // Get feed items
        $items = $feed->get_items(0, 10);
        $item_count = count($items);
        
        // Update result
        $results[$url]['success'] = true;
        $results[$url]['item_count'] = $item_count;
        
        if ($item_count > 0) {
            $results[$url]['message'] = "Successfully fetched {$item_count} items in {$elapsed}s";
            
            // Add first item title for verification
            if ($item_count > 0) {
                $first_item = $items[0];
                $results[$url]['first_title'] = $first_item->get_title();
                $results[$url]['first_date'] = $first_item->get_date();
            }
        } else {
            $results[$url]['message'] = "Feed is valid but contains no items";
        }
    }
    
    return $results;
}