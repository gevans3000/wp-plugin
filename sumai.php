<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Fetches RSS articles, summarizes with OpenAI, and posts a daily summary.
 * Version:     1.4.0
 * Author:      biglife360.com
 * Author URI:  https://biglife360.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sumai
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---
define( 'SUMAI_SETTINGS_OPTION', 'sumai_settings' );
define( 'SUMAI_PROCESSED_GUIDS_OPTION', 'sumai_processed_guids' );
define( 'SUMAI_CRON_HOOK', 'sumai_daily_event' );
define( 'SUMAI_CRON_TOKEN_OPTION', 'sumai_cron_token' );
define( 'SUMAI_ROTATE_TOKEN_HOOK', 'sumai_rotate_cron_token' );
define( 'SUMAI_PRUNE_LOGS_HOOK', 'sumai_prune_logs_event' );
define( 'SUMAI_LOG_DIR_NAME', 'sumai-logs' );
define( 'SUMAI_LOG_FILE_NAME', 'sumai.log' );
define( 'SUMAI_MAX_FEED_URLS', 3 );
define( 'SUMAI_FEED_ITEM_LIMIT', 7 );
define( 'SUMAI_MAX_INPUT_CHARS', 25000 );
define( 'SUMAI_PROCESSED_GUID_TTL', 30 * DAY_IN_SECONDS );
define( 'SUMAI_LOG_TTL', 30 * DAY_IN_SECONDS );
define( 'SUMAI_PROCESS_CONTENT_ACTION', 'sumai_process_content_action' );
define( 'SUMAI_STATUS_OPTION', 'sumai_generation_status' );
define( 'SUMAI_STATUS_TRANSIENT', 'sumai_status_' );

/* -------------------------------------------------------------------------
 * 1. ACTIVATION & DEACTIVATION HOOKS
 * ------------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'sumai_activate' );
register_deactivation_hook( __FILE__, 'sumai_deactivate' );

function sumai_activate() {
    $defaults = [
        'feed_urls' => '', 'context_prompt' => "Summarize the key points concisely.",
        'title_prompt' => "Generate a compelling and unique title.", 'api_key' => '', 'draft_mode' => 0,
        'schedule_time' => '03:00', 'post_signature' => ''
    ];
    add_option( SUMAI_SETTINGS_OPTION, $defaults, '', 'no' );
    sumai_ensure_log_dir();
    sumai_schedule_daily_event();
    if (!wp_next_scheduled(SUMAI_ROTATE_TOKEN_HOOK)) wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK);
    if (!get_option(SUMAI_CRON_TOKEN_OPTION)) sumai_rotate_cron_token();
    if (!wp_next_scheduled(SUMAI_PRUNE_LOGS_HOOK)) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin activated. V' . get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
}

function sumai_deactivate() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    wp_clear_scheduled_hook(SUMAI_ROTATE_TOKEN_HOOK);
    wp_clear_scheduled_hook(SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin deactivated.');
}

/* -------------------------------------------------------------------------
 * 1.1. ACTION SCHEDULER INTEGRATION
 * ------------------------------------------------------------------------- */

/**
 * Checks if Action Scheduler is available and loads it if needed.
 * 
 * @return bool True if Action Scheduler is available, false otherwise.
 */
function sumai_check_action_scheduler(): bool {
    // First check if Action Scheduler functions are already available
    if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
        return true;
    }
    
    // Don't try to load Action Scheduler during plugin activation
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return false;
    }
    
    // Check for WooCommerce's Action Scheduler
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php')) {
        include_once WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php';
        return function_exists('as_schedule_single_action');
    }
    
    // Check for standalone Action Scheduler plugin
    if (file_exists(WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php')) {
        include_once WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php';
        return function_exists('as_schedule_single_action');
    }
    
    return false;
}

/* -------------------------------------------------------------------------
 * 2. CRON SCHEDULING & HOOKS
 * ------------------------------------------------------------------------- */

