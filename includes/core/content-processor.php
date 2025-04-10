<?php
/**
 * Content processing functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main function to generate the daily summary.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_generate_daily_summary(bool $force_fetch = false, bool $draft_mode = false, string $status_id = ''): bool {
    // Create status ID if not provided
    if (empty($status_id)) {
        $status_id = sumai_generate_status_id();
    }
    
    // Update initial status
    sumai_update_status(
        $status_id,
        'Starting content generation process...',
        'pending'
    );
    
    // Get settings - use cached version if available
    static $settings_cache = null;
    if ($settings_cache === null) {
        $settings_cache = get_option(SUMAI_SETTINGS_OPTION);
    }
    $settings = $settings_cache;
    
    // Check if feeds are configured
    if (empty($settings['feed_urls'])) {
        sumai_update_status(
            $status_id,
            'No feeds configured.',
            'error'
        );
        return false;
    }
    
    // Override draft mode if specified
    if ($draft_mode) {
        $settings['draft_mode'] = 1;
    }
    
    // Get API key
    $api_key = sumai_get_api_key();
    
    // Check if API key is available
    if (empty($api_key)) {
        sumai_update_status(
            $status_id,
            'OpenAI API key not configured.',
            'error'
        );
        return false;
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        'Fetching articles from RSS feeds...',
        'processing'
    );
    
    // Get feed URLs
    $feed_urls = explode("\n", $settings['feed_urls']);
    $feed_urls = array_map('trim', $feed_urls);
    $feed_urls = array_filter($feed_urls);
    
    // Fetch articles
    $feeds_data = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    
    // Check if we have any articles
    $has_content = false;
    $total_articles = 0;
    foreach ($feeds_data as $feed_data) {
        if (!empty($feed_data['articles'])) {
            $has_content = true;
            $total_articles += count($feed_data['articles']);
        }
    }
    
    if (!$has_content) {
        sumai_update_status(
            $status_id,
            'No new content found in feeds.',
            'complete'
        );
        return true; // Successfully completed, just nothing to do
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        "Found $total_articles new articles, processing...",
        'processing'
    );
    
    // Process each feed
    $all_processed_articles = [];
    $all_processed_guids = [];
    
    foreach ($feeds_data as $feed_url => $feed_data) {
        // Skip feeds with errors or no articles
        if ($feed_data['error'] || empty($feed_data['articles'])) {
            continue;
        }
        
        // Process this feed
        $feed_info = [
            'url' => $feed_url,
            'article_count' => count($feed_data['articles'])
        ];
        
        // Check if Action Scheduler is available
        if (sumai_check_action_scheduler()) {
            // Process content asynchronously
            $result = sumai_process_content_async(
                $feed_data['articles'],
                $settings['context_prompt'],
                $settings['title_prompt'],
                $feed_info,
                true
            );
            
            if ($result) {
                $all_processed_articles = array_merge($all_processed_articles, $feed_data['articles']);
                $all_processed_guids = array_merge($all_processed_guids, array_column($feed_data['articles'], 'guid'));
            }
        } else {
            // Fallback to direct processing
            $result = sumai_process_content_direct($force_fetch, $draft_mode, $status_id);
            
            if ($result) {
                // Direct processing already marks GUIDs as processed
                return true;
            }
        }
    }
    
    // Mark all articles as processed if we're using async processing
    if (!empty($all_processed_articles)) {
        // Use optimized batch processing function
        sumai_mark_articles_as_processed($all_processed_articles);
        
        sumai_update_status(
            $status_id,
            'Content processing scheduled in background.',
            'complete',
            ['processed_count' => count($all_processed_articles)]
        );
        
        return true;
    } else if (!empty($all_processed_guids)) {
        // Fallback to old method if we only have GUIDs
        sumai_mark_guids_as_processed($all_processed_guids);
        
        sumai_update_status(
            $status_id,
            'Content processing scheduled in background.',
            'complete',
            ['processed_count' => count($all_processed_guids)]
        );
        
        return true;
    }
    
    // If we get here with an empty array, no processing happened
    sumai_update_status(
        $status_id,
        'Failed to process or schedule content.',
        'error'
    );
    
    return false;
}

/**
 * Fallback function for direct processing when Action Scheduler is not available.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save as draft
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_process_content_direct(bool $force_fetch = false, bool $draft_mode = false, string $status_id = ''): bool {
    // Get settings
    $settings = get_option(SUMAI_SETTINGS_OPTION);
    
    // Get API key
    $api_key = sumai_get_api_key();
    
    // Get feed URLs
    $feed_urls = explode("\n", $settings['feed_urls']);
    $feed_urls = array_map('trim', $feed_urls);
    $feed_urls = array_filter($feed_urls);
    
    // Fetch articles
    $feeds_data = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    
    // Prepare content for processing
    $all_articles = [];
    $all_guids = [];
    
    foreach ($feeds_data as $feed_data) {
        if (!$feed_data['error'] && !empty($feed_data['articles'])) {
            $all_articles = array_merge($all_articles, $feed_data['articles']);
            $all_guids = array_merge($all_guids, array_column($feed_data['articles'], 'guid'));
        }
    }
    
    // Skip if no articles
    if (empty($all_articles)) {
        sumai_update_status(
            $status_id,
            'No new content found in feeds.',
            'complete'
        );
        return true;
    }
    
    // Combine all content
    $combined_content = '';
    foreach ($all_articles as $article) {
        $combined_content .= "TITLE: " . $article['title'] . "\n\n";
        $combined_content .= "CONTENT: " . $article['content'] . "\n\n";
        $combined_content .= "SOURCE: " . $article['link'] . "\n\n";
        $combined_content .= "---\n\n";
    }
    
    // Truncate if too long
    if (strlen($combined_content) > SUMAI_MAX_INPUT_CHARS) {
        $combined_content = substr($combined_content, 0, SUMAI_MAX_INPUT_CHARS);
    }
    
    // Get settings
    $context_prompt = $settings['context_prompt'] ?? 'Summarize the key points concisely.';
    $title_prompt = $settings['title_prompt'] ?? 'Generate a compelling and unique title.';
    $draft_mode = isset($settings['draft_mode']) && $settings['draft_mode'] ? true : false;
    $post_signature = $settings['post_signature'] ?? '';
    
    // Process content
    $result = sumai_process_content(
        $combined_content,
        $context_prompt,
        $title_prompt,
        $api_key,
        $draft_mode,
        $post_signature,
        $all_articles,
        $status_id
    );
    
    if ($result) {
        // Mark articles as processed
        sumai_mark_articles_as_processed($all_articles);
        return true;
    }
    
    return false;
}

/**
 * Process content, make OpenAI API call, and create a post.
 * 
 * @param string $content Content to summarize
 * @param string $context_prompt Context prompt for OpenAI
 * @param string $title_prompt Title prompt for OpenAI
 * @param string $api_key OpenAI API key
 * @param bool $draft_mode Whether to save as draft
 * @param string $post_signature Signature to append to post
 * @param array $articles Articles to mark as processed
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_process_content(
    string $content,
    string $context_prompt,
    string $title_prompt,
    string $api_key,
    bool $draft_mode,
    string $post_signature,
    array $articles,
    string $status_id = ''
): bool {
    // Create status ID if not provided
    if (empty($status_id)) {
        $status_id = sumai_generate_status_id();
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        'Sending content to OpenAI for processing...',
        'processing'
    );
    
    // Make API call with caching
    $result = sumai_get_summary($content, $context_prompt, $title_prompt, $api_key);
    
    // Check for API error
    if (!$result) {
        sumai_update_status(
            $status_id,
            'Failed to get response from OpenAI API.',
            'error'
        );
        return false;
    }
    
    // Extract title and content
    $title = $result['title'];
    $post_content = $result['content'];
    
    // Add post signature if provided
    if (!empty($post_signature)) {
        $post_content .= "\n\n" . $post_signature;
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        'Creating WordPress post...',
        'processing'
    );
    
    // Create post
    $post_data = array(
        'post_title'    => $title,
        'post_content'  => $post_content,
        'post_status'   => $draft_mode ? 'draft' : 'publish',
        'post_author'   => 1,
        'post_type'     => 'post'
    );
    
    $post_id = wp_insert_post($post_data);
    
    // Check for post creation error
    if (is_wp_error($post_id)) {
        sumai_update_status(
            $status_id,
            'Failed to create post: ' . $post_id->get_error_message(),
            'error'
        );
        return false;
    }
    
    // Mark articles as processed
    if (!empty($articles)) {
        sumai_mark_articles_as_processed($articles);
    }
    
    // Update status
    $post_url = get_permalink($post_id);
    $post_edit_url = get_edit_post_link($post_id, 'raw');
    
    sumai_update_status(
        $status_id,
        'Post created successfully.',
        'complete',
        array(
            'post_id' => $post_id,
            'post_url' => $post_url,
            'post_edit_url' => $post_edit_url,
            'post_title' => $title,
            'draft_mode' => $draft_mode
        )
    );
    
    return true;
}

/**
 * Helper function to process content with OpenAI API.
 * Used by the Action Scheduler handler (called from action-scheduler.php).
 * 
 * @param string $content Content to summarize
 * @param string $context_prompt Context prompt for OpenAI
 * @param string $title_prompt Title prompt for OpenAI
 * @param bool $draft_mode Whether to save as draft
 * @param string $post_signature Signature to append to post
 * @param array $articles Articles to mark as processed
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_process_content_helper(
    string $content,
    string $context_prompt,
    string $title_prompt,
    bool $draft_mode = false,
    string $post_signature = '',
    array $articles = [],
    string $status_id = ''
): bool {
    // Get API key
    $api_key = sumai_get_api_key();
    
    // Process content
    $result = sumai_process_content(
        $content,
        $context_prompt,
        $title_prompt,
        $api_key,
        $draft_mode,
        $post_signature,
        $articles,
        $status_id
    );
    
    return $result;
}

/**
 * Processes content asynchronously in a background task
 * 
 * @param array $articles Articles to process
 * @param string $context_prompt Context prompt for OpenAI
 * @param string $title_prompt Title prompt for OpenAI
 * @param array $feed_data Feed data including URL and other settings
 * @param bool $update_initial_status Whether to update the initial status
 * @return bool Success or failure
 */
