<?php
/**
 * AJAX handlers for the Sumai plugin admin interface.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Register AJAX handlers for the admin interface.
 */
function sumai_register_ajax_handlers() {
    // Test feed AJAX endpoint
    add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');
    
    // Manual generation AJAX endpoint
    add_action('wp_ajax_sumai_generate_now', 'sumai_ajax_generate_now');
    
    // Check status AJAX endpoint
    add_action('wp_ajax_sumai_check_status', 'sumai_ajax_check_status');
    
    // Processed articles management endpoints
    add_action('wp_ajax_sumai_get_processed_articles', 'sumai_ajax_get_processed_articles');
    add_action('wp_ajax_sumai_clear_all_articles', 'sumai_ajax_clear_all_articles');
    add_action('wp_ajax_sumai_clear_article', 'sumai_ajax_clear_article');
}

/**
 * AJAX handler for testing feed URLs.
 */
function sumai_ajax_test_feeds() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sumai_nonce' ) ) {
        wp_send_json_error( array(
            'message' => 'Security check failed.',
            'progress' => 100
        ) );
    }

    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'You do not have permission to perform this action.',
            'progress' => 100
        ) );
    }

    // Get settings
    $opts = sumai_get_cached_settings();
    $feed_urls = explode( "\n", $opts['feed_urls'] );
    $feed_urls = array_map( 'trim', $feed_urls );
    $feed_urls = array_filter( $feed_urls );

    if ( empty( $feed_urls ) ) {
        wp_send_json_error( array(
            'message' => 'No feed URLs configured. Please add feed URLs in the settings.',
            'progress' => 100
        ) );
    }

    // Test each feed
    $results = array();
    $total_feeds = count($feed_urls);
    $processed = 0;
    
    foreach ( $feed_urls as $url ) {
        // Send progress update
        $progress = floor(($processed / $total_feeds) * 100);
        $response = array(
            'message' => sprintf('Testing feed %d of %d: %s', $processed + 1, $total_feeds, esc_url($url)),
            'progress' => $progress,
            'status' => 'processing'
        );
        
        // Use wp_send_json_success but don't exit so we can continue processing
        wp_send_json_success($response);
        
        // Flush output buffer to send the response immediately
        wp_ob_end_flush_all();
        flush();
        
        // Test the feed
        $feed = fetch_feed( $url );
        
        if ( is_wp_error( $feed ) ) {
            $results[] = array(
                'url' => $url,
                'status' => 'error',
                'message' => $feed->get_error_message()
            );
        } else {
            $item_count = $feed->get_item_quantity();
            $results[] = array(
                'url' => $url,
                'status' => 'success',
                'message' => sprintf( 'Found %d items', $item_count ),
                'items' => $item_count
            );
        }
        
        $processed++;
    }

    // Send final response
    wp_send_json_success( array(
        'results' => $results,
        'progress' => 100,
        'status' => 'complete',
        'message' => 'Feed testing complete.'
    ) );
}

/**
 * AJAX handler for manual content generation.
 */