function sumai_schedule_daily_event() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $time_str = (isset($options['schedule_time']) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $options['schedule_time'])) ? $options['schedule_time'] : '03:00';
    $tz = wp_timezone(); $now = current_time('timestamp', true); // GMT
    try {
        $dt = new DateTime(date('Y-m-d').' '.$time_str.':00', $tz);
        $ts = $dt->getTimestamp(); // GMT
        if ($ts <= $now) $dt->modify('+1 day');
        $first_run = $dt->getTimestamp();
        wp_schedule_event($first_run, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled. Next: '.wp_date('Y-m-d H:i:s T', $first_run));
    } catch (Exception $e) {
        sumai_log_event('Error scheduling event: '.$e->getMessage().'. Fallback.', true);
        $tz = wp_timezone(); $dt = new DateTime('now', $tz); $dt->modify('+1 day'); $dt->setTime(3, 0, 0);
        $first_run = $dt->getTimestamp();
        wp_schedule_event($first_run, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled (fallback). Next: '.wp_date('Y-m-d H:i:s T', $first_run));
    }
}

function sumai_rotate_cron_token() {
    update_option(SUMAI_CRON_TOKEN_OPTION, bin2hex(random_bytes(16)));
    sumai_log_event('Cron token rotated.');
}

add_action('update_option_'.SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event', 10, 0);
add_action(SUMAI_CRON_HOOK, 'sumai_generate_daily_summary_event'); // Direct call
add_action(SUMAI_ROTATE_TOKEN_HOOK, 'sumai_rotate_cron_token');
add_action(SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs');

/* -------------------------------------------------------------------------
 * 3. ADMIN HOOKS & AJAX
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', 'sumai_add_admin_menu' );
add_action( 'admin_enqueue_scripts', 'sumai_admin_enqueue_scripts' );
add_action( 'wp_ajax_sumai_manual_generate', 'sumai_ajax_manual_generate' );
add_action( 'wp_ajax_sumai_test_feed', 'sumai_ajax_test_feed' );
add_action( 'wp_ajax_sumai_get_generation_status', 'sumai_ajax_get_generation_status' );

function sumai_admin_enqueue_scripts( $hook ) {
    if ( 'settings_page_sumai-settings' !== $hook ) return;
    wp_enqueue_script( 'sumai-admin-js', plugins_url( 'admin.js', __FILE__ ), array( 'jquery' ), SUMAI_VERSION, true );
    wp_localize_script( 'sumai-admin-js', 'sumaiAdmin', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'sumai-admin-nonce' ),
        'settingsOption' => SUMAI_SETTINGS_OPTION
    ));
}

function sumai_ajax_manual_generate() {
    check_ajax_referer( 'sumai-admin-nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $draft_mode = isset( $_POST['draft_mode'] ) && $_POST['draft_mode'] === 'true';
    
    // Generate a unique status ID for this process
    $status_id = sumai_generate_status_id();
    
    // Initialize status
    sumai_update_status($status_id, 'Starting manual generation process...', 'pending');
    
    // Schedule the generation process
    wp_schedule_single_event( time(), 'sumai_generate_daily_summary_event', array( true, $draft_mode, $status_id ) );
    
    // Return the status ID to the client
    wp_send_json_success( array(
        'status_id' => $status_id,
        'message' => 'Generation process started. Please wait...'
    ));
}

function sumai_ajax_get_generation_status() {
    check_ajax_referer( 'sumai-admin-nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    $status_id = isset( $_POST['status_id'] ) ? sanitize_text_field( $_POST['status_id'] ) : '';
    if (empty($status_id)) {
        wp_send_json_error('Invalid status ID');
    }
    
    $status = sumai_get_status($status_id);
    if ($status === false) {
        wp_send_json_error('Status not found or expired');
    }
    
    wp_send_json_success($status);
}

function sumai_ajax_test_feed() {
    // Existing code...
}

/* -------------------------------------------------------------------------
 * 4. CRON & MAIN FUNCTIONALITY
 * ------------------------------------------------------------------------- */

add_action( SUMAI_CRON_HOOK, 'sumai_generate_daily_summary_event' );
add_action( SUMAI_PROCESS_CONTENT_ACTION, 'sumai_process_content_action' );

// External trigger function
function sumai_check_external_trigger() {
    if (!isset($_GET['sumai_trigger'], $_GET['token']) || $_GET['sumai_trigger'] !== '1') return;
    $provided = sanitize_text_field($_GET['token']);
    $stored = get_option(SUMAI_CRON_TOKEN_OPTION);
    if ($stored && hash_equals($stored, $provided)) {
        $lock_key = 'sumai_external_trigger_lock';
        if (false === get_transient($lock_key)) {
            set_transient($lock_key, 1, MINUTE_IN_SECONDS * 5);
            sumai_log_event('External trigger validated. Running summary generation...');
            do_action(SUMAI_CRON_HOOK);
        } else { sumai_log_event('External trigger skipped, lock active.', true); }
    } else { sumai_log_event('Invalid external trigger token.', true); }
}
add_action('init', 'sumai_check_external_trigger', 5);

/**
 * Event handler for the daily summary generation cron job.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Optional status ID for tracking progress
 */
function sumai_generate_daily_summary_event( bool $force_fetch = false, bool $draft_mode = false, string $status_id = '' ) {
    // Check if we're in manual mode (admin triggered)
    $is_manual = !empty($status_id);
    
    // Use the provided status ID or generate a new one
    if (empty($status_id)) {
        $status_id = sumai_generate_status_id();
    }
    
    // Update status
    sumai_update_status($status_id, 'Starting summary generation...', 'processing');
    
    // Call the main function with the status ID
    sumai_generate_daily_summary($force_fetch, $draft_mode, $status_id);
}

/**
 * Main function to generate the daily summary.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_generate_daily_summary( bool $force_fetch = false, bool $draft_mode = false, string $status_id = '' ): bool {
    if (empty($status_id)) {
        $status_id = sumai_generate_status_id();
    }
    
    sumai_log_event("Starting daily summary generation. Status ID: {$status_id}");
    sumai_update_status($status_id, 'Starting summary generation...', 'pending');
    
    $settings = get_option(SUMAI_SETTINGS_OPTION);
    
    // Validation
    if (empty($settings['feed_urls'])) {
        sumai_update_status($status_id, 'No feed URLs configured.', 'error');
        sumai_log_event('No feed URLs configured.', true);
        return false;
    }
    
    if (empty($settings['api_key'])) {
        sumai_update_status($status_id, 'OpenAI API key not configured.', 'error');
        sumai_log_event('OpenAI API key not configured.', true);
        return false;
    }
    
    // Check for Action Scheduler availability
    if (!sumai_check_action_scheduler()) {
        sumai_update_status($status_id, 'Action Scheduler not available. Attempting to process directly.', 'processing');
        sumai_log_event('Action Scheduler not available. Attempting to process directly.', true);
        return sumai_process_content_direct($force_fetch, $draft_mode, $status_id);
    }
    
    // If force draft mode is enabled, override the setting
    if ($draft_mode) {
        $settings['draft_mode'] = 1;
    }
    
    // Parse and clean feed URLs
    $feed_urls = array_map('trim', explode("\n", $settings['feed_urls']));
    $feed_urls = array_filter($feed_urls);
    
    if (empty($feed_urls)) {
        sumai_update_status($status_id, 'No valid feed URLs found after parsing.', 'error');
        sumai_log_event('No valid feed URLs found after parsing.', true);
        return false;
    }
    
    // Fetch new content from feeds
    sumai_update_status($status_id, 'Fetching content from RSS feeds...', 'processing');
    
    $result = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    
    if (is_wp_error($result)) {
        sumai_update_status(
            $status_id, 
            'Error fetching feeds: ' . $result->get_error_message(), 
            'error'
        );
        sumai_log_event('Error fetching feeds: ' . $result->get_error_message(), true);
        return false;
    }
    
    if (empty($result['content'])) {
        sumai_update_status(
            $status_id, 
            'No new content found in feeds. Skipping summary generation.', 
            'complete',
            ['articles_checked' => $result['articles_checked'] ?? 0]
        );
        sumai_log_event('No new content found in feeds. Skipping summary generation.');
        return true; // Not an error, just nothing to process
    }
    
    // Update status with data about what was fetched
    sumai_update_status(
        $status_id, 
        'Feed content fetched. Scheduling background processing...', 
        'processing',
        [
            'articles_processed' => count($result['guids'] ?? []),
            'articles_checked' => $result['articles_checked'] ?? 0,
            'content_length' => strlen($result['content'])
        ]
    );
    
    // Schedule background processing task
    $args = [
        'content' => $result['content'],
        'context_prompt' => $settings['context_prompt'],
        'title_prompt' => $settings['title_prompt'],
        'api_key' => $settings['api_key'],
        'draft_mode' => !empty($settings['draft_mode']) || $draft_mode,
        'post_signature' => $settings['post_signature'] ?? '',
        'guids_to_add' => $result['guids'] ?? [],
        'status_id' => $status_id
    ];
    
    // Schedule the background task with Action Scheduler
    as_schedule_single_action(
        time(), // Run immediately 
        SUMAI_PROCESS_CONTENT_ACTION,
        [$args]
    );
    
    sumai_log_event("Background content processing scheduled. Status ID: {$status_id}");
    return true;
}

/**
 * Fallback function for direct processing when Action Scheduler is not available.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Status ID for tracking progress
 * @return bool Success or failure
 */
function sumai_process_content_direct(bool $force_fetch = false, bool $draft_mode = false, string $status_id = ''): bool {
    sumai_log_event("Starting direct content processing (fallback mode).");
    
    $settings = get_option(SUMAI_SETTINGS_OPTION);
    
    // Parse and clean feed URLs
    $feed_urls = array_map('trim', explode("\n", $settings['feed_urls']));
    $feed_urls = array_filter($feed_urls);
    
    // Fetch new content from feeds
    $result = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    
    if (is_wp_error($result)) {
        sumai_update_status(
            $status_id, 
            'Error fetching feeds: ' . $result->get_error_message(), 
            'error'
        );
        sumai_log_event('Error fetching feeds: ' . $result->get_error_message(), true);
        return false;
    }
    
    if (empty($result['content'])) {
        sumai_update_status(
            $status_id, 
            'No new content found in feeds. Skipping summary generation.', 
            'complete',
            ['articles_checked' => $result['articles_checked'] ?? 0]
        );
        sumai_log_event('No new content found in feeds. Skipping summary generation.');
        return true; // Not an error, just nothing to process
    }
    
    // Process content directly since Action Scheduler is not available
    return sumai_process_content(
        $result['content'],
        $settings['context_prompt'],
        $settings['title_prompt'],
        $settings['api_key'],
        !empty($settings['draft_mode']) || $draft_mode,
        $settings['post_signature'] ?? '',
        $result['guids'] ?? [],
        $status_id
    );
}

/**
 * Background action to process content and create a post.
 * 
 * @param array $args Arguments for processing
 * @return bool Success or failure
 */
function sumai_process_content_action(array $args) {
    sumai_log_event('Starting background content processing.');
    
    // Extract all arguments from the first array element
    // This is necessary because Action Scheduler passes arguments as a single nested array
    if (isset($args[0]) && is_array($args[0])) {
        $args = $args[0];
    }
    
    $status_id = $args['status_id'] ?? '';
    if (!empty($status_id)) {
        sumai_update_status($status_id, 'Starting OpenAI API processing...', 'processing');
    }
    
    if (empty($args['content'])) {
        if (!empty($status_id)) {
            sumai_update_status($status_id, 'No content to process.', 'error');
        }
        sumai_log_event('No content to process in background action.', true);
        return false;
    }
    
    return sumai_process_content(
        $args['content'],
        $args['context_prompt'],
        $args['title_prompt'],
        $args['api_key'],
        $args['draft_mode'],
        $args['post_signature'],
        $args['guids_to_add'],
        $status_id
    );
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
 * @param array $guids_to_add GUIDs to mark as processed
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
    array $guids_to_add,
    string $status_id = ''
) {
    if (empty($content)) {
        if (!empty($status_id)) {
            sumai_update_status($status_id, 'No content to process.', 'error');
        }
        sumai_log_event('No content to process in sumai_process_content.', true);
        return false;
    }
    
    if (!empty($status_id)) {
        sumai_update_status($status_id, 'Calling OpenAI API to generate summary...', 'processing');
    }
    
    // Call OpenAI API
    $summary_data = sumai_summarize_text($content, $context_prompt, $title_prompt, $api_key);
    
    if (!$summary_data) {
        if (!empty($status_id)) {
            sumai_update_status($status_id, 'Failed to generate summary from OpenAI.', 'error');
        }
        sumai_log_event('Failed to generate summary from OpenAI.', true);
        return false;
    }
    
    if (!empty($status_id)) {
        sumai_update_status($status_id, 'Summary generated. Creating post...', 'processing');
    }
    
    // Create post
    $post_content = $summary_data['content'];
    
    // Add signature if provided
    if (!empty($post_signature)) {
        $post_content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . wp_kses_post($post_signature);
    }
    
    $post_data = array(
        'post_title'    => $summary_data['title'],
        'post_content'  => $post_content,
        'post_status'   => $draft_mode ? 'draft' : 'publish',
        'post_author'   => 1,
        'post_type'     => 'post',
    );
    
    $post_id = wp_insert_post($post_data);
    
    if (is_wp_error($post_id)) {
        if (!empty($status_id)) {
            sumai_update_status($status_id, 'Failed to create post: ' . $post_id->get_error_message(), 'error');
        }
        sumai_log_event('Failed to create post: ' . $post_id->get_error_message(), true);
        return false;
    }
    
    // Update processed GUIDs
    if (!empty($guids_to_add)) {
        $processed = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
        $processed = array_merge($processed, $guids_to_add);
        
        // Prune old GUIDs to prevent the option from growing too large
        $cutoff = time() - SUMAI_PROCESSED_GUID_TTL;
        foreach ($processed as $guid => $timestamp) {
            if ($timestamp < $cutoff) {
                unset($processed[$guid]);
            }
        }
        
        update_option(SUMAI_PROCESSED_GUIDS_OPTION, $processed);
    }
    
    if (!empty($status_id)) {
        $post_url = get_permalink($post_id);
        $status_message = $draft_mode 
            ? 'Draft created successfully. <a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">Edit draft</a>'
            : 'Post published successfully. <a href="' . esc_url($post_url) . '" target="_blank">View post</a>';
        
        sumai_update_status($status_id, $status_message, 'complete', [
            'post_id' => $post_id,
            'post_url' => $post_url,
            'is_draft' => $draft_mode
        ]);
    }
    
    sumai_log_event('Post ' . ($draft_mode ? 'draft' : 'published') . ' successfully. ID: ' . $post_id);
    return true;
}

/* -------------------------------------------------------------------------
 * 5. FEED FETCHING & CONTENT PREPARATION
 * ------------------------------------------------------------------------- */

function sumai_fetch_new_articles_content( array $feed_urls, bool $force_fetch = false ) {
    if (!function_exists('fetch_feed')) { include_once ABSPATH.WPINC.'/feed.php'; }
    if (!function_exists('fetch_feed')) { 
        sumai_log_event('Error: fetch_feed unavailable.', true); 
        return new WP_Error('fetch_feed_unavailable', 'WordPress fetch_feed function not available'); 
    }
    
    $content = ''; 
    $new_guids = []; 
    $now = time(); 
    $char_count = 0; 
    $processed = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    $total_articles_added = 0; // Track total articles added across all feeds
    $total_articles_checked = 0; // Track total articles checked
    
    foreach ($feed_urls as $url) { 
        // Stop if we've already added 3 articles total
        if ($total_articles_added >= 3) {
            sumai_log_event('Maximum of 3 articles reached, skipping remaining feeds.');
            break;
        }
        
        $url = esc_url_raw(trim($url)); 
        if (empty($url)) continue; 

        // --- OPTIMIZATION: Check character count *before* fetching the feed --- 
        if ($char_count >= SUMAI_MAX_INPUT_CHARS) {
             sumai_log_event('Character limit reached (/'.SUMAI_MAX_INPUT_CHARS.'), skipping remaining feeds.'); 
             break; // Exit the outer loop entirely, no need to fetch more feeds
        }
        // --- END OPTIMIZATION ---

        $feed = fetch_feed($url); 
        if (is_wp_error($feed)) { 
            sumai_log_event("Err fetch {$url}: ".$feed->get_error_message(), true); 
            continue; 
        } 
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); 
        if (empty($items)) { 
            // Optional: Log if a feed returns no items
            // sumai_log_event("Feed {$url} returned no items.");
            unset($feed); // Clean up feed object
            continue; 
        }
        
        $article_added_for_this_feed = false; // Track if we've added an article from this feed
        $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST); // Get feed title once
        
        foreach ($items as $item) { 
            // Track articles checked
            $total_articles_checked++;
            
            // Skip if we already added an article from this feed
            if ($article_added_for_this_feed) {
                break;
            }
            
            $guid = $item->get_id(true); 
            // Skip if we've already processed this article
            if (isset($new_guids[$guid]) || (!$force_fetch && isset($processed[$guid]))) { 
                continue; 
            }
            
            $text_content = $item->get_content() ?: $item->get_description();
            if (empty($text_content)) continue; // Skip if no content or description

            $text = trim(preg_replace('/\s+/s', ' ', wp_strip_all_tags($text_content))); // Added /s modifier for newline handling
            if (empty($text)) continue; // Skip if content becomes empty after stripping tags
            
            $item_title = strip_tags($item->get_title()?:'Untitled'); 
            $item_source_info = "Source: ".esc_html($feed_title)."\nTitle: ".esc_html($item_title)."\nContent:\n";
            $formatted_content = $item_source_info . $text . "\n\n---\n\n";
            
            $content_length = mb_strlen($formatted_content);
            
            // Check if adding this item exceeds the limit
            if (($char_count + $content_length) > SUMAI_MAX_INPUT_CHARS) { 
                 sumai_log_event("Character limit reached while processing item '".esc_html($item_title)."' from feed '".esc_html($feed_title)."'. Processed chars: {$char_count}/".SUMAI_MAX_INPUT_CHARS); 
                 break; // Stop processing items for *this* feed
            } 
            
            // Append content and update counts/GUIDs
            $content .= $formatted_content; 
            $char_count += $content_length; 
            $new_guids[$guid] = $now; 
            $article_added_for_this_feed = true; // Mark that we've added an article from this feed
            $total_articles_added++; // Increment total articles counter
            
            sumai_log_event("Added article '{$item_title}' from feed '{$feed_title}'. Total articles: {$total_articles_added}/3");
            
            // Break if we've reached the maximum of 3 articles total
            if ($total_articles_added >= 3) {
                break;
            }
        } 
        unset($feed, $items, $feed_title); // Clean up variables for this feed
    } 
    
    sumai_log_event("Fetched content: " . $char_count . " characters, " . count($new_guids) . " new items from " . count($feed_urls) . " feeds. Checked " . $total_articles_checked . " articles."); // Log summary
    
    return [
        'content' => $content,
        'guids' => $new_guids,
        'articles_checked' => $total_articles_checked,
        'feeds_checked' => count($feed_urls),
        'articles_processed' => $total_articles_added,
        'char_count' => $char_count
    ];
}

/* -------------------------------------------------------------------------
 * 6. OPENAI SUMMARIZATION & API
 * ------------------------------------------------------------------------- */

function sumai_summarize_text( string $text, string $ctx_prompt, string $title_prompt, string $api_key ): ?array {
    if (empty($text)) return null; if (mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);
    $messages = [ ['role'=>'system', 'content'=>"Output valid JSON {\"title\":\"...\",\"summary\":\"...\"}. Context: ".($ctx_prompt?:"Summarize concisely.")." Title: ".($title_prompt?:"Generate unique title.")], ['role'=>'user', 'content'=>"Text:\n\n".$text] ];
    $body = ['model'=>'gpt-4o-mini', 'messages'=>$messages, 'max_tokens'=>1500, 'temperature'=>0.6, 'response_format'=>['type'=>'json_object']];
    $args = ['headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$api_key],'body'=>json_encode($body),'method'=>'POST','timeout'=>90];
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    if (is_wp_error($resp)) { sumai_log_event('OpenAI WP Error: '.$resp->get_error_message(), true); return null; }
    $status = wp_remote_retrieve_response_code($resp); $body = wp_remote_retrieve_body($resp); if ($status !== 200) { sumai_log_event("OpenAI HTTP Error: {$status}. Body: ".$body, true); return null; }
    $data = json_decode($body, true); $json_str = $data['choices'][0]['message']['content'] ?? null; if (!is_string($json_str)) return null; $parsed = json_decode($json_str, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed) || empty($parsed['title']) || !isset($parsed['summary'])) { sumai_log_event('Error parsing API JSON. Raw: '.$json_str, true); return null; }
    return ['title'=>trim($parsed['title']), 'content'=>trim($parsed['summary'])];
}

/* -------------------------------------------------------------------------
 * 7. API KEY & POST SIGNATURE
 * ------------------------------------------------------------------------- */

function sumai_get_api_key(): string { static $k=null; if($k!==null)return $k; if(defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY))return $k=SUMAI_OPENAI_API_KEY; $o=get_option(SUMAI_SETTINGS_OPTION); $e=$o['api_key']??''; if(empty($e)||!function_exists('openssl_decrypt')||!defined('AUTH_KEY')||!AUTH_KEY)return $k=''; $d=base64_decode($e,true); $c='aes-256-cbc'; $il=openssl_cipher_iv_length($c); if($d===false||$il===false||strlen($d)<=$il)return $k=''; $iv=substr($d,0,$il); $cr=substr($d,$il); $dec=openssl_decrypt($cr,$c,AUTH_KEY,OPENSSL_RAW_DATA,$iv); return $k=($dec===false)?'':$dec; }
function sumai_validate_api_key(string $api_key): bool { if(empty($api_key)||strpos($api_key,'sk-')!==0)return false; $r=wp_remote_get('https://api.openai.com/v1/models',['headers'=>['Authorization'=>'Bearer '.$api_key],'timeout'=>15]); $v=(!is_wp_error($r)&&wp_remote_retrieve_response_code($r)===200); if(!$v)sumai_log_event('API Key validation FAILED.'.(is_wp_error($r)?' WP Err: '.$r->get_error_message():' Status: '.wp_remote_retrieve_response_code($r)),true); return $v; }
function sumai_append_signature_to_content($content) { if(is_singular('post')&&!is_admin()&&in_the_loop()&&is_main_query()){$s=trim(get_option(SUMAI_SETTINGS_OPTION)['post_signature']??'');if(!empty($s)){$h=wp_kses_post($s);if(strpos($content,$h)===false)$content.="\n\n<hr class=\"sumai-signature-divider\" />\n".$h;}} return $content;} add_filter('the_content','sumai_append_signature_to_content',99);

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'sumai_add_admin_menu'); add_action('admin_init', 'sumai_register_settings');
function sumai_add_admin_menu() { add_options_page('Sumai Settings', 'Sumai', 'manage_options', 'sumai-settings', 'sumai_render_settings_page'); }
function sumai_register_settings() { register_setting('sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings'); }