function sumai_process_content_async(
    array $articles,
    string $context_prompt,
    string $title_prompt,
    array $feed_data = [],
    bool $update_initial_status = false
): bool {
    // Check if Action Scheduler is available
    if (!sumai_check_action_scheduler()) {
        return false;
    }
    
    // Create a status ID for tracking
    $status_id = sumai_generate_status_id();
    
    // Get settings once
    static $settings_cache = null;
    if ($settings_cache === null) {
        $settings_cache = get_option(SUMAI_SETTINGS_OPTION);
    }
    $settings = $settings_cache;
    
    // Get draft mode setting
    $draft_mode = !empty($settings['draft_mode']);
    
    // Get post signature
    $post_signature = isset($settings['post_signature']) ? $settings['post_signature'] : '';
    
    // Prepare content from articles
    $content = '';
    foreach ($articles as $article) {
        $content .= "TITLE: " . $article['title'] . "\n\n";
        $content .= "CONTENT: " . $article['content'] . "\n\n";
        $content .= "URL: " . $article['link'] . "\n\n";
        $content .= "---\n\n";
    }
    
    // Update initial status if requested
    if ($update_initial_status) {
        $article_count = count($articles);
        $feed_url = isset($feed_data['url']) ? $feed_data['url'] : 'Unknown feed';
        
        sumai_update_status(
            $status_id,
            "Scheduling processing for $article_count articles from $feed_url",
            'pending'
        );
    }
    
    // Schedule the action
    $args = [
        'content' => $content,
        'context_prompt' => $context_prompt,
        'title_prompt' => $title_prompt,
        'draft_mode' => $draft_mode,
        'post_signature' => $post_signature,
        'articles' => $articles,
        'status_id' => $status_id
    ];
    
    // Use batch scheduling if available
    static $scheduled_actions = [];
    $scheduled_actions[] = $args;
    
    // If we have accumulated enough actions or this is the last batch, schedule them
    if (count($scheduled_actions) >= 5 || $update_initial_status) {
        foreach ($scheduled_actions as $action_args) {
            as_schedule_single_action(
                time(),
                SUMAI_PROCESS_CONTENT_ACTION,
                [$action_args],
                'sumai'
            );
        }
        
        // Clear the batch
        $scheduled_actions = [];
    }
    
    return true;
}