function sumai_ajax_generate_now() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sumai_nonce' ) ) {
        wp_send_json_error( array(
            'message' => 'Security check failed.',
            'progress' => 100
        ) );
    }

    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'You do not have permission to perform this action.',
            'progress' => 100
        ) );
    }

    // Get parameters
    $draft_mode = isset( $_POST['draft_mode'] ) ? (bool) $_POST['draft_mode'] : false;
    $respect_processed = isset( $_POST['respect_processed'] ) ? (bool) $_POST['respect_processed'] : true;

    // Initialize progress tracking
    $progress_data = array(
        'total_steps' => 5,
        'current_step' => 0,
        'status' => 'processing',
        'message' => 'Starting content generation...'
    );

    // Function to update progress
    $update_progress = function($step, $message, $status = 'processing') use (&$progress_data) {
        $progress_data['current_step'] = $step;
        $progress_data['message'] = $message;
        $progress_data['status'] = $status;
        $progress_data['progress'] = floor(($step / $progress_data['total_steps']) * 100);
        
        // Send progress update
        wp_send_json_success($progress_data);
        
        // Flush output buffer to send the response immediately
        wp_ob_end_flush_all();
        flush();
    };

    // Step 1: Fetch feeds
    $update_progress(1, 'Fetching RSS feeds...');
    
    // Get settings
    $opts = sumai_get_cached_settings();
    $feed_urls = explode( "\n", $opts['feed_urls'] );
    $feed_urls = array_map( 'trim', $feed_urls );
    $feed_urls = array_filter( $feed_urls );

    if ( empty( $feed_urls ) ) {
        wp_send_json_error( array(
            'message' => 'No feed URLs configured. Please add feed URLs in the settings.',
            'progress' => 100
        ) );
    }

    // Step 2: Process feeds
    $update_progress(2, 'Processing feed content...');
    
    $articles = array();
    $processed_articles = sumai_get_processed_articles();
    
    foreach ( $feed_urls as $url ) {
        $feed = fetch_feed( $url );
        
        if ( is_wp_error( $feed ) ) {
            continue;
        }
        
        $items = $feed->get_items();
        
        foreach ( $items as $item ) {
            $guid = $item->get_id();
            
            // Skip if already processed and respect_processed is true
            if ( $respect_processed && isset( $processed_articles[ $guid ] ) ) {
                continue;
            }
            
            $content = $item->get_content();
            $title = $item->get_title();
            $link = $item->get_permalink();
            $date = $item->get_date( 'U' );
            
            $articles[] = array(
                'guid' => $guid,
                'title' => $title,
                'content' => $content,
                'link' => $link,
                'date' => $date
            );
        }
    }

    // Step 3: Prepare for AI processing
    $update_progress(3, 'Preparing content for AI processing...');
    
    if ( empty( $articles ) ) {
        wp_send_json_error( array(
            'message' => 'No new articles found to process.',
            'progress' => 100
        ) );
    }
    
    // Sort articles by date (newest first)
    usort( $articles, function( $a, $b ) {
        return $b['date'] - $a['date'];
    } );
    
    // Prepare content for AI
    $context_prompt = $opts['context_prompt'];
    $title_prompt = $opts['title_prompt'];
    
    // Step 4: Generate content with AI
    $update_progress(4, 'Generating content with AI...');
    
    // Generate content
    $generated_content = sumai_generate_content( $articles, $context_prompt );
    
    if ( is_wp_error( $generated_content ) ) {
        wp_send_json_error( array(
            'message' => 'Error generating content: ' . $generated_content->get_error_message(),
            'progress' => 100
        ) );
    }
    
    // Generate title
    $generated_title = sumai_generate_title( $generated_content, $title_prompt );
    
    if ( is_wp_error( $generated_title ) ) {
        $generated_title = 'AI-Generated Summary: ' . current_time( 'F j, Y' );
    }
    
    // Step 5: Create post
    $update_progress(5, 'Creating post...');
    
    // Add signature if configured
    if ( ! empty( $opts['post_signature'] ) ) {
        $generated_content .= "\n\n" . $opts['post_signature'];
    }
    
    // Create post
    $post_id = wp_insert_post( array(
        'post_title' => $generated_title,
        'post_content' => $generated_content,
        'post_status' => $draft_mode ? 'draft' : 'publish',
        'post_author' => get_current_user_id(),
        'post_type' => 'post'
    ) );
    
    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( array(
            'message' => 'Error creating post: ' . $post_id->get_error_message(),
            'progress' => 100
        ) );
    }
    
    // Mark articles as processed
    foreach ( $articles as $article ) {
        $processed_articles[ $article['guid'] ] = array(
            'date' => current_time( 'timestamp' ),
            'post_id' => $post_id
        );
    }
    
    update_option( 'sumai_processed_articles', $processed_articles );
    
    // Send final response
    wp_send_json_success( array(
        'message' => sprintf( 
            'Content generated successfully. <a href="%s">View Post</a> | <a href="%s">Edit Post</a>',
            get_permalink( $post_id ),
            get_edit_post_link( $post_id )
        ),
        'progress' => 100,
        'status' => 'complete',
        'post_id' => $post_id
    ) );
}

/**
 * AJAX handler for checking status.
 */