function sumai_sanitize_settings($input): array { 
    $s=[]; 
    $c=get_option(SUMAI_SETTINGS_OPTION,[]); 
    $ce=$c['api_key']??''; 
    $vu=[]; 
    if(isset($input['feed_urls'])){ 
        $us=array_map('trim',preg_split('/\r\n|\r|\n/',sanitize_textarea_field($input['feed_urls']))); 
        foreach($us as $u){ 
            if(!empty($u)&&filter_var($u,FILTER_VALIDATE_URL)&&preg_match('/^https?:\/\//',$u))$vu[]=$u; 
        } 
        $vu=array_slice($vu,0,SUMAI_MAX_FEED_URLS); 
    } 
    $s['feed_urls']=implode("\n",$vu); 
    $s['context_prompt']=isset($input['context_prompt'])?sanitize_textarea_field($input['context_prompt']):''; 
    $s['title_prompt']=isset($input['title_prompt'])?sanitize_textarea_field($input['title_prompt']):''; 
    $s['draft_mode']=(isset($input['draft_mode'])&&$input['draft_mode']=='1')?1:0; 
    $s['post_signature']=isset($input['post_signature'])?wp_kses_post($input['post_signature']):''; 
    $t=isset($input['schedule_time'])?sanitize_text_field($input['schedule_time']):'03:00'; 
    $s['schedule_time']=preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/',$t)?$t:($c['schedule_time']??'03:00'); 
    if(defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY)){ 
        $s['api_key']=$ce; 
    } elseif(isset($input['api_key'])){ 
        $ni=sanitize_text_field(trim($input['api_key'])); 
        if($ni==='********************'){ 
            $s['api_key']=$ce; 
        } elseif(empty($ni)){ 
            $s['api_key']=''; 
            if(!empty($ce))sumai_log_event('API key cleared.'); 
        } else { 
            if(function_exists('openssl_encrypt')&&defined('AUTH_KEY')&&AUTH_KEY){ 
                $cp='aes-256-cbc'; 
                $il=openssl_cipher_iv_length($cp); 
                if($il!==false){ 
                    $iv=openssl_random_pseudo_bytes($il); 
                    $en=openssl_encrypt($ni,$cp,AUTH_KEY,OPENSSL_RAW_DATA,$iv); 
                    if($en!==false&&$iv!==false){ 
                        $ne=base64_encode($iv.$en); 
                        if($ne!==$ce)sumai_log_event('API key saved.'); 
                        $s['api_key']=$ne; 
                    } else { 
                        $s['api_key']=$ce; 
                    } 
                } 
            } else $s['api_key']=$ce; 
        } 
    } 
    return $s; 
}