/**
 * Fetches and schedules processing for articles from feeds.
 * This decouples content fetching from API calls to prevent timeouts.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_fetch_and_schedule_processing(bool $force_fetch = false, bool $draft_mode = false, string $status_id = ''): bool {
    // Create status ID if not provided
    if (empty($status_id)) {
        $status_id = sumai_generate_status_id();
    }
    
    // Update initial status
    sumai_update_status(
        $status_id,
        'Starting content generation process...',
        'pending'
    );
    
    // Get settings once and cache
    static $settings_cache = null;
    if ($settings_cache === null) {
        $settings_cache = get_option(SUMAI_SETTINGS_OPTION);
    }
    $settings = $settings_cache;
    
    // Check if feeds are configured
    if (empty($settings['feed_urls'])) {
        sumai_update_status(
            $status_id,
            'No feeds configured.',
            'error'
        );
        return false;
    }
    
    // Override draft mode if specified
    if ($draft_mode) {
        $settings['draft_mode'] = 1;
    }
    
    // Get API key
    $api_key = sumai_get_api_key();
    
    // Check if API key is available
    if (empty($api_key)) {
        sumai_update_status(
            $status_id,
            'OpenAI API key not configured.',
            'error'
        );
        return false;
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        'Fetching articles from RSS feeds...',
        'processing'
    );
    
    // Get feed URLs
    $feed_urls = explode("\n", $settings['feed_urls']);
    $feed_urls = array_map('trim', $feed_urls);
    $feed_urls = array_filter($feed_urls);
    
    // Fetch articles
    $feeds_data = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    
    // Check if we have any articles
    $has_content = false;
    $total_articles = 0;
    foreach ($feeds_data as $feed_data) {
        if (!empty($feed_data['articles'])) {
            $has_content = true;
            $total_articles += count($feed_data['articles']);
        }
    }
    
    if (!$has_content) {
        sumai_update_status(
            $status_id,
            'No new content found in feeds.',
            'complete'
        );
        return true; // Successfully completed, just nothing to do
    }
    
    // Update status
    sumai_update_status(
        $status_id,
        "Found $total_articles new articles, scheduling processing...",
        'processing'
    );
    
    // Prepare batch status updates
    $status_updates = [];
    
    // Process each feed
    $all_processed_articles = [];
    
    foreach ($feeds_data as $feed_url => $feed_data) {
        // Skip feeds with errors or no articles
        if ($feed_data['error'] || empty($feed_data['articles'])) {
            continue;
        }
        
        // Create a status ID for this feed
        $feed_status_id = sumai_generate_status_id();
        
        // Add to batch status updates
        $status_updates[$feed_status_id] = [
            'message' => "Scheduling processing for " . count($feed_data['articles']) . " articles from $feed_url",
            'state' => 'pending',
            'data' => [
                'feed_url' => $feed_url,
                'article_count' => count($feed_data['articles'])
            ]
        ];
        
        // Process this feed
        $feed_info = [
            'url' => $feed_url,
            'article_count' => count($feed_data['articles'])
        ];
        
        // Process content asynchronously
        $result = sumai_process_content_async(
            $feed_data['articles'],
            $settings['context_prompt'],
            $settings['title_prompt'],
            $feed_info,
            false // Don't update status individually
        );
        
        if ($result) {
            $all_processed_articles = array_merge($all_processed_articles, $feed_data['articles']);
        }
    }
    
    // Update all statuses in a single operation
    if (!empty($status_updates)) {
        sumai_update_multiple_statuses($status_updates);
    }
    
    // Mark all articles as processed
    if (!empty($all_processed_articles)) {
        sumai_mark_articles_as_processed($all_processed_articles);
        
        sumai_update_status(
            $status_id,
            'Content processing scheduled in background.',
            'complete',
            ['processed_count' => count($all_processed_articles)]
        );
        
        return true;
    }
    
    // If we get here with an empty array, no processing happened
    sumai_update_status(
        $status_id,
        'Failed to process or schedule content.',
        'error'
    );
    
    return false;
}