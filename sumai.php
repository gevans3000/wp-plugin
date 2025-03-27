<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Automatically fetches and summarizes the latest RSS feed articles using OpenAI gpt-4o-mini, then publishes a single daily "Daily Summary" post.
 * Version:     1.1.6
 * Author:      biglife360.com
 * Author URI:  https://biglife360.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sumai
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

// Exit if accessed directly.
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

/* -------------------------------------------------------------------------
 * 1. ACTIVATION & DEACTIVATION HOOKS
 * ------------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'sumai_activate' );
register_deactivation_hook( __FILE__, 'sumai_deactivate' );

/**
 * Plugin Activation Handler.
 */
function sumai_activate() {
    $defaults = array(
        'feed_urls'      => '',
        'context_prompt' => "Summarize the key points from the following articles concisely.",
        'title_prompt'   => "Generate a compelling and unique title for this daily news summary.",
        'api_key'        => '',
        'draft_mode'     => 0,
        'schedule_time'  => '03:00',
        'post_signature' => '',
    );
    // Add option only if it doesn't exist
    add_option( SUMAI_SETTINGS_OPTION, $defaults, '', 'no' );

    sumai_ensure_log_dir();
    sumai_schedule_daily_event();

    // Setup weekly token rotation if not already scheduled
    if ( ! wp_next_scheduled( SUMAI_ROTATE_TOKEN_HOOK ) ) {
        wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK );
    }
    // Generate initial token if it doesn't exist
    if ( ! get_option( SUMAI_CRON_TOKEN_OPTION ) ) {
        sumai_rotate_cron_token(); // Call directly to generate initial token
    }

    // Setup daily log pruning if not already scheduled
    if ( ! wp_next_scheduled( SUMAI_PRUNE_LOGS_HOOK ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK );
    }
    sumai_log_event( 'Plugin activated. Version: ' . get_file_data(__FILE__, ['Version' => 'Version'])['Version'] );
}

/**
 * Plugin Deactivation Handler.
 */
function sumai_deactivate() {
    wp_clear_scheduled_hook( SUMAI_CRON_HOOK );
    wp_clear_scheduled_hook( SUMAI_ROTATE_TOKEN_HOOK );
    wp_clear_scheduled_hook( SUMAI_PRUNE_LOGS_HOOK );
    sumai_log_event( 'Plugin deactivated. Cron jobs cleared.' );
    // Note: Settings, token, and logs are intentionally kept on deactivation by default.
}

/* -------------------------------------------------------------------------
 * 2. CRON SCHEDULING & HOOKS
 * ------------------------------------------------------------------------- */

/**
 * Schedule the main daily summary event based on settings.
 */
function sumai_schedule_daily_event() {
    wp_clear_scheduled_hook( SUMAI_CRON_HOOK ); // Clear existing first

    $options = get_option( SUMAI_SETTINGS_OPTION, array() );
    $schedule_time_str = '03:00'; // Default
    if ( isset( $options['schedule_time'] ) && preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $options['schedule_time'] ) ) {
        $schedule_time_str = $options['schedule_time'];
    }

    // Use wp_date to get timestamp in site's timezone correctly
    $scheduled_time_today_str = date('Y-m-d') . ' ' . $schedule_time_str . ':00';
    $site_timezone = wp_timezone(); // Get site timezone object
    $now_timestamp = current_time( 'timestamp', true ); // Get current time as GMT timestamp

    try {
        // Create DateTime object in site's timezone
        $scheduled_dt_today = new DateTime( $scheduled_time_today_str, $site_timezone );
        $scheduled_timestamp_today_gmt = $scheduled_dt_today->getTimestamp(); // Get timestamp (always UTC/GMT)

        // If scheduled time for today has passed (in GMT), schedule for tomorrow same time
        if ( $scheduled_timestamp_today_gmt <= $now_timestamp ) {
            $scheduled_dt_today->modify('+1 day');
            $first_run_gmt = $scheduled_dt_today->getTimestamp();
        } else {
            $first_run_gmt = $scheduled_timestamp_today_gmt;
        }

        wp_schedule_event( $first_run_gmt, 'daily', SUMAI_CRON_HOOK );
        sumai_log_event( 'Daily summary event scheduled. Next run approx: ' . wp_date( 'Y-m-d H:i:s T', $first_run_gmt ) );

    } catch ( Exception $e ) {
         sumai_log_event( 'Error calculating schedule time timestamp: ' . $e->getMessage() . '. Using fallback.', true );
         // Fallback: Schedule for 3 AM GMT tomorrow if calculation fails
         $first_run_gmt = strtotime('tomorrow 03:00', $now_timestamp);
         wp_schedule_event( $first_run_gmt, 'daily', SUMAI_CRON_HOOK );
         sumai_log_event( 'Daily summary event scheduled using fallback. Next run approx: ' . wp_date( 'Y-m-d H:i:s T', $first_run_gmt ) );
    }
}

/**
 * Rotates the secure token used for external cron triggers.
 */
function sumai_rotate_cron_token() {
    // Generate a new secure random token
    $new_token = bin2hex( random_bytes( 16 ) );
    update_option( SUMAI_CRON_TOKEN_OPTION, $new_token );
    sumai_log_event( 'Cron security token rotated.' );
}