function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now_action')) {
        $draft_mode = isset($_POST['draft_mode']) ? (bool)$_POST['draft_mode'] : false;
        $status_id = sumai_generate_status_id();
        sumai_update_status($status_id, 'Starting manual generation...', 'pending');
        wp_schedule_single_event(time(), 'sumai_generate_daily_summary_event', array(true, $draft_mode, $status_id));
        echo '<div class="notice notice-info"><p>Summary generation started in the background. Check the status below.</p></div>';
        echo '<script>var sumaiStatusId = "' . esc_js($status_id) . '";</script>';
    }
    $opts = get_option(SUMAI_SETTINGS_OPTION, []);
    $opts = array_merge(['feed_urls'=>'', 'context_prompt'=>'', 'title_prompt'=>'', 'draft_mode'=>0, 'schedule_time'=>'03:00', 'post_signature'=>''], $opts);
    ?>
    <div class="wrap"><h1>Sumai Settings</h1>
    <div id="sumai-tabs">
    <h2 class="nav-tab-wrapper">
        <a href="#tab-settings" class="nav-tab nav-tab-active">Settings</a>
        <a href="#tab-advanced" class="nav-tab">Advanced</a>
        <a href="#tab-debug" class="nav-tab">Debug</a>
    </h2>
    <div id="tab-settings" class="tab-content">
    <form method="post" action="options.php"><?php settings_fields('sumai_options_group'); ?>
    <h2>OpenAI API</h2>
    <table class="form-table">
    <tr><th><label for="f_key">API Key</label></th><td><input type="text" id="f_key" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[api_key]" value="<?php echo !empty($opts['api_key'])?'********************':''; ?>" class="regular-text" placeholder="sk-..." autocomplete="off" <?php echo defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY)?'disabled':''; ?>/><?php if(defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY)) echo '<p class="description">Using key defined in wp-config.php</p>'; ?></td></tr>
    </table>
    <h2>Feed Settings</h2>
    <table class="form-table">
    <tr><th><label for="f_urls">Feed URLs</label></th><td><textarea id="f_urls" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[feed_urls]" rows="5" class="large-text" placeholder="https://example.com/feed/"><?=esc_textarea($opts['feed_urls'])?></textarea><p class="description">One URL per line. Max <?=SUMAI_MAX_FEED_URLS?> feeds.</p></td></tr>
    <tr><th><label for="f_ctx">Summary Prompt</label></th><td><textarea id="f_ctx" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[context_prompt]" rows="3" class="large-text" placeholder="e.g., Summarize the key points of the article concisely."><?=esc_textarea($opts['context_prompt'])?></textarea><p class="description">Optional: Provide specific instructions for the AI summarization process.</p></td></tr>
    <tr><th><label for="f_ttl">Title Prompt</label></th><td><textarea id="f_ttl" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[title_prompt]" rows="2" class="large-text" placeholder="e.g., Generate a unique and engaging title for this summary."><?=esc_textarea($opts['title_prompt'])?></textarea><p class="description">Optional: Provide instructions for AI to generate the post title. Leave blank to use the original article title if possible.</p></td></tr> {/* UX Change */}
    <tr><th>Status</th><td><fieldset><label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="0" <?php checked(0,$opts['draft_mode'])?>> Publish</label> <label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="1" <?php checked(1,$opts['draft_mode'])?>> Draft</label></fieldset></td></tr>
    <tr><th><label for="f_time">Schedule Time</label></th><td><input type="time" id="f_time" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[schedule_time]" value="<?=esc_attr($opts['schedule_time'])?>" required pattern="([01]?\d|2[0-3]):([0-5]\d)"/><p>Daily (HH:MM). TZ: <strong><?=esc_html(wp_timezone_string())?></strong>.<?php $next=wp_next_scheduled(SUMAI_CRON_HOOK); echo '<br>Next: '.($next?wp_date('Y-m-d H:i',$next):'N/A');?></p></td></tr>
    <tr><th><label for="f_sig">Signature</label></th><td><textarea id="f_sig" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[post_signature]" rows="3" class="large-text"><?=esc_textarea($opts['post_signature'])?></textarea></td></tr>
    </table><?php submit_button('Save Settings');?></form></div>
    <div id="tab-advanced" class="tab-content" style="display:none;">
        <h2>Advanced</h2>
        <div class="card">
            <h3>Generate Now</h3>
            <form name="sumai_generate_now" method="post">
                <p>
                    <label><input type="radio" name="draft_mode" value="0" <?php checked(0,$opts['draft_mode'])?>> Publish</label>
                    <label><input type="radio" name="draft_mode" value="1" <?php checked(1,$opts['draft_mode'])?>> Draft</label>
                </p>
                <input type="submit" name="sumai_generate_now" class="button button-primary" value="Generate Now">
                <?php wp_nonce_field('sumai_generate_now_action');?>
            </form>
            <div id="sumai-status-container" style="display:none; margin-top: 15px;">
                <div class="sumai-status-header" style="margin-bottom: 10px; font-weight: bold;">Generation Status:</div>
                <div class="sumai-status-message" style="padding: 10px; background: #f8f8f8; border-left: 4px solid #2271b1;"></div>
            </div>
        </div>
        <div class="card">
            <h3>Test Feeds</h3>
            <button type="button" id="test-feed-btn" class="button">Test</button>
            <div id="feed-test-res"></div>
        </div>
        <div class="card">
            <h3>External Cron</h3>
            <?php 
            $tok=get_option(SUMAI_CRON_TOKEN_OPTION); 
            if($tok){
                $url=add_query_arg(['sumai_trigger'=>'1','token'=>$tok],site_url('/')); 
                echo '<input type="text" value="'.esc_url($url).'" readonly onfocus="this.select();">';
                echo '<p><code>wget -qO- \''.esc_url($url).'\' > /dev/null</code></p>';
            } else {
                echo '<p>Save settings.</p>';
            }
            ?>
        </div>
    </div>
    <div id="tab-debug" class="tab-content" style="display:none;"><h2>Debug</h2><?php sumai_render_debug_info();?></div>
    </div></div>
    <style>
    .card{padding:15px;border:1px solid #ccc;background:#fff;margin:20px 0;}
    .nav-tab-wrapper{margin-bottom:20px;}
    #api-test-res,#feed-test-res{margin-left:10px;vertical-align:middle;}
    #feed-test-res{display:none;white-space:pre-wrap;font-family:monospace;max-height:300px;overflow-y:auto;background:#f9f9f9;padding:10px;border:1px solid #ccc;}
    </style>
    <script>
    // Initialize status tracking if we have a status ID from a form submission
    jQuery(document).ready(function($) {
        // Tab functionality
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');
            
            // Update active tab
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show selected tab content, hide others
            $('.tab-content').hide();
            $(target).show();
        });
        
        if (typeof sumaiStatusId !== 'undefined') {
            $('#sumai-status-container').show();
            $('.sumai-status-message').html('<span class="spinner is-active" style="float:left; margin-right:10px;"></span> Starting generation process...');
            
            // Set the status ID in the admin.js script
            window.currentStatusId = sumaiStatusId;
            
            // Start polling
            if (typeof startStatusPolling === 'function') {
                startStatusPolling();
            }
        }
    });
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * 9. AJAX HANDLERS
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_sumai_test_api_key', 'sumai_ajax_test_api_key');
add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');