function sumai_ajax_check_status() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Get status ID
    if (!isset($_POST['status_id']) || empty($_POST['status_id'])) {
        wp_send_json_error(['message' => 'No status ID provided']);
    }
    
    $status_id = sanitize_text_field($_POST['status_id']);
    
    // Get status
    $status = sumai_get_status($status_id);
    
    if (empty($status)) {
        wp_send_json_error(['message' => 'Status not found']);
    }
    
    // Return status
    wp_send_json_success($status);
}

/**
 * AJAX handler for getting processed articles with pagination and search.
 */
function sumai_ajax_get_processed_articles() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Get pagination parameters
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    // Get processed GUIDs and hashes
    $guids = sumai_get_processed_guids();
    $hashes = sumai_get_processed_hashes();
    
    // Combine and format the data
    $articles = [];
    
    // Process GUIDs
    foreach ($guids as $guid => $timestamp) {
        $articles[$guid] = [
            'id' => $guid,
            'type' => 'guid',
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'age' => human_time_diff($timestamp, time()) . ' ago'
        ];
    }
    
    // Process content hashes
    foreach ($hashes as $hash => $timestamp) {
        // If we already have this as a GUID, just add the hash info
        $found = false;
        foreach ($articles as &$article) {
            if (isset($article['content_hash']) && $article['content_hash'] === $hash) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $articles[$hash] = [
                'id' => $hash,
                'type' => 'hash',
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
                'age' => human_time_diff($timestamp, time()) . ' ago'
            ];
        }
    }
    
    // Apply search filter if provided
    if (!empty($search)) {
        $articles = array_filter($articles, function($article) use ($search) {
            return stripos($article['id'], $search) !== false;
        });
    }
    
    // Sort by timestamp (newest first)
    usort($articles, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Calculate pagination
    $total_items = count($articles);
    $total_pages = ceil($total_items / $per_page);
    
    // Ensure page is within bounds
    $page = max(1, min($page, $total_pages));
    
    // Get items for current page
    $offset = ($page - 1) * $per_page;
    $paged_articles = array_slice($articles, $offset, $per_page);
    
    // Return paginated results
    wp_send_json_success([
        'articles' => $paged_articles,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_items' => $total_items,
            'total_pages' => $total_pages
        ]
    ]);
}

/**
 * AJAX handler for clearing all processed articles.
 */
function sumai_ajax_clear_all_articles() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Clear all processed GUIDs and hashes
    $guids_cleared = update_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    $hashes_cleared = update_option(SUMAI_PROCESSED_HASHES_OPTION, []);
    
    if ($guids_cleared && $hashes_cleared) {
        sumai_log_event('All processed articles cleared by admin');
        wp_send_json_success(['message' => 'All processed articles have been cleared']);
    } else {
        wp_send_json_error(['message' => 'Failed to clear processed articles']);
    }
}

/**
 * AJAX handler for clearing a specific processed article.
 */
function sumai_ajax_clear_article() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Get article ID and type
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        wp_send_json_error(['message' => 'No article ID provided']);
    }
    
    $id = sanitize_text_field($_POST['id']);
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    
    // Get current data
    $guids = sumai_get_processed_guids();
    $hashes = sumai_get_processed_hashes();
    
    $updated = false;
    
    // Remove from appropriate array
    if ($type === 'guid' && isset($guids[$id])) {
        unset($guids[$id]);
        $updated = update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
        sumai_log_event("Processed article GUID cleared: $id");
    } elseif ($type === 'hash' && isset($hashes[$id])) {
        unset($hashes[$id]);
        $updated = update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
        sumai_log_event("Processed article hash cleared: $id");
    } else {
        // Try both if type not specified
        if (isset($guids[$id])) {
            unset($guids[$id]);
            $updated = update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
            sumai_log_event("Processed article GUID cleared: $id");
        }
        
        if (isset($hashes[$id])) {
            unset($hashes[$id]);
            $updated = $updated || update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
            sumai_log_event("Processed article hash cleared: $id");
        }
    }
    
    if ($updated) {
        wp_send_json_success(['message' => 'Article has been cleared']);
    } else {
        wp_send_json_error(['message' => 'Article not found or could not be cleared']);
    }
}

sumai_register_ajax_handlers();