// Reschedule when settings are updated
add_action( 'update_option_' . SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event', 10, 0 );
// Hook the wrapper function to the cron event for reliable dependency loading
add_action( SUMAI_CRON_HOOK, 'sumai_run_daily_summary_hook' );
// Hook token rotation and log pruning
add_action( SUMAI_ROTATE_TOKEN_HOOK, 'sumai_rotate_cron_token' );
add_action( SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs' );

/* -------------------------------------------------------------------------
 * 3. EXTERNAL CRON TRIGGER
 * ------------------------------------------------------------------------- */

add_action( 'init', 'sumai_check_external_trigger', 5 ); // Run early on init

/**
 * Check for external cron trigger requests. Validates token and calls the hook wrapper.
 */
function sumai_check_external_trigger() {
    // Check only if the specific parameters are set
    if ( ! isset( $_GET['sumai_trigger'], $_GET['token'] ) || $_GET['sumai_trigger'] !== '1' ) {
        return;
    }

    $provided_token = sanitize_text_field( $_GET['token'] );
    $stored_token = get_option( SUMAI_CRON_TOKEN_OPTION );

    // Use hash_equals for timing-attack safe comparison
    if ( $stored_token && hash_equals( $stored_token, $provided_token ) ) {
        sumai_log_event( 'External cron trigger received and validated.' );

        // Prevent multiple rapid runs via external trigger using a transient lock
        $lock_transient_key = 'sumai_external_trigger_lock';
        if ( false === get_transient( $lock_transient_key ) ) {
            // Set lock for 5 minutes
            set_transient( $lock_transient_key, time(), MINUTE_IN_SECONDS * 5 );
            sumai_log_event( 'Executing summary generation via external trigger (calling wrapper)...' );

            // *** Call the wrapper function directly to ensure dependencies are loaded ***
            sumai_run_daily_summary_hook();

            // Optional: Exit after processing if this URL is *only* for the trigger
            // Clean exit after successful processing can prevent further WP loading.
            // exit('Sumai trigger processed successfully.');
        } else {
            sumai_log_event( 'External cron trigger skipped, process locked (ran recently).', true );
            // Optional: Exit if skipped
            // wp_send_json_error('Trigger skipped, ran recently.', 429); // 429 Too Many Requests
        }
    } else {
        sumai_log_event( 'Invalid external cron trigger token received.', true );
         // Optional: Exit with error - send a 403 Forbidden header
         // status_header(403);
         // exit('Invalid token.');
         // Or use wp_send_json_error for consistency if expecting JSON response
         // wp_send_json_error('Invalid token.', 403);
    }
     // If you uncommented an exit/wp_send_json_error above, this might not be reached.
     // If no exit is used, WordPress continues loading.
}

/* -------------------------------------------------------------------------
 * 4. SUMMARY GENERATION PROCESS
 * ------------------------------------------------------------------------- */

 /**
 * Wrapper function triggered by WP-Cron OR the external trigger.
 * Ensures necessary admin files (like post.php) are loaded before generation.
 */
function sumai_run_daily_summary_hook() {
    sumai_log_event( 'Hook triggered: sumai_run_daily_summary_hook. Ensuring admin functions available...' );

    // --- FORCE LOAD ADMIN FILES if needed ---
    // Check if a core function from post.php exists. If not, attempt to load it.
    if ( ! function_exists( 'wp_insert_post' ) ) {
        $post_file_path = ABSPATH . 'wp-admin/includes/post.php';
        sumai_log_event( 'Function wp_insert_post() not found. Attempting to load: ' . $post_file_path );

        if ( file_exists( $post_file_path ) ) {
            require_once $post_file_path;
            // Verify loading was successful
            if ( ! function_exists( 'wp_insert_post' ) ) {
                sumai_log_event( 'FATAL: FAILED to make functions available from wp-admin/includes/post.php after require_once. Check file permissions or WordPress integrity.', true );
                return; // Stop execution
            } else {
                 sumai_log_event( 'Successfully loaded wp-admin/includes/post.php.' );
            }
        } else {
             sumai_log_event( 'FATAL: Could not find file wp-admin/includes/post.php. Cannot proceed.', true );
             return; // Stop execution
        }
    } else {
         sumai_log_event( 'Function wp_insert_post() already exists. Assuming admin files loaded.' );
    }
    // --- END FORCE LOAD ---

    // Proceed with the main generation task
    sumai_log_event( 'Dependencies checked/loaded. Calling sumai_generate_daily_summary()...' );
    sumai_generate_daily_summary();
}


/**
 * Main function to generate the daily summary post. Fetches feeds, calls API, creates post.
 * Assumes necessary dependencies (like wp_unique_post_title) are loaded by the calling context
 * (sumai_run_daily_summary_hook for cron/external, or admin context for manual trigger).
 *
 * @param bool $force_fetch If true, ignores processed GUID check (for manual trigger/testing).
 * @return int|false Post ID on success, false on failure.
 */
function sumai_generate_daily_summary( bool $force_fetch = false ) {
    sumai_log_event( 'Starting main generation: sumai_generate_daily_summary()' . ($force_fetch ? ' (Forced Fetch)' : '') );

    $options = get_option( SUMAI_SETTINGS_OPTION, array() );
    $api_key = sumai_get_api_key(); // Handles constant override and decryption

    // --- Pre-checks ---
    if ( empty( $api_key ) ) {
        sumai_log_event( 'Error: OpenAI API key is missing or invalid. Cannot generate summary.', true );
        return false;
    }
    $feed_urls_raw = $options['feed_urls'] ?? '';
    if ( empty( $feed_urls_raw ) ) {
        sumai_log_event( 'Error: No RSS feed URLs configured in settings.', true );
        return false;
    }
    $feed_urls = array_slice( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $feed_urls_raw ) ) ), 0, SUMAI_MAX_FEED_URLS );
    if ( empty( $feed_urls ) ) {
        sumai_log_event( 'Error: No valid RSS feed URLs found after filtering.', true );
        return false;
    }
    sumai_log_event( 'Configuration checks passed. Using ' . count($feed_urls) . ' feed(s).' );
    // --- End Pre-checks ---

    try {
        // --- Fetch New Content ---
        sumai_log_event( 'Fetching new article content...' );
        list( $new_content, $processed_guids_updates ) = sumai_fetch_new_articles_content( $feed_urls, $force_fetch );

        if ( empty( $new_content ) ) {
            sumai_log_event( 'No new, unprocessed content found in feeds. Summary generation skipped.' );
            return false; // Not an error, just nothing new to process
        }
        sumai_log_event( 'Fetched ' . mb_strlen( $new_content ) . ' characters of new content for summarization.' );
        // --- End Fetch New Content ---

        // --- Generate Summary & Title ---
        sumai_log_event( 'Generating summary and title via OpenAI...' );
        $context_prompt = $options['context_prompt'] ?? '';
        $title_prompt = $options['title_prompt'] ?? '';
        $summary_result = sumai_summarize_text( $new_content, $context_prompt, $title_prompt, $api_key );

        // Explicitly release potentially large variable early
        unset($new_content);

        if ( ! $summary_result || empty( $summary_result['content'] ) || empty( $summary_result['title'] ) ) {
            sumai_log_event( 'Error: Failed to generate summary or title from OpenAI API call.', true );
            // $summary_result might be false or an array with missing keys, already logged in sumai_summarize_text
            return false;
        }
        sumai_log_event( 'Summary and title generated successfully by OpenAI.' );
        // --- End Generate Summary & Title ---

        // --- Create Post ---
        sumai_log_event( 'Preparing to create WordPress post...' );
        $draft_mode = isset( $options['draft_mode'] ) ? (int) $options['draft_mode'] : 0;

        // Critical Check: Ensure required functions exist before calling them.
        // This should have been handled by sumai_run_daily_summary_hook, but check as a safeguard.
        if ( ! function_exists( 'wp_unique_post_title' ) || ! function_exists( 'wp_insert_post' ) ) {
             sumai_log_event( 'CRITICAL ERROR: wp_unique_post_title() or wp_insert_post() not defined! Dependency loading failed earlier. Aborting post creation.', true );
             return false;
        }

        // Ensure title uniqueness
        // Remove potential quotes often added by AI models around the title
        $clean_title = trim( $summary_result['title'], '"\' ' );
        $unique_title = wp_unique_post_title( $clean_title );
        if ($unique_title !== $clean_title) {
             sumai_log_event( "Post title adjusted for uniqueness: '{$clean_title}' -> '{$unique_title}'" );
        }

        // Determine post author
        $author_id = 1; // Default to admin user ID 1 for cron/non-interactive contexts
        if ( is_user_logged_in() ) {
            $current_user_id = get_current_user_id();
            if ( $current_user_id > 0 ) {
                 $author_id = $current_user_id; // Use logged-in user if available (e.g., manual trigger)
            }
        }
        // Verify the chosen author ID exists and can publish
        $author_data = get_userdata($author_id);
        if ( ! $author_data || ! $author_data->has_cap('publish_posts') ) {
             sumai_log_event( "Warning: Determined author ID {$author_id} is invalid or lacks 'publish_posts' capability. Trying admin ID 1.", true );
             $author_id = 1; // Fallback definitively to ID 1
             $author_data = get_userdata($author_id);
              if ( ! $author_data || ! $author_data->has_cap('publish_posts') ) {
                  sumai_log_event( 'Error: Author ID 1 is invalid or lacks publish capability. Cannot create post.', true );
                  return false;
              }
        }
        sumai_log_event( "Using author ID: {$author_id} for post creation." );


        $post_data = array(
            'post_title'    => $unique_title,
            'post_content'  => $summary_result['content'], // Raw summary content from API
            'post_status'   => $draft_mode ? 'draft' : 'publish',
            'post_type'     => 'post',
            'post_author'   => $author_id,
            // Add post meta to easily identify Sumai-generated posts
            'meta_input'    => [ '_sumai_generated' => true ]
        );

        // Insert the post
        $post_id = wp_insert_post( $post_data, true ); // Pass true to return WP_Error on failure

        if ( is_wp_error( $post_id ) ) {
            sumai_log_event( "Error creating WordPress post: " . $post_id->get_error_message(), true );
            return false;
        }

        sumai_log_event( "Successfully created post ID: {$post_id} with status '{$post_data['post_status']}'." );
        // --- End Create Post ---

        // --- Update Processed GUIDs ---
        if ( ! empty( $processed_guids_updates ) ) {
            $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, array() );
            // Merge new GUIDs with existing ones
            $processed_guids = array_merge( $processed_guids, $processed_guids_updates );

            // Prune old entries immediately after adding new ones
            $current_time = time();
            $pruned_count = 0;
            foreach ( $processed_guids as $guid => $timestamp ) {
                if ( $timestamp < ( $current_time - SUMAI_PROCESSED_GUID_TTL ) ) {
                    unset( $processed_guids[ $guid ] );
                    $pruned_count++;
                }
            }
            update_option( SUMAI_PROCESSED_GUIDS_OPTION, $processed_guids );
            sumai_log_event( "Updated processed items list. Added: " . count($processed_guids_updates) . ". Pruned: {$pruned_count}. Total tracked: " . count( $processed_guids ) );
        }
        // --- End Update Processed GUIDs ---

        return $post_id; // Return the new post ID on success

    } catch ( \Throwable $e ) {
        // Catch any unexpected fatal errors/exceptions during the process
        sumai_log_event( "FATAL ERROR during summary generation: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine(), true );
        // Optionally log stack trace $e->getTraceAsString() but can be verbose
        return false;
    }
}


/* -------------------------------------------------------------------------
 * 5. FEED FETCHING & PROCESSING
 * ------------------------------------------------------------------------- */

/**
 * Fetches content from new articles across multiple RSS feeds.
 *
 * @param array $feed_urls Array of feed URLs.
 * @param bool  $force_fetch If true, includes content even if GUID was recently processed.
 * @return array Returns [$combined_content, $newly_processed_guids].
 */
function sumai_fetch_new_articles_content( array $feed_urls, bool $force_fetch = false ): array {
    // Ensure WordPress feed functions are available
    if ( ! function_exists( 'fetch_feed' ) ) {
        include_once ABSPATH . WPINC . '/feed.php';
        if ( ! function_exists( 'fetch_feed' ) ) {
            sumai_log_event( 'Error: fetch_feed() function unavailable after including feed.php.', true );
            return ['', []];
        }
    }

    $combined_content = '';
    $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, array() );
    $newly_processed_guids = array();
    $current_time = time();
    $content_char_count = 0;

    foreach ( $feed_urls as $url ) {
        $url = esc_url_raw( trim( $url ) );
        if ( empty( $url ) ) continue;

        sumai_log_event( "Processing feed: {$url}" );
        // Fetch the feed (WordPress handles caching)
        $feed = fetch_feed( $url );

        if ( is_wp_error( $feed ) ) {
            sumai_log_event( "Error fetching feed {$url}: " . $feed->get_error_message(), true );
            continue;
        }

        $items = $feed->get_items( 0, SUMAI_FEED_ITEM_LIMIT );
        $item_count = count( $items );

        if ( empty( $items ) ) {
            sumai_log_event( "No items found in feed: {$url}" );
            continue;
        }

        sumai_log_event( "Found {$item_count} items in feed: {$url} (Limit: " . SUMAI_FEED_ITEM_LIMIT . ")" );
        $added_from_feed = 0;
        foreach ( $items as $item ) {
            $guid = $item->get_id( true ); // Get unique ID for the item

            // Skip if already processed unless forcing fetch, or if already added in this run
            if ( isset( $newly_processed_guids[ $guid ] ) ) continue;
            if ( ! $force_fetch && isset( $processed_guids[ $guid ] ) ) continue;


            $title = strip_tags( $item->get_title() ?: 'Untitled' );
            $content = $item->get_content();
            $description = $item->get_description();

            // Prefer content, fallback to description, then strip tags and normalize whitespace
            $text_content = wp_strip_all_tags( ! empty( $content ) ? $content : $description );
            $text_content = trim( preg_replace( '/\s+/', ' ', $text_content ) );

            if ( ! empty( $text_content ) ) {
                $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST); // Use feed title or host name
                $item_header = "Source: " . esc_html($feed_title) . "\n";
                $item_header .= "Title: " . esc_html($title) . "\n";
                $item_body = "Content:\n" . $text_content . "\n\n---\n\n";

                $item_full_content = $item_header . $item_body;
                $item_char_count = mb_strlen($item_full_content);

                // Check if adding this item exceeds the overall character limit for the API
                if (($content_char_count + $item_char_count) > SUMAI_MAX_INPUT_CHARS) {
                    sumai_log_event("Skipping item '{$title}' from {$feed_title} - adding it would exceed max input characters ({$content_char_count} + {$item_char_count} > " . SUMAI_MAX_INPUT_CHARS . ").");
                    // Stop processing items from *this feed* once limit is about to be hit
                    // Could also break the outer loop entirely if preferred.
                    break;
                }

                // Add item content to the combined string
                $combined_content .= $item_full_content;
                $content_char_count += $item_char_count;
                $newly_processed_guids[ $guid ] = $current_time; // Mark GUID as processed in this run
                $added_from_feed++;
            }
        }
         sumai_log_event("Added {$added_from_feed} new items from feed {$url}. Total chars: {$content_char_count}");

        // Explicitly unset feed object to potentially free memory sooner
        unset( $feed, $items );
    }

    return [ $combined_content, $newly_processed_guids ];
}