function sumai_ajax_test_api_key() { check_ajax_referer('sumai_test_api_key_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403); $key_input = isset($_POST['api_key_to_test']) ? trim(sanitize_text_field($_POST['api_key_to_test'])) : ''; $key_to_test = empty($key_input) ? sumai_get_api_key() : $key_input; $context = empty($key_input) ? 'Current key' : 'Provided key'; if (empty($key_to_test)) { wp_send_json_error(['message'=>'API key not configured.']); return; } if (sumai_validate_api_key($key_to_test)) wp_send_json_success(['message'=>$context.' validation OK.']); else wp_send_json_error(['message'=>$context.' validation FAILED. Check logs.']); }
function sumai_ajax_test_feeds() { check_ajax_referer('sumai_test_feeds_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403); $options = get_option(SUMAI_SETTINGS_OPTION, []); $urls = empty($options['feed_urls']) ? [] : array_slice(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS); if (empty($urls)) { wp_send_json_error(['message'=>'No feeds configured.']); return; } if (!function_exists('fetch_feed')){ include_once ABSPATH.WPINC.'/feed.php'; if (!function_exists('fetch_feed')) { wp_send_json_error(['message'=>'WP feed functions unavailable.']); return; } } $output = sumai_test_feeds($urls); wp_send_json_success(['message'=>'<pre>'.esc_html($output).'</pre>']); }

/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING (Restored)
 * ------------------------------------------------------------------------- */

function sumai_test_feeds(array $feed_urls): string { if (!function_exists('fetch_feed')) return "Error: fetch_feed unavailable."; $out = "--- Feed Test Results ---\nTime: ".wp_date('Y-m-d H:i:s T')."\n"; $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $out .= count($guids)." processed GUIDs.\n\n"; $new=false; $items_total=0; $new_total=0; foreach ($feed_urls as $i=>$url) { $out .= "--- Feed #".($i+1).": {$url} ---\n"; $feed = fetch_feed($url); if (is_wp_error($feed)) { $out .= "❌ Err: ".esc_html($feed->get_error_message())."\n\n"; continue; } $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); $count = count($items); $items_total += $count; if (empty($items)) { $out .= "⚠️ OK but no items.\n\n"; continue; } $out .= "✅ OK: {$count} items:\n"; $feed_new = false; foreach ($items as $idx => $item) { $guid = $item->get_id(true); $title = mb_strimwidth(strip_tags($item->get_title()?:'Untitled'),0,80,'...'); $out .= "- ".($idx+1).": ".esc_html($title)."\n"; if (isset($guids[$guid])) $out .= "  Status: Processed\n"; else { $out .= "  Status: ✨ NEW\n"; $feed_new=true; $new=true; $new_total++; } } if (!$feed_new && $count>0) $out .= "  ℹ️ No new items.\n"; $out .= "\n"; unset($feed,$items); } $out .= "--- Summary ---\nChecked ".count($feed_urls)." feeds, {$items_total} items.\n".($new?"✅ Detected {$new_total} NEW items.":"ℹ️ No new content."); return $out; }

/**
 * Reads the last N lines from a file.
 *
 * @param string $filepath Path to the file.
 * @param int    $lines    Number of lines to read from the tail.
 * @param int    $buffer   Buffer size for reading chunks.
 * @return array Array of lines or an array with a single error message.
 */
function sumai_read_log_tail(string $filepath, int $lines = 50, int $buffer = 4096): array {
    // Check if file exists and is readable
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return ['Error: Log file does not exist or is not readable.'];
    }

    // Handle empty file case
    if (filesize($filepath) === 0) {
        return [];
    }

    try {
        // Simple approach for small files or when we need all lines
        $content = file_get_contents($filepath);
        if ($content === false) {
            return ['Error: Failed to read log file.'];
        }
        
        // Split into lines, handling all line ending types
        $all_lines = preg_split('/\r\n|\r|\n/', rtrim($content));
        $total_lines = count($all_lines);
        
        // Return only the requested number of lines from the end
        return array_slice($all_lines, max(0, $total_lines - $lines), min($lines, $total_lines));
    } catch (\Throwable $e) {
        return ['Error: Exception during log reading - ' . esc_html($e->getMessage())];
    }
}

function sumai_get_debug_info(): array {
    $debug_info = []; $options = get_option(SUMAI_SETTINGS_OPTION, []); $debug_info['settings'] = $options; $debug_info['settings']['api_key'] = (defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY))?'*** Constant ***':(!empty($options['api_key'])?'*** DB Set ***':'*** Not Set ***');
    $cron_jobs = _get_cron_array()?:[]; $debug_info['cron_jobs'] = []; $has_sumai_jobs = false; foreach ($cron_jobs as $time => $hooks) { foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $hook_name) { if (isset($hooks[$hook_name])) { $has_sumai_jobs = true; $event_key = key($hooks[$hook_name]); $schedule_details = $hooks[$hook_name][$event_key]; $schedule_name = $schedule_details['schedule'] ?? '(One-off?)'; $interval = isset($schedule_details['interval']) ? ($schedule_details['interval'] . 's') : 'N/A'; $debug_info['cron_jobs'][$hook_name] = [ 'next_run_gmt' => gmdate('Y-m-d H:i:s', $time), 'next_run_site' => wp_date('Y-m-d H:i:s T', $time), 'schedule_name' => $schedule_name, 'interval' => $interval ]; } } } if (!$has_sumai_jobs) $debug_info['cron_jobs'] = 'No Sumai tasks scheduled.';
    $log_file = sumai_ensure_log_dir(); 
    $debug_info['log_file_path'] = $log_file ?: 'ERROR: Could not determine log path.'; // Changed error message
    $debug_info['log_readable'] = false;
    $debug_info['log_writable'] = false;
    $debug_info['log_size_kb'] = 'N/A';
    $debug_info['recent_logs'] = ['Log status unknown.']; // Default message

    if ($log_file) {
        // Check readability/writability using WP Filesystem for potentially better compatibility
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // Use WP_Filesystem methods if available
        if ($wp_filesystem) {
             $debug_info['log_readable'] = $wp_filesystem->is_readable($log_file);
             $debug_info['log_writable'] = $wp_filesystem->is_writable($log_file);
             if ($debug_info['log_readable'] && $wp_filesystem->exists($log_file)) {
                $debug_info['log_size_kb'] = round($wp_filesystem->size($log_file) / 1024, 2);
             } else {
                 $debug_info['log_size_kb'] = 'N/A';
             }
        } else {
            // Fallback to basic PHP checks if WP_Filesystem fails to initialize
            $debug_info['log_readable'] = is_readable($log_file);
            $debug_info['log_writable'] = is_writable($log_file);
            if ($debug_info['log_readable'] && file_exists($log_file)) {
                $debug_info['log_size_kb'] = round(filesize($log_file) / 1024, 2);
            } else {
                 $debug_info['log_size_kb'] = 'N/A';
            }
            $debug_info['recent_logs'][] = 'Note: WP_Filesystem init failed, using basic file checks.';
        }

        // Attempt to read logs only if readable
        if ($debug_info['log_readable']) {
            if ($debug_info['log_size_kb'] === 'N/A' || $debug_info['log_size_kb'] == 0) {
                 $debug_info['recent_logs'] = ['Log file is empty or size unknown.'];
            } else {
                // Use the robust tail function
                $debug_info['recent_logs'] = sumai_read_log_tail($log_file, 50);
            }
        } else {
            $debug_info['recent_logs'] = ['Log file is not readable. Check permissions.'];
        }
    } else {
         $debug_info['recent_logs'] = ['Could not determine log file path.'];
    }

    // --- Remove the old log reading logic --- 
    // if ($debug_info['log_readable']) {
    //    $log_content = ($debug_info['log_size_kb'] > 0) ? @file_get_contents($log_file, false, null, -10240) : '';
    //    $debug_info['recent_logs'] = ($log_content!==false)?array_slice(explode("\n", trim($log_content)), -50):['Error reading log.'];
    // } else {
    //    $debug_info['recent_logs'] = ['Log not readable.'];
    // }
    
    $processed_guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $debug_info['guids_count'] = count($processed_guids); $debug_info['guids_newest'] = empty($processed_guids)?'N/A':wp_date('Y-m-d H:i:s T', max($processed_guids)); $debug_info['guids_oldest'] = empty($processed_guids)?'N/A':wp_date('Y-m-d H:i:s T', min($processed_guids));
    global $wp_version; $wp_cron_disabled = defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON; $debug_info['system'] = ['plugin_v' => get_file_data(__FILE__, ['Version'=>'Version'])['Version'], 'php_v' => phpversion(), 'wp_v' => $wp_version, 'wp_tz' => wp_timezone_string(), 'wp_debug' => (defined('WP_DEBUG')&&WP_DEBUG?'Yes':'No'), 'wp_mem' => WP_MEMORY_LIMIT, 'wp_cron' => $wp_cron_disabled?'Disabled':'Enabled', 'server_time' => wp_date('Y-m-d H:i:s T'), 'openssl' => extension_loaded('openssl')?'Yes':'No', 'auth_key' => (defined('AUTH_KEY')&&AUTH_KEY?'Yes':'No'), 'curl' => extension_loaded('curl')?'Yes':'No', 'mbstring' => extension_loaded('mbstring')?'Yes':'No'];
    return $debug_info;
}