/* -------------------------------------------------------------------------
 * 6. OPENAI SUMMARIZATION & API
 * ------------------------------------------------------------------------- */

/**
 * Summarizes text and generates a title using OpenAI API.
 *
 * @param string $text Text to summarize.
 * @param string $context_prompt Context for the summary.
 * @param string $title_prompt Context for the title.
 * @param string $api_key OpenAI API Key.
 * @return array|false Array with 'title' and 'content' keys, or false on failure.
 */
function sumai_summarize_text( string $text, string $context_prompt, string $title_prompt, string $api_key ): ?array {
    if ( empty( $text ) ) {
        sumai_log_event( 'Error: Empty text provided for summarization.', true );
        return null;
    }

    // Input length already checked during feed fetching, but double-check is safe.
    if ( mb_strlen( $text ) > SUMAI_MAX_INPUT_CHARS ) {
        $text = mb_substr( $text, 0, SUMAI_MAX_INPUT_CHARS );
        sumai_log_event( 'Warning: Input text re-truncated to ' . SUMAI_MAX_INPUT_CHARS . ' chars before API call.' );
    }

    // Define default prompts if settings are empty
    $default_context = "Focus on the main points and key information.";
    $default_title = "Create a compelling title reflecting the summary's content.";

    $messages = [];
    $messages[] = [
        'role' => 'system',
        'content' => "You are an expert summarizer. Generate a concise summary and a unique title based on the provided text from multiple articles. "
                   . "Summary Context: " . ($context_prompt ?: $default_context) . " "
                   . "Title Context: " . ($title_prompt ?: $default_title) . " "
                   . "Output format MUST be valid JSON like this: {\"title\": \"Generated Title\", \"summary\": \"Generated Summary Content\"}"
    ];
    $messages[] = [ 'role' => 'user', 'content' => "Text to summarize:\n\n" . $text ];

    $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    $request_body = array(
        'model' => 'gpt-4o-mini', // Use the specified efficient model
        'messages' => $messages,
        'max_tokens' => 1500,    // Max tokens for the *output* (summary + title)
        'temperature' => 0.6,    // Balance creativity and focus
        'response_format' => [ 'type' => 'json_object' ] // Enforce JSON output
    );

    $request_args = array(
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode( $request_body ),
        'method' => 'POST',
        'timeout' => 90, // Timeout for the API request in seconds
    );

    sumai_log_event( 'Sending request to OpenAI API (model: gpt-4o-mini)...' );
    $response = wp_remote_post( $api_endpoint, $request_args );

    // --- Handle API Response ---
    if ( is_wp_error( $response ) ) {
        sumai_log_event( 'OpenAI API WP Error: ' . $response->get_error_message(), true );
        return null;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    if ( $status_code !== 200 ) {
        sumai_log_event( "OpenAI API HTTP Error: Status {$status_code}. Body: " . $response_body, true );
        return null;
    }

    sumai_log_event( 'OpenAI API request successful (Status: 200).' );
    $data = json_decode( $response_body, true );

    // Validate response structure and content
    if ( ! isset( $data['choices'][0]['message']['content'] ) || ! is_string($data['choices'][0]['message']['content']) ) {
        sumai_log_event( 'Error: Invalid OpenAI API response structure (missing/invalid content). Body: ' . $response_body, true );
        return null;
    }

    $json_content_string = $data['choices'][0]['message']['content'];
    $parsed_content = json_decode( $json_content_string, true );

    // Check JSON decoding and presence of required keys
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($parsed_content) || empty( $parsed_content['title'] ) || !isset( $parsed_content['summary'] ) ) { // Allow empty summary if intended
         sumai_log_event( 'Error: Failed to parse valid JSON object with required title/summary from OpenAI response. Raw content string: ' . $json_content_string, true );
         return null;
    }

    // Return the extracted title and summary (sanitized later before DB insert)
    return array(
        'title'   => trim( $parsed_content['title'] ),
        'content' => trim( $parsed_content['summary'] ), // Allow potentially empty summary
    );
}

/**
 * Retrieves and decrypts the OpenAI API key from settings or constant.
 * Caches the result per request for efficiency.
 */
function sumai_get_api_key(): string {
    static $cached_key = null;

    // Return cached result if already processed in this request
    if ( $cached_key !== null ) {
        return $cached_key;
    }

    // 1. Prioritize constant defined in wp-config.php
    if ( defined( 'SUMAI_OPENAI_API_KEY' ) && ! empty( SUMAI_OPENAI_API_KEY ) ) {
        sumai_log_event( 'Using API Key from constant SUMAI_OPENAI_API_KEY.' );
        $cached_key = SUMAI_OPENAI_API_KEY;
        return $cached_key;
    }

    // 2. Fallback to database option
    $options = get_option( SUMAI_SETTINGS_OPTION );
    $encrypted_key = $options['api_key'] ?? '';

    if ( empty( $encrypted_key ) ) {
        $cached_key = ''; // Cache empty result
        return '';
    }

    // 3. Decrypt the key from the database
    // Check if necessary functions/constants are available
    if ( ! function_exists('openssl_decrypt') || ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
         sumai_log_event( 'Cannot decrypt API key from DB: OpenSSL extension missing or AUTH_KEY not defined in wp-config.php.', true );
         $cached_key = '';
         return '';
    }

    // Decode base64
    $decoded = base64_decode( $encrypted_key, true );
    if ($decoded === false) {
        sumai_log_event( 'Failed to base64 decode API key from DB.', true );
        $cached_key = '';
        return '';
    }

    // Decrypt using AES-256-CBC
    $cipher = 'aes-256-cbc';
    $ivlen = openssl_cipher_iv_length( $cipher );
     if ($ivlen === false || strlen($decoded) <= $ivlen) {
        sumai_log_event( 'Invalid encrypted API key format from DB (IV length check failed). Possible corruption or old format.', true );
        $cached_key = '';
        return '';
    }

    $iv = substr( $decoded, 0, $ivlen );
    $ciphertext_raw = substr( $decoded, $ivlen );
    // Use WordPress AUTH_KEY directly for decryption
    $decryption_key = AUTH_KEY;
    $decrypted = openssl_decrypt( $ciphertext_raw, $cipher, $decryption_key, OPENSSL_RAW_DATA, $iv );

    if ( $decrypted === false ) {
        sumai_log_event( 'Failed to decrypt API key from DB. Check if AUTH_KEY has changed or if the key was saved correctly.', true );
        $cached_key = '';
        return '';
    }

    // Cache the successfully decrypted key for this request
    sumai_log_event( 'Successfully decrypted API Key from database.' );
    $cached_key = $decrypted;
    return $cached_key;
}

/**
 * Validates the OpenAI API Key format and via a simple API call.
 */
function sumai_validate_api_key( string $api_key ): bool {
    if ( empty( $api_key ) ) {
         sumai_log_event( 'API Key validation skipped: Key is empty.' );
         return false;
     }

     // Basic format check (OpenAI keys typically start with 'sk-')
     if (strpos($api_key, 'sk-') !== 0) {
         sumai_log_event( 'API Key validation failed: Invalid format (does not start with sk-).', true );
         return false;
     }

    // Test connectivity and authentication by listing models
    $response = wp_remote_get( 'https://api.openai.com/v1/models', [
        'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
        'timeout' => 15, // Short timeout for validation
    ]);

    if ( is_wp_error( $response ) ) {
        sumai_log_event( 'API Key Validation WP Error during HTTP request: ' . $response->get_error_message(), true );
        return false;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code === 200 ) {
        sumai_log_event( 'API Key validation successful via API call.' );
        return true;
    } else {
        // Log specific error if possible
        $response_body = wp_remote_retrieve_body( $response );
        $error_message = "Status {$status_code}.";
        $response_data = json_decode($response_body, true);
        if (isset($response_data['error']['message'])) {
            $error_message .= " OpenAI Error: " . $response_data['error']['message'];
        } else {
             $error_message .= " Body: " . $response_body;
        }
        sumai_log_event( "API Key validation failed via API call: " . $error_message, true );
        return false;
    }
}

/* -------------------------------------------------------------------------
 * 7. POST SIGNATURE
 * ------------------------------------------------------------------------- */

/**
 * Appends the configured signature to the post content on the frontend.
 * Uses wp_kses_post for safe HTML.
 */
function sumai_append_signature_to_content( $content ) {
    // Check if it's a single post view, not admin, in the main loop, and the main query
    if ( is_singular( 'post' ) && ! is_admin() && in_the_loop() && is_main_query() ) {
         $options = get_option( SUMAI_SETTINGS_OPTION, array() );
         $signature = $options['post_signature'] ?? '';
         $signature = trim( $signature );

         // Only append if signature is not empty and not already present (basic check)
         if ( ! empty( $signature ) ) {
             // Check if the exact signature HTML (after kses) is already there to prevent duplicates more reliably
             $signature_html = wp_kses_post( $signature );
             if ( strpos( $content, $signature_html ) === false ) {
                  $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . $signature_html;
             }
         }
    }
    return $content;
}
add_filter( 'the_content', 'sumai_append_signature_to_content', 99 ); // High priority to run late

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', 'sumai_add_admin_menu' );
add_action( 'admin_init', 'sumai_register_settings' );

function sumai_add_admin_menu() {
    add_options_page(
        __( 'Sumai Settings', 'sumai' ), // Page Title
        __( 'Sumai', 'sumai' ),          // Menu Title
        'manage_options',                // Capability
        'sumai-settings',                // Menu Slug
        'sumai_render_settings_page'     // Callback function
    );
}

function sumai_register_settings() {
    register_setting(
        'sumai_options_group',           // Option group
        SUMAI_SETTINGS_OPTION,           // Option name
        'sumai_sanitize_settings'        // Sanitize callback
    );
}

/**
 * Sanitize settings before saving. Handles API key encryption/decryption/clearing.
 */
function sumai_sanitize_settings( $input ): array {
    $sanitized_input = array();
    $current_settings = get_option( SUMAI_SETTINGS_OPTION, array() );
    $current_encrypted = $current_settings['api_key'] ?? '';

    // --- Feed URLs ---
    if ( isset( $input['feed_urls'] ) ) {
        $urls = array_map( 'trim', preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $input['feed_urls'] ) ) );
        $valid_urls = [];
        $invalid_detected = false;
        foreach ($urls as $url) {
             if ( empty($url) ) continue;
             // Use filter_var with flag requiring scheme for better validation
             if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) {
                 $valid_urls[] = $url;
             } else {
                 $invalid_detected = true;
                 sumai_log_event("Removed invalid feed URL during save: {$url}");
             }
        }
        if ($invalid_detected) {
             add_settings_error( SUMAI_SETTINGS_OPTION, 'invalid_feed_url', __( 'One or more invalid feed URLs were detected and removed.', 'sumai' ), 'warning' );
        }
        // Enforce limit
        if ( count( $valid_urls ) > SUMAI_MAX_FEED_URLS ) {
            $valid_urls = array_slice( $valid_urls, 0, SUMAI_MAX_FEED_URLS );
            add_settings_error( SUMAI_SETTINGS_OPTION, 'feed_limit_exceeded', sprintf( __( 'Only the first %d valid feed URLs were saved (limit reached).', 'sumai' ), SUMAI_MAX_FEED_URLS ), 'warning' );
        }
        $sanitized_input['feed_urls'] = implode( "\n", $valid_urls );
    } else {
        $sanitized_input['feed_urls'] = $current_settings['feed_urls'] ?? '';
    }

    // --- Prompts ---
    $sanitized_input['context_prompt'] = isset( $input['context_prompt'] ) ? sanitize_textarea_field( $input['context_prompt'] ) : '';
    $sanitized_input['title_prompt']   = isset( $input['title_prompt'] ) ? sanitize_textarea_field( $input['title_prompt'] ) : '';

    // --- Schedule Time ---
    if ( isset( $input['schedule_time'] ) ) {
         $time = sanitize_text_field( $input['schedule_time'] );
         if (preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time )) {
             $sanitized_input['schedule_time'] = $time;
         } else {
             $sanitized_input['schedule_time'] = $current_settings['schedule_time'] ?? '03:00'; // Revert if invalid
             add_settings_error( SUMAI_SETTINGS_OPTION, 'invalid_schedule_time', __( 'Invalid schedule time format (HH:MM). Reverted to previous or default value.', 'sumai' ), 'warning' );
         }
    } else {
        $sanitized_input['schedule_time'] = $current_settings['schedule_time'] ?? '03:00';
    }

    // --- Draft Mode ---
    $sanitized_input['draft_mode'] = ( isset( $input['draft_mode'] ) && $input['draft_mode'] == '1' ) ? 1 : 0;

    // --- Post Signature ---
    // wp_kses_post allows safe HTML suitable for post content
    $sanitized_input['post_signature'] = isset( $input['post_signature'] ) ? wp_kses_post( $input['post_signature'] ) : '';

    // --- API Key ---
    if ( isset( $input['api_key'] ) ) {
        $new_api_key_input = sanitize_text_field( trim( $input['api_key'] ) );
        $is_placeholder = ($new_api_key_input === '********************');

        if ( $is_placeholder ) {
            // User submitted the placeholder - means no change intended
            $sanitized_input['api_key'] = $current_encrypted;
        } elseif ( empty( $new_api_key_input ) ) {
            // User submitted an empty field - clear the key
            if (!empty($current_encrypted)) {
                 add_settings_error( SUMAI_SETTINGS_OPTION, 'api_key_cleared', __( 'API key cleared.', 'sumai' ), 'updated' );
                 sumai_log_event('OpenAI API key cleared via settings.');
            }
            $sanitized_input['api_key'] = ''; // Save empty string
        } else {
            // User submitted a new potential key
            // Check if encryption is possible
            if ( ! function_exists('openssl_encrypt') || ! defined('AUTH_KEY') || ! AUTH_KEY ) {
                 add_settings_error( SUMAI_SETTINGS_OPTION, 'api_key_encrypt_env', __( 'Cannot securely save API key (OpenSSL extension or AUTH_KEY missing). Key NOT saved. Define SUMAI_OPENAI_API_KEY in wp-config.php as an alternative.', 'sumai' ), 'error' );
                 $sanitized_input['api_key'] = $current_encrypted; // Keep old one
            } else {
                // Attempt to encrypt the new key
                $cipher = 'aes-256-cbc';
                $ivlen = openssl_cipher_iv_length( $cipher );
                if (false === $ivlen) {
                      add_settings_error( SUMAI_SETTINGS_OPTION, 'api_key_encrypt_fail', __( 'Failed get IV length for encryption. Key NOT saved.', 'sumai' ), 'error' );
                      $sanitized_input['api_key'] = $current_encrypted; // Keep old one
                } else {
                    $iv = openssl_random_pseudo_bytes( $ivlen );
                    $encrypted = openssl_encrypt( $new_api_key_input, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv );

                    if ( $encrypted !== false && $iv !== false ) {
                        $new_encrypted = base64_encode( $iv . $encrypted );
                        // Only update if the newly encrypted value is actually different
                        if ($new_encrypted !== $current_encrypted) {
                            $sanitized_input['api_key'] = $new_encrypted;
                            add_settings_error( SUMAI_SETTINGS_OPTION, 'api_key_saved', __( 'New API key saved.', 'sumai' ), 'updated' );
                            sumai_log_event('New OpenAI API key encrypted and saved via settings.');
                            // Optionally re-validate the newly saved key here
                            // sumai_validate_api_key($new_api_key_input); // This would log success/failure
                        } else {
                            // Input matched current key, no change needed
                             $sanitized_input['api_key'] = $current_encrypted;
                        }
                    } else {
                        add_settings_error( SUMAI_SETTINGS_OPTION, 'api_key_encrypt_fail', __( 'Failed to encrypt new API key. Key NOT saved.', 'sumai' ), 'error' );
                        $sanitized_input['api_key'] = $current_encrypted; // Keep old one
                    }
                }
            }
        }
    } else {
         // If api_key is not in the submitted $input array at all, keep the existing one.
         $sanitized_input['api_key'] = $current_encrypted;
    }

    return $sanitized_input;
}


/**
 * Render the settings page HTML.
 */