function sumai_render_debug_info() { $d = sumai_get_debug_info(); echo '<div><style>td{vertical-align:top;}pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:5px;border:1px solid #ccc;margin:0;font-size:12px;max-height:200px;overflow-y:auto;}</style><h3>Settings</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($d['settings'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(ucwords(str_replace('_',' ',$k))).'</td><td><pre>'.esc_html(is_array($v)?print_r($v,true):$v).'</pre></td></tr>'; echo '</tbody></table><h3>Processed Items</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Count</td><td>'.esc_html($d['guids_count']).'</td></tr><tr><td>Newest</td><td>'.esc_html($d['guids_newest']).'</td></tr><tr><td>Oldest</td><td>'.esc_html($d['guids_oldest']).' (TTL: '.esc_html(SUMAI_PROCESSED_GUID_TTL/DAY_IN_SECONDS).'d)</td></tr></tbody></table><h3>Scheduled Tasks</h3>'; if(is_array($d['cron_jobs'])&&!empty($d['cron_jobs'])){echo '<table class="wp-list-table fixed striped"><thead><tr><th>Hook</th><th>Next Run (Site)</th><th>Schedule</th></tr></thead><tbody>'; foreach($d['cron_jobs'] as $h=>$det) echo '<tr><td><code>'.esc_html($h).'</code></td><td>'.esc_html($det['next_run_site']).'</td><td>'.esc_html($det['schedule_name']).'</td></tr>'; echo '</tbody></table>';} else echo '<p>'.esc_html($d['cron_jobs']).'</p>'; echo '<h3>System Info</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($d['system'] as $k=>$v):?><tr><td width="30%"><strong><?=esc_html(ucwords(str_replace('_',' ',$k)))?></strong></td><td><?=wp_kses_post($v)?></td></tr><?php endforeach; echo '</tbody></table><h3>Logging</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Path</td><td><code>'.esc_html($d['log_file_path']).'</code></td></tr><tr><td>Status</td><td>'.($d['log_readable']?'Readable':'Not Readable').', '.($d['log_writable']?'Writable':'Not Writable').' (Size: '.esc_html($d['log_size_kb']).' KB)</td></tr></tbody></table><h4>Recent Logs (tail ~50)</h4><div style="max-height:400px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;border:1px solid #3c3c3c;padding:10px;font-family:monospace;white-space:pre-wrap;word-break:break-word;">'.esc_html(implode("\n",$d['recent_logs'])).'</div></div>'; }