function sumai_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // --- Manual Generation Trigger Handling ---
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['sumai_generate_now'] ) && check_admin_referer( 'sumai_generate_now_action' ) ) {
        sumai_log_event('Manual generation trigger initiated by user.');
        // Ensure required admin functions are available for manual trigger
        if ( ! function_exists( 'wp_insert_post' ) ) {
             sumai_log_event('Loading post.php for manual trigger...');
             require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        // Run generation with force_fetch=true
        $result = sumai_generate_daily_summary( true );

        if ( $result !== false && is_int($result) ) {
            add_settings_error( 'sumai_settings', 'manual_gen_success', sprintf(__( 'Manual summary post generated successfully (Post ID: %d).', 'sumai' ), $result), 'success' );
        } else {
            add_settings_error( 'sumai_settings', 'manual_gen_error', __( 'Manual summary generation failed or skipped (no new content). Check Sumai logs for details.', 'sumai' ), 'error' );
        }
         // Persist notices across the redirect
         set_transient('settings_errors', get_settings_errors(), 30);
         // Redirect to clear POST data and show messages cleanly
         wp_safe_redirect(admin_url('options-general.php?page=sumai-settings'));
         exit;
    }

    // --- Display Notices ---
    // Display notices stored in transient (from manual run redirect)
    $transient_notices = get_transient('settings_errors');
    if ($transient_notices) {
        settings_errors('sumai_settings'); // Let WP display notices stored in transient
        delete_transient('settings_errors');
    } else {
         // Display standard settings errors from saving options.php POST
         settings_errors('sumai_settings');
    }

    // --- Get Settings for Display ---
    $options = get_option( SUMAI_SETTINGS_OPTION, array() );
    // Set defaults for display if options aren't set yet
    $options = wp_parse_args($options, array(
        'feed_urls' => '', 'context_prompt' => '', 'title_prompt' => '',
        'api_key' => '', 'draft_mode' => 0, 'schedule_time' => '03:00',
        'post_signature' => '',
    ));
    // Use placeholder for API key display if set
    $api_key_defined_const = defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY);
    $api_key_set_db = !empty($options['api_key']);
    $api_key_display = $api_key_defined_const ? '*** Defined in wp-config.php ***' : ($api_key_set_db ? '********************' : '');
    $feed_urls_count = count( array_filter( preg_split( '/\r\n|\r|\n/', $options['feed_urls'] ) ) );
    ?>
    <div class="wrap sumai-settings-wrap">
        <h1><?php esc_html_e( 'Sumai Settings', 'sumai' ); ?></h1>

        <div id="sumai-tabs">
             <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'sumai' ); ?>">
                <a href="#tab-main" class="nav-tab"><?php esc_html_e( 'Main Settings', 'sumai' ); ?></a>
                <a href="#tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced & Tools', 'sumai' ); ?></a>
                <a href="#tab-debug" class="nav-tab"><?php esc_html_e( 'Debug Info', 'sumai' ); ?></a>
            </nav>

            <div id="tab-main" class="tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( 'sumai_options_group' ); ?>
                    <table class="form-table" role="presentation">
                       <tbody>
                            <tr>
                                <th scope="row"><label for="sumai-feed-urls"><?php esc_html_e( 'RSS Feed URLs', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-feed-urls" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[feed_urls]" rows="3" cols="50" class="large-text" placeholder="<?php esc_attr_e( 'Enter one valid RSS/Atom feed URL per line (http:// or https://)', 'sumai' ); ?>"><?php echo esc_textarea( $options['feed_urls'] ); ?></textarea>
                                    <p class="description">
                                        <?php printf( esc_html__( 'Enter up to %d feed URLs. Currently saved: %d.', 'sumai' ), SUMAI_MAX_FEED_URLS, $feed_urls_count ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-api-key"><?php esc_html_e( 'OpenAI API Key', 'sumai' ); ?></label></th>
                                <td>
                                    <?php if ($api_key_defined_const): ?>
                                        <input type="text" id="sumai-api-key-const" value="<?php echo esc_attr($api_key_display); ?>" class="regular-text" readonly disabled />
                                        <p class="description"><?php esc_html_e( 'API key is defined using the SUMAI_OPENAI_API_KEY constant in wp-config.php. This setting is ignored.', 'sumai' ); ?></p>
                                    <?php else: ?>
                                        <input type="password" id="sumai-api-key" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[api_key]" value="<?php echo esc_attr( $api_key_display ); ?>" class="regular-text" placeholder="<?php echo $api_key_set_db ? esc_attr__( 'Enter new key to update', 'sumai' ) : esc_attr__( 'Enter your OpenAI API Key', 'sumai'); ?>" autocomplete="new-password" />
                                        <button type="button" id="test-api-button" class="button button-secondary"><?php esc_html_e( 'Test API Key', 'sumai' ); ?></button>
                                        <span id="api-test-result" style="margin-left: 10px; vertical-align: middle;"></span>
                                        <p class="description">
                                            <?php esc_html_e( 'Your securely stored OpenAI API key.', 'sumai' ); ?>
                                            <?php if ( $api_key_set_db ) : ?>
                                                <br/><em><?php esc_html_e( 'Key is set. Leave field showing stars to keep it, enter a new key to replace it, or clear the field and save to remove the key.', 'sumai' ); ?></em>
                                            <?php else: ?>
                                                <br/><?php printf( wp_kses( __( 'Get your API key from <a href="%s" target="_blank">OpenAI</a>.', 'sumai' ), ['a'=>['href'=>[],'target'=>[]]] ), 'https://platform.openai.com/api-keys' ); ?>
                                            <?php endif; ?>
                                             <?php if ( ! function_exists('openssl_encrypt') || ! defined('AUTH_KEY') || !AUTH_KEY) : ?>
                                                <br/><strong style="color: red;"><?php esc_html_e( 'Warning: Cannot securely store API key in database (OpenSSL extension or AUTH_KEY missing/invalid). Please define the SUMAI_OPENAI_API_KEY constant in your wp-config.php file instead.', 'sumai' ); ?></strong>
                                            <?php endif; ?>
                                        </p>
                                     <?php endif; ?>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="sumai-context-prompt"><?php esc_html_e( 'AI Summary Prompt', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-context-prompt" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[context_prompt]" rows="4" cols="50" class="large-text" placeholder="<?php esc_attr_e( 'e.g., Summarize the key points concisely for a tech news blog...', 'sumai' ); ?>"><?php echo esc_textarea( $options['context_prompt'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Optional instructions for AI summary generation (tone, length, focus). Default used if blank.', 'sumai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-title-prompt"><?php esc_html_e( 'AI Title Prompt', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-title-prompt" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[title_prompt]" rows="3" cols="50" class="large-text" placeholder="<?php esc_attr_e( 'e.g., Create a compelling title for this daily tech news summary...', 'sumai' ); ?>"><?php echo esc_textarea( $options['title_prompt'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Optional instructions for AI post title generation. Default used if blank.', 'sumai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Publish Status', 'sumai' ); ?></th>
                                <td>
                                    <fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Publish Status', 'sumai' ); ?></span></legend>
                                        <label><input type="radio" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[draft_mode]" value="0" <?php checked( 0, $options['draft_mode'] ); ?>> <?php esc_html_e( 'Publish Immediately', 'sumai' ); ?></label><br>
                                        <label><input type="radio" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[draft_mode]" value="1" <?php checked( 1, $options['draft_mode'] ); ?>> <?php esc_html_e( 'Save as Draft', 'sumai' ); ?></label>
                                        <p class="description"><?php esc_html_e( 'Choose whether the generated post should be published or saved as a draft.', 'sumai' ); ?></p>
                                    </fieldset>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="sumai-schedule-time"><?php esc_html_e( 'Schedule Time', 'sumai' ); ?></label></th>
                                <td>
                                    <input type="time" id="sumai-schedule-time" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[schedule_time]" value="<?php echo esc_attr( $options['schedule_time'] ); ?>" class="regular-text" required pattern="([01]?[0-9]|2[0-3]):[0-5][0-9]" />
                                    <p class="description">
                                        <?php esc_html_e( 'Time for daily summary generation (HH:MM, 24-hour). Uses site timezone:', 'sumai' ); ?>
                                        <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>.
                                        <?php
                                            $next_run_gmt = wp_next_scheduled( SUMAI_CRON_HOOK );
                                            echo '<br/>' . ($next_run_gmt
                                                ? sprintf(esc_html__('Next scheduled run: %s', 'sumai'), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run_gmt ))
                                                : esc_html__('Not currently scheduled. Save settings to schedule.', 'sumai'));
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-post-signature"><?php esc_html_e( 'Post Signature', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-post-signature" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[post_signature]" rows="5" cols="50" class="large-text" placeholder="<?php esc_attr_e( 'e.g., <p>Summary generated by AI.</p>', 'sumai' ); ?>"><?php echo esc_textarea( $options['post_signature'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Optional Text/HTML appended to each generated post. Allows basic HTML. Leave blank for no signature.', 'sumai' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'sumai' ) ); ?>
                </form>
            </div><!-- /#tab-main -->

            <div id="tab-advanced" class="tab-content" style="display:none;">
                 <h2><?php esc_html_e( 'Manual Generation & Testing', 'sumai' ); ?></h2>
                 <div class="card">
                     <h3><?php esc_html_e( 'Generate Summary Now', 'sumai' ); ?></h3>
                     <p><?php esc_html_e( 'Manually trigger the summary generation process using current settings. This ignores the processed items list and fetches recent items again.', 'sumai' ); ?></p>
                     <form method="post" action="">
                         <?php wp_nonce_field( 'sumai_generate_now_action' ); ?>
                         <input type="submit" name="sumai_generate_now" class="button button-primary" value="<?php esc_attr_e( 'Generate Summary Now', 'sumai' ); ?>">
                     </form>
                 </div>
                 <div class="card">
                     <h3><?php esc_html_e( 'Test Feed Fetching', 'sumai' ); ?></h3>
                     <p><?php esc_html_e( 'Test configured RSS feeds, check reachability, and see which recent items would be considered NEW.', 'sumai' ); ?></p>
                     <button type="button" id="test-feed-button" class="button button-secondary"><?php esc_html_e( 'Test Feeds', 'sumai' ); ?></button>
                     <div id="feed-test-result" class="sumai-test-results"></div>
                 </div>
                 <div class="card">
                    <h3><?php esc_html_e( 'External Cron Trigger', 'sumai' ); ?></h3>
                    <p><?php esc_html_e( 'Use this URL with an external cron service (like EasyCron, Cron-job.org, or server cron) if WP-Cron is unreliable or disabled:', 'sumai' ); ?></p>
                    <?php
                        $cron_token = get_option( SUMAI_CRON_TOKEN_OPTION );
                        if ($cron_token) {
                            $trigger_url = add_query_arg( [ 'sumai_trigger' => '1', 'token' => $cron_token ], site_url( '/' ) );
                            echo '<label for="sumai-cron-url" class="screen-reader-text">' . esc_html__('Cron Trigger URL', 'sumai') . '</label>';
                            echo '<input type="text" id="sumai-cron-url" class="large-text" value="' . esc_url($trigger_url) . '" readonly onfocus="this.select();" aria-describedby="cron-url-desc">';
                            echo '<p id="cron-url-desc" class="description">' . esc_html__('Recommended usage with `wget` or `curl` (wrap URL in single quotes):', 'sumai') . '<br>';
                            echo '<code>wget -qO- \'' . esc_url($trigger_url) . '\' > /dev/null 2>&1</code><br>';
                            echo '<code>curl -s -o /dev/null \'' . esc_url($trigger_url) . '\'</code><br>';
                            echo esc_html__('This token automatically rotates weekly.', 'sumai') . '</p>';
                        } else {
                             echo '<p class="description">' . esc_html__('Cron token not generated. Please activate or re-save the plugin settings.', 'sumai') . '</p>';
                        }
                    ?>
                 </div>
            </div><!-- /#tab-advanced -->

             <div id="tab-debug" class="tab-content" style="display:none;">
                <h2><?php esc_html_e( 'Debug Information', 'sumai' ); ?></h2>
                <?php sumai_render_debug_info(); ?>
            </div><!-- /#tab-debug -->
        </div><!-- /#sumai-tabs -->
    </div><!-- /.wrap -->
    <style>
        .sumai-settings-wrap .card { padding: 15px 20px; border: 1px solid #ccd0d4; background: #fff; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
        .sumai-settings-wrap .nav-tab-wrapper { margin-bottom: 20px; padding-bottom: 0; border-bottom: 1px solid #ccd0d4; }
        .sumai-test-results { margin-top: 10px; padding: 15px; background-color: #f6f7f7; border: 1px solid #ccd0d4; max-height: 400px; overflow-y: auto; display: none; white-space: pre-wrap; font-family: monospace; font-size: 12px; line-height: 1.6; }
        #api-test-result span, #api-test-result .spinner { display: inline-block; vertical-align: middle; }
        #api-test-result .spinner { margin-right: 5px; }
        #feed-test-result .spinner { margin-right: 5px; }
        .sumai-debug-info pre { white-space: pre-wrap; word-break: break-all; background: #f6f7f7; padding: 10px; border: 1px solid #ccd0d4; margin-top: 5px; font-size: 12px; max-height: 300px; overflow-y: auto;}
        .sumai-debug-info .wp-list-table { margin-bottom: 25px; }
        .sumai-debug-info h3 { margin: 30px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #eee; }
        .sumai-debug-info h4 { margin: 20px 0 5px; }
        .sumai-log-entries { background: #1e1e1e; color: #d4d4d4; border-color: #3c3c3c; }
    </style>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Tab Navigation
        var $tabs = $('#sumai-tabs');
        var $navLinks = $tabs.find('.nav-tab');
        var $tabContent = $tabs.find('.tab-content');

        function showTab(targetHash) {
            if (!targetHash || targetHash === '#') {
                targetHash = localStorage.getItem('sumaiActiveTab') || $navLinks.first().attr('href');
            }
            $navLinks.removeClass('nav-tab-active');
            $tabContent.hide();
            var $activeLink = $navLinks.filter('[href="' + targetHash + '"]');
            if ($activeLink.length === 0) {
                 $activeLink = $navLinks.first();
                 targetHash = $activeLink.attr('href');
            }
            $activeLink.addClass('nav-tab-active');
            $(targetHash).show();
            try { localStorage.setItem('sumaiActiveTab', targetHash); } catch(e) {}
            // Optionally update URL hash without jump
            // if (history.pushState) { history.pushState(null, null, targetHash); }
        }

        $navLinks.on('click', function(e) {
            e.preventDefault();
            showTab($(this).attr('href'));
        });

        // Show initial tab based on localStorage or default to first
        showTab(localStorage.getItem('sumaiActiveTab'));


        // API Key Test AJAX
        $('#test-api-button').on('click', function() {
            var $button = $(this);
            var $resultSpan = $('#api-test-result');
            var $apiKeyInput = $('#sumai-api-key'); // Use ID if not constant
            var keyToSend = '';
            if ($apiKeyInput.length > 0) { // Check if the input field exists
                 keyToSend = ($apiKeyInput.val() === '********************') ? '' : $apiKeyInput.val();
            } // If constant is used, keyToSend remains '', testing the saved/constant key

            $button.prop('disabled', true).text('<?php esc_js( __( 'Testing...', 'sumai' ) ); ?>');
            $resultSpan.html('<span class="spinner is-active"></span><?php esc_js( __( 'Testing...', 'sumai' ) ); ?>').css('color', 'inherit');

            $.ajax({
                 url: ajaxurl, type: 'POST', dataType: 'json',
                data: {
                    action: 'sumai_test_api_key',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'sumai_test_api_key_nonce' ); ?>',
                    api_key_to_test: keyToSend // Send '' to test saved/const, else send input value
                 },
                success: function(response) {
                    if (response.success) { $resultSpan.html('✅ ' + response.data.message).css('color', 'green'); }
                    else { $resultSpan.html('❌ ' + response.data.message).css('color', '#d63638'); } // WP error color
                },
                error: function(xhr) {
                    $resultSpan.html('❌ <?php esc_js( __( 'AJAX Error', 'sumai' ) ); ?>').css('color', '#d63638');
                    console.error('Sumai API Test Error:', xhr.status, xhr.responseText);
                },
                complete: function() { $button.prop('disabled', false).text('<?php esc_js( __( 'Test API Key', 'sumai' ) ); ?>'); }
            });
        });

        // Feed Test AJAX
        $('#test-feed-button').on('click', function() {
            var $button = $(this);
            var $resultDiv = $('#feed-test-result');
            $button.prop('disabled', true).text('<?php esc_js( __( 'Testing...', 'sumai' ) ); ?>');
            $resultDiv.html('<span class="spinner is-active"></span><?php esc_js( __( 'Testing feeds...', 'sumai' ) ); ?>').css('color', 'inherit').show();
            $.ajax({
                url: ajaxurl, type: 'POST', dataType: 'json',
                data: {
                    action: 'sumai_test_feeds',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'sumai_test_feeds_nonce' ); ?>'
                },
                success: function(response) {
                    // Expecting pre-formatted HTML <pre> tag in response.data.message
                    if (response.success) { $resultDiv.html(response.data.message).css('color', 'inherit'); }
                    else { $resultDiv.html('❌ Error: ' + response.data.message).css('color', '#d63638'); }
                },
                error: function(xhr) {
                    $resultDiv.html('❌ <?php esc_js( __( 'AJAX Error fetching feeds.', 'sumai' ) ); ?>').css('color', '#d63638');
                     console.error('Sumai Feed Test Error:', xhr.status, xhr.responseText);
                },
                complete: function() { $button.prop('disabled', false).text('<?php esc_js( __( 'Test Feeds', 'sumai' ) ); ?>'); }
            });
        });
    });
    </script>
    <?php
}


/* -------------------------------------------------------------------------
 * 9. AJAX HANDLERS
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_sumai_test_api_key', 'sumai_ajax_test_api_key' );
add_action( 'wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds' );

/** AJAX handler for testing API key. */
function sumai_ajax_test_api_key() {
    // Verify nonce and capability
    check_ajax_referer( 'sumai_test_api_key_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sumai' ) ], 403 );
    }

    // Use trim to handle potential whitespace
    $key_input = isset( $_POST['api_key_to_test'] ) ? trim( sanitize_text_field( $_POST['api_key_to_test'] ) ) : '';
    $key_to_test = '';
    $testing_input = !empty($key_input); // Flag if we are testing a specific input

    if ( $testing_input ) {
        // Test the key provided specifically in the AJAX request
        $key_to_test = $key_input;
        $context_msg = __( 'Provided API key validation', 'sumai' );
    } else {
        // Test the key currently configured (from constant or decrypted DB value)
        $key_to_test = sumai_get_api_key();
        $context_msg = __( 'Current configured API key validation', 'sumai' );
        if ( empty( $key_to_test ) ) {
            wp_send_json_error( [ 'message' => __( 'API key not configured. Enter a key and save, or define SUMAI_OPENAI_API_KEY.', 'sumai' ) ] );
        }
    }

    // Perform validation
    if ( sumai_validate_api_key( $key_to_test ) ) {
        wp_send_json_success( [ 'message' => $context_msg . ' ' . __( 'successful.', 'sumai' ) ] );
    } else {
        // Validation function logs details
        wp_send_json_error( [ 'message' => $context_msg . ' ' . __( 'failed. Check Sumai debug logs.', 'sumai' ) ] );
    }
}

/** AJAX handler for testing feeds. */
function sumai_ajax_test_feeds() {
    // Verify nonce and capability
    check_ajax_referer( 'sumai_test_feeds_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sumai' ) ], 403 );
    }

    $options = get_option( SUMAI_SETTINGS_OPTION, [] );
    $feed_urls_raw = $options['feed_urls'] ?? '';
    $feed_urls = array_slice( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $feed_urls_raw ) ) ), 0, SUMAI_MAX_FEED_URLS );

    if ( empty( $feed_urls ) ) {
        wp_send_json_error( [ 'message' => __( 'No feed URLs configured in settings.', 'sumai' ) ] );
    }

    // Ensure fetch_feed is available
     if ( ! function_exists( 'fetch_feed' ) ) {
        include_once ABSPATH . WPINC . '/feed.php';
        if ( ! function_exists( 'fetch_feed' ) ) {
            wp_send_json_error( [ 'message' => __( 'Error: WordPress feed functions (fetch_feed) are unavailable.', 'sumai' ) ] );
        }
    }

    // Get the formatted test output
    $test_output = sumai_test_feeds( $feed_urls );
    // Send back the pre-formatted text within a <pre> tag for proper display
    wp_send_json_success( [ 'message' => '<pre>' . esc_html($test_output) . '</pre>' ] );
}

/** Tests configured RSS feeds and returns formatted string output. */
function sumai_test_feeds( array $feed_urls ): string {
    // fetch_feed should be loaded by the AJAX handler
    if ( ! function_exists( 'fetch_feed' ) ) return "Error: fetch_feed() function not available.";

    $output = "--- Sumai Feed Test Results ---\n";
    $output .= "Time: " . wp_date('Y-m-d H:i:s T') . "\n";
    $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, [] );
    $output .= "Tracking " . count( $processed_guids ) . " processed item GUIDs (max TTL: " . (SUMAI_PROCESSED_GUID_TTL / DAY_IN_SECONDS) ." days).\n\n";
    $found_new_overall = false;
    $total_items_checked = 0;
    $total_new_items = 0;

    foreach ( $feed_urls as $index => $url ) {
        $output .= "--- Feed #" . ($index + 1) . ": {$url} ---\n";

        // Attempt to force-refresh the feed for testing by temporarily disabling WP cache
        wp_feed_cache_transient_lifetime( 0 );
        $feed = fetch_feed( $url );
        wp_feed_cache_transient_lifetime( HOUR_IN_SECONDS ); // Restore default (or filterable) cache time

        if ( is_wp_error( $feed ) ) {
            $output .= "❌ Error fetching feed: " . esc_html( $feed->get_error_message() ) . "\n\n";
            continue;
        }

        // Get items up to the processing limit for relevant testing
        $items = $feed->get_items( 0, SUMAI_FEED_ITEM_LIMIT );
        $item_count = count( $items );
        $total_items_checked += $item_count;

        if ( empty( $items ) ) {
            $output .= "⚠️ Feed fetched successfully, but no items found within the limit of " . SUMAI_FEED_ITEM_LIMIT . ".\n\n";
            continue;
        }

        $output .= "✅ Feed OK. Found {$item_count} item(s) within limit. Checking status:\n";
        $found_new_this_feed = false;
        foreach ($items as $item_index => $item) {
             $guid = $item->get_id( true );
             $title = strip_tags($item->get_title() ?: 'Untitled');
             $title = mb_strimwidth($title, 0, 80, '...'); // Truncate long titles

             $output .= "- Item " . ($item_index + 1) . ": " . esc_html( $title ) . "\n"; // (GUID: " . esc_html( $guid ) . ")
             if ( isset( $processed_guids[ $guid ] ) ) {
                 $output .= "  Status: Processed (" . wp_date('Y-m-d H:i', $processed_guids[$guid]) . ")\n";
             } else {
                 $output .= "  Status: ✨ NEW (Would be processed)\n";
                 $found_new_this_feed = true;
                 $found_new_overall = true;
                 $total_new_items++;
             }
        }
        if (!$found_new_this_feed) {
            $output .= "  ℹ️ All checked items from this feed were processed previously.\n";
        }
        $output .= "\n";
        unset( $feed, $items ); // Cleanup memory
    }
    $output .= "--- Test Summary ---\n";
    $output .= "Checked " . count($feed_urls) . " feed(s), found {$total_items_checked} total item(s) within limits.\n";
    if ($found_new_overall) {
        $output .= "✅ Detected {$total_new_items} NEW item(s) that would be included in the next summary.";
    } else {
        $output .= "ℹ️ No new, unprocessed items detected in the latest checks.";
    }
    return $output;
}