function sumai_ensure_log_dir(): ?string { static $p=null,$c=false; if($c)return $p; $c=true; $u=wp_upload_dir(); if(!empty($u['error']))return null; $d=trailingslashit($u['basedir']).SUMAI_LOG_DIR_NAME; $f=trailingslashit($d).SUMAI_LOG_FILE_NAME; if(!is_dir($d)){if(!wp_mkdir_p($d))return null; @file_put_contents($d.'/.htaccess',"Options -Indexes\nDeny from all"); @file_put_contents($d.'/index.php','<?php // Silence');} if(!is_writable($d))return null; if(!file_exists($f)){if(false===@file_put_contents($f,''))return null; @chmod($f,0644);} if(!is_writable($f))return null; return $p=$f; }

function sumai_log_event(string $msg, bool $is_error=false) { $file=sumai_ensure_log_dir(); if(!$file){error_log("Sumai ".($is_error?'[ERR]':'[INFO]')."(Log N/A): ".$msg); return;} $ts=wp_date('Y-m-d H:i:s T'); $lvl=$is_error?' [ERROR] ':' [INFO]  '; $line='['.$ts.']'.$lvl.trim(preg_replace('/\s+/',' ',wp_strip_all_tags($msg))).PHP_EOL; @file_put_contents($file,$line,FILE_APPEND|LOCK_EX); }