/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING
 * ------------------------------------------------------------------------- */

/** Ensure log directory exists and is writable. Returns log file path or null on failure. */
function sumai_ensure_log_dir(): ?string {
    static $log_file_path = null;
    static $checked = false;

    if ($checked) { return $log_file_path; } // Return cached result (path or null)
    $checked = true;

    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        error_log( "Sumai Log Error: wp_upload_dir failed: " . $upload_dir['error'] );
        return null;
    }

    $log_dir = trailingslashit( $upload_dir['basedir'] ) . SUMAI_LOG_DIR_NAME;
    $log_file = trailingslashit( $log_dir ) . SUMAI_LOG_FILE_NAME;

    // Check/create directory with protection
    if ( ! is_dir( $log_dir ) ) {
        if ( ! wp_mkdir_p( $log_dir ) ) {
             error_log("Sumai Log Error: Failed to create directory: " . $log_dir);
             return null;
        }
        // Add/update protection files (.htaccess for Apache, index.php for others)
        @file_put_contents( $log_dir . '/.htaccess', "Options -Indexes\nDeny from all\n<Files \"" . SUMAI_LOG_FILE_NAME . "\">\n Require all denied\n</Files>\n" );
        @file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
        @chmod( $log_dir . '/.htaccess', 0644 );
        @chmod( $log_dir . '/index.php', 0644 );
        error_log("Sumai Log Info: Created log directory and protection files: " . $log_dir);
    }

    // Check directory writability
    if ( ! is_writable( $log_dir ) ) {
        error_log( "Sumai Log Error: Directory not writable: " . $log_dir );
        return null;
    }

    // Check/create log file
    if ( ! file_exists( $log_file ) ) {
         if ( false === @file_put_contents( $log_file, '' ) ) { // Try creating it
              error_log( "Sumai Log Error: Failed to create log file: " . $log_file );
              return null;
         }
         @chmod( $log_file, 0644 ); // Set reasonable permissions
         error_log("Sumai Log Info: Created log file: " . $log_file);
    }

    // Final check: file writability
     if ( ! is_writable( $log_file ) ) {
         error_log( "Sumai Log Error: Log file exists but is not writable: " . $log_file );
         return null;
     }

    $log_file_path = $log_file; // Cache the successful path
    return $log_file_path;
}

/** Log plugin events to the dedicated log file. */
function sumai_log_event( string $message, bool $is_error = false ) {
    $log_file = sumai_ensure_log_dir();
    // Don't try to log if the log directory/file setup failed
    if ( ! $log_file ) {
        // Optionally log to PHP error log as a fallback if our file fails
        error_log( "Sumai Plugin " . ($is_error ? '[ERROR]' : '[INFO]') . " (Log file unavailable): " . $message );
        return;
    }

    $timestamp = wp_date( 'Y-m-d H:i:s T' ); // Use WP function for site timezone
    $level = $is_error ? ' [ERROR] ' : ' [INFO]  '; // Consistent spacing
    // Basic sanitization - remove tags and normalize whitespace
    $sanitized_message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) );

    $log_line = '[' . $timestamp . ']' . $level . $sanitized_message . PHP_EOL;

    // Use LOCK_EX for safer concurrent writes (especially during cron)
    @file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX );
}

/** Prune old log entries based on SUMAI_LOG_TTL. */
function sumai_prune_logs() {
    $log_days = SUMAI_LOG_TTL / DAY_IN_SECONDS;
    sumai_log_event( "Running scheduled log pruning (keeping last {$log_days} days)..." );
    $log_file = sumai_ensure_log_dir();

    if ( ! $log_file || ! file_exists( $log_file ) || ! is_readable( $log_file ) || !is_writable($log_file) ) {
        sumai_log_event( 'Log pruning skipped: Log file missing or inaccessible.', true );
        return;
    }

    $lines = @file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( $lines === false || empty($lines) ) {
        sumai_log_event( 'Log pruning: Log file is empty or unreadable.' );
        return;
    }

    $cutoff_timestamp = time() - SUMAI_LOG_TTL;
    $retained_lines = [];
    $lines_processed = count($lines);
    $lines_pruned = 0;

    foreach ( $lines as $line ) {
        // Match timestamp like [YYYY-MM-DD HH:MM:SS TZ/Offset]
        if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/', $line, $matches ) ) {
            $timestamp = false;
            try {
                 // Use DateTime for robust parsing, respecting timezone info
                 $dt = new DateTime($matches[1]);
                 $timestamp = $dt->getTimestamp(); // Get UTC timestamp
            } catch (Exception $e) {
                 // Fallback: Attempt strtotime if DateTime fails (less reliable for complex TZs)
                 $timestamp = strtotime( $matches[1] );
                 if ($timestamp === false) {
                      sumai_log_event( "Log pruning: Could not parse timestamp '{$matches[1]}'. Retaining line: " . $line, true );
                 }
            }

            // Keep if timestamp is valid and recent enough
            if ( $timestamp !== false && $timestamp >= $cutoff_timestamp ) {
                $retained_lines[] = $line;
            } else {
                // Line is too old or timestamp was invalid
                $lines_pruned++;
            }
        } else {
             // Keep lines that don't match the expected timestamp format
             $retained_lines[] = $line;
        }
    }

    if ( $lines_pruned > 0 ) {
        // Add a newline at the end only if there are retained lines
        $new_content = empty($retained_lines) ? '' : implode( PHP_EOL, $retained_lines ) . PHP_EOL;
        // Attempt to overwrite the file with retained lines
        if ( false !== @file_put_contents( $log_file, $new_content, LOCK_EX ) ) {
             sumai_log_event( "Log pruning complete. Processed {$lines_processed} lines, removed {$lines_pruned} old/invalid entries." );
        } else {
             sumai_log_event( "Log pruning failed: Could not write updated log file.", true );
        }
    } else {
        sumai_log_event( "Log pruning complete. Processed {$lines_processed} lines. No old entries found to remove." );
    }
}

/** Retrieves debug information for display on the settings page. */
function sumai_get_debug_info(): array {
    $debug_info = [];
    $options = get_option( SUMAI_SETTINGS_OPTION, [] );

    // Mask API key for display safety
    $options['api_key'] = (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY))
                         ? '*** Defined in wp-config.php ***'
                         : (!empty($options['api_key']) ? '*** SET (Encrypted in DB) ***' : '*** NOT SET ***');
    $debug_info['settings'] = $options;

    // Cron Jobs
    $cron_jobs = _get_cron_array() ?: [];
    $debug_info['cron_jobs'] = [];
    $has_sumai_jobs = false;
    foreach ( $cron_jobs as $time => $hooks ) {
        foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $hook_name) {
            if ( isset( $hooks[$hook_name] ) ) {
                 $has_sumai_jobs = true;
                 $event_key = key($hooks[$hook_name]);
                 $schedule_details = $hooks[$hook_name][$event_key];
                 $schedule_name = $schedule_details['schedule'] ?? '(One-off?)';
                 $interval = isset($schedule_details['interval']) ? ($schedule_details['interval'] . 's') : 'N/A';
                $debug_info['cron_jobs'][$hook_name] = [
                    'next_run_gmt' => gmdate('Y-m-d H:i:s', $time),
                    'next_run_site' => wp_date( 'Y-m-d H:i:s T', $time ),
                    'schedule_name' => $schedule_name,
                    'interval' => $interval
                ];
            }
        }
    }
     if (!$has_sumai_jobs) $debug_info['cron_jobs'] = 'No Sumai tasks found in WP-Cron schedule.';

    // Logging Info
    $log_file = sumai_ensure_log_dir(); // Ensure paths are checked/created
    $debug_info['log_file_path'] = $log_file ?: 'ERROR: Log directory/file setup failed. Check PHP error logs.';
    $debug_info['log_writable'] = $log_file && is_writable($log_file);
    $debug_info['log_readable'] = $log_file && is_readable($log_file);
    $debug_info['log_size_kb'] = ($debug_info['log_readable'] && file_exists($log_file)) ? round(filesize($log_file) / 1024, 2) : 'N/A';

    // Recent Log Entries (read max ~10KB efficiently)
    if ( $debug_info['log_readable'] ) {
        $log_content = ($debug_info['log_size_kb'] > 0) ? @file_get_contents( $log_file, false, null, -10240 ) : ''; // Read last ~10KB
        if ($log_content !== false) {
            $log_lines = explode( "\n", trim( $log_content ) );
            $debug_info['recent_logs'] = array_slice( $log_lines, -50 ); // Show max last 50 lines from the tail
        } else { $debug_info['recent_logs'] = ['Error: Could not read log file content.']; }
    } else { $debug_info['recent_logs'] = ['Error: Log file not found or not readable.']; }

    // Processed GUIDs Summary
    $processed_guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    $debug_info['processed_guids_count'] = count($processed_guids);
    $newest_guid_time = empty($processed_guids) ? 0 : max($processed_guids);
    $oldest_guid_time = empty($processed_guids) ? 0 : min($processed_guids);
    $debug_info['processed_guids_newest'] = $newest_guid_time > 0 ? wp_date('Y-m-d H:i:s T', $newest_guid_time) : 'N/A';
    $debug_info['processed_guids_oldest'] = $oldest_guid_time > 0 ? wp_date('Y-m-d H:i:s T', $oldest_guid_time) : 'N/A';

    // System Info
    global $wp_version;
    $wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    $wp_cron_url = site_url( 'wp-cron.php' );
    $debug_info['system'] = [
        'plugin_version' => get_file_data(__FILE__, ['Version' => 'Version'])['Version'],
        'php_version' => phpversion(),
        'wp_version' => $wp_version,
        'wp_timezone' => wp_timezone_string(),
        'wp_debug' => (defined('WP_DEBUG') && WP_DEBUG) ? 'Yes' : 'No',
        'wp_memory_limit' => WP_MEMORY_LIMIT,
        'wp_cron_status' => $wp_cron_disabled ? 'Disabled (DISABLE_WP_CRON is true)' : 'Enabled',
        'wp_cron_url_test' => $wp_cron_disabled ? 'N/A (WP-Cron Disabled)' : '<a href="'.$wp_cron_url.'?doing_wp_cron" target="_blank" rel="noopener noreferrer">Test Link</a> (Should show blank page)',
        'server_time_utc' => gmdate('Y-m-d H:i:s'),
        'site_time' => wp_date('Y-m-d H:i:s T'),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        'openssl_loaded' => extension_loaded('openssl') ? 'Yes' : 'No',
        'auth_key_defined' => (defined('AUTH_KEY') && AUTH_KEY) ? 'Yes' : 'No (Required for DB API key encryption)',
        'curl_loaded' => extension_loaded('curl') ? 'Yes' : 'No (Required for API calls)',
        'mbstring_loaded' => extension_loaded('mbstring') ? 'Yes' : 'No (Recommended for multi-byte chars)',
    ];
    return $debug_info;
}

/** Renders the debug information tab content. */
function sumai_render_debug_info() {
    $debug_info = sumai_get_debug_info();
    ?>
    <div class="sumai-debug-info">
        <p><i><?php esc_html_e('This information helps troubleshoot issues. No sensitive data (like API keys) is displayed.', 'sumai'); ?></i></p>

        <h3><?php esc_html_e( 'Plugin Settings (Current)', 'sumai' ); ?></h3>
        <table class="wp-list-table widefat fixed striped"><tbody>
            <?php foreach ($debug_info['settings'] as $key => $value): ?>
                <tr>
                    <td style="width: 30%; vertical-align: top;"><strong><?php echo esc_html( ucwords(str_replace('_', ' ', $key)) ); ?></strong></td>
                    <td><pre><?php
                        if (is_array($value)) echo esc_html(print_r($value, true));
                        elseif (is_bool($value)) echo $value ? 'true' : 'false';
                        else echo esc_html( $value );
                    ?></pre></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>

         <h3><?php esc_html_e( 'Processed Items Tracking', 'sumai' ); ?></h3>
         <table class="wp-list-table widefat fixed striped"><tbody>
             <tr><td style="width: 30%;"><strong><?php esc_html_e('Tracked Items Count', 'sumai'); ?></strong></td><td><?php echo esc_html($debug_info['processed_guids_count']); ?></td></tr>
             <tr><td><strong><?php esc_html_e('Newest Item Logged', 'sumai'); ?></strong></td><td><?php echo esc_html($debug_info['processed_guids_newest']); ?></td></tr>
             <tr><td><strong><?php esc_html_e('Oldest Item Logged', 'sumai'); ?></strong></td><td><?php echo esc_html($debug_info['processed_guids_oldest']); ?> (Max TTL: <?php echo esc_html(SUMAI_PROCESSED_GUID_TTL / DAY_IN_SECONDS); ?> days)</td></tr>
        </tbody></table>

        <h3><?php esc_html_e( 'Scheduled Tasks (Sumai)', 'sumai' ); ?></h3>
        <?php if ( is_array($debug_info['cron_jobs']) && ! empty( $debug_info['cron_jobs'] ) ): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Hook Name</th><th>Next Run (Site Timezone)</th><th>Next Run (UTC)</th><th>Recurrence</th></tr></thead>
                <tbody>
                    <?php foreach ($debug_info['cron_jobs'] as $hook => $details): ?>
                        <tr>
                            <td><code><?php echo esc_html($hook); ?></code></td>
                            <td><?php echo esc_html($details['next_run_site']); ?></td>
                            <td><?php echo esc_html($details['next_run_gmt']); ?></td>
                            <td><?php echo esc_html($details['schedule_name']); ?> (<?php echo esc_html($details['interval']); ?>)</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php echo is_string($debug_info['cron_jobs']) ? esc_html($debug_info['cron_jobs']) : esc_html__( 'No Sumai-specific tasks found in the WP-Cron schedule.', 'sumai' ); ?></p>
        <?php endif; ?>

        <h3><?php esc_html_e( 'System & Server Info', 'sumai' ); ?></h3>
         <table class="wp-list-table widefat fixed striped"><tbody>
            <?php foreach ($debug_info['system'] as $key => $value): ?>
                <tr>
                    <td style="width: 30%;"><strong><?php echo esc_html( ucwords(str_replace('_', ' ', $key)) ); ?></strong></td>
                    <td><?php echo wp_kses_post( $value ); // Allow links for WP Cron test ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>

        <h3><?php esc_html_e( 'Logging Information', 'sumai' ); ?></h3>
        <table class="wp-list-table widefat fixed striped"><tbody>
             <tr><td style="width: 30%;"><strong><?php esc_html_e('Log File Path', 'sumai'); ?></strong></td><td><code><?php echo esc_html($debug_info['log_file_path']); ?></code></td></tr>
             <tr><td><strong><?php esc_html_e('Readable by WP', 'sumai'); ?></strong></td><td><?php echo $debug_info['log_readable'] ? '✅ Yes' : '❌ No'; ?></td></tr>
             <tr><td><strong><?php esc_html_e('Writable by WP', 'sumai'); ?></strong></td><td><?php echo $debug_info['log_writable'] ? '✅ Yes' : '❌ No'; ?></td></tr>
             <tr><td><strong><?php esc_html_e('Approximate Size', 'sumai'); ?></strong></td><td><?php echo esc_html($debug_info['log_size_kb']); ?> KB</td></tr>
        </tbody></table>

        <h4><?php esc_html_e( 'Recent Log Entries (Tail ~50 lines)', 'sumai' ); ?></h4>
        <div class="sumai-log-entries" style="max-height: 400px; overflow-y: auto; border: 1px solid #3c3c3c; padding: 10px; font-family: monospace; white-space: pre-wrap; word-break: break-word;">
            <?php
                if (!empty($debug_info['recent_logs'])) {
                    // Implode with newline and escape for HTML display
                    echo esc_html( implode( "\n", $debug_info['recent_logs'] ) );
                } else {
                    echo esc_html__('Log appears empty or could not be read.', 'sumai');
                }
            ?>
        </div>
    </div>
    <?php
}