function sumai_prune_logs() { $file=sumai_ensure_log_dir(); if(!$file||!is_readable($file)||!is_writable($file)) return; $lines=@file($file,4|2); if(empty($lines))return; $cutoff=time()-SUMAI_LOG_TTL; $keep=[]; $pruned=0; foreach($lines as $line){$ts=false; if(preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/',$line,$m)){try{$dt=new DateTime($m[1]);$ts=$dt->getTimestamp();}catch(Exception $e){$ts=strtotime($m[1]);}} if($ts!==false&&$ts>=$cutoff)$keep[]=$line; else $pruned++;} if($pruned>0){$new_content=empty($keep)?'':implode(PHP_EOL,$keep).PHP_EOL; @file_put_contents($file,$new_content,LOCK_EX); } }

/* -------------------------------------------------------------------------
 * 10.1 STATUS TRACKING
 * ------------------------------------------------------------------------- */

/**
 * Updates the generation status with a new message.
 *
 * @param string $status_id Unique ID for this generation process
 * @param string $message Status message
 * @param string $status Status type (pending, processing, complete, error)
 * @param array $data Additional data to store with the status
 * @return bool Success or failure
 */
function sumai_update_status(string $status_id, string $message, string $status = 'processing', array $data = []): bool {
    $transient_name = SUMAI_STATUS_TRANSIENT . $status_id;
    $status_data = [
        'message' => $message,
        'status' => $status,
        'timestamp' => current_time('timestamp'),
        'data' => $data
    ];
    
    // Also log the status update
    sumai_log_event("Status [{$status_id}]: {$status} - {$message}");
    
    // Store for 1 hour
    return set_transient($transient_name, $status_data, HOUR_IN_SECONDS);
}

/**
 * Gets the current generation status.
 *
 * @param string $status_id Unique ID for this generation process
 * @return array|false Status data or false if not found
 */
function sumai_get_status(string $status_id) {
    $transient_name = SUMAI_STATUS_TRANSIENT . $status_id;
    return get_transient($transient_name);
}

/**
 * Generates a unique status ID.
 *
 * @return string Unique status ID
 */
function sumai_generate_status_id(): string {
    return uniqid('sumai_', true);
}

/**
 * Clears a status when it's no longer needed.
 *
 * @param string $status_id Unique ID for this generation process
 * @return bool Success or failure
 */
function sumai_clear_status(string $status_id): bool {
    $transient_name = SUMAI_STATUS_TRANSIENT . $status_id;
    return delete_transient($transient_name);
}

/* -------------------------------------------------------------------------
 * 11. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */