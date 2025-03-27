<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Automatically fetches and summarizes the latest RSS feed articles using OpenAI gpt-4o-mini, then publishes a single daily "Daily Summary" post.
 * Version:     1.1.4
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
define( 'SUMAI_FEED_ITEM_LIMIT', 7 ); // Slightly reduced item limit per feed
define( 'SUMAI_MAX_INPUT_CHARS', 25000 ); // Slightly reduced max chars for API
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
        'feed_urls' => '',
        'context_prompt' => "Summarize the key points from the following articles concisely.",
        'title_prompt' => "Generate a compelling and unique title for this daily news summary.",
        'api_key' => '',
        'draft_mode' => 0,
        'schedule_time' => '03:00',
        'post_signature' => '',
    );
    // Add option only if it doesn't exist
    add_option( SUMAI_SETTINGS_OPTION, $defaults, '', 'no' );

    sumai_ensure_log_dir();
    sumai_schedule_daily_event();

    if ( ! get_option( SUMAI_CRON_TOKEN_OPTION ) ) {
        update_option( SUMAI_CRON_TOKEN_OPTION, bin2hex( random_bytes( 16 ) ) );
    }
    if ( ! wp_next_scheduled( SUMAI_ROTATE_TOKEN_HOOK ) ) {
        wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK );
    }
    if ( ! wp_next_scheduled( SUMAI_PRUNE_LOGS_HOOK ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK );
    }
    sumai_log_event( 'Plugin activated.' );
}

/**
 * Plugin Deactivation Handler.
 */
function sumai_deactivate() {
    wp_clear_scheduled_hook( SUMAI_CRON_HOOK );
    wp_clear_scheduled_hook( SUMAI_ROTATE_TOKEN_HOOK );
    wp_clear_scheduled_hook( SUMAI_PRUNE_LOGS_HOOK );
    sumai_log_event( 'Plugin deactivated. Cron jobs cleared.' );
    // Note: Settings and logs are intentionally kept on deactivation by default.
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
    $schedule_time_str = isset( $options['schedule_time'] ) && preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $options['schedule_time'] )
        ? $options['schedule_time']
        : '03:00'; // Default/fallback

    // Use wp_date to get timestamp in site's timezone correctly
    $scheduled_time_today_str = date('Y-m-d') . ' ' . $schedule_time_str . ':00';
    $site_timezone = wp_timezone();
    $scheduled_dt_today = date_create( $scheduled_time_today_str, $site_timezone );

    if ( ! $scheduled_dt_today ) {
         sumai_log_event( 'Error calculating schedule time timestamp. Using default.', true );
         $first_run = strtotime('tomorrow 03:00'); // Fallback
    } else {
        $scheduled_timestamp_today = $scheduled_dt_today->getTimestamp();
        $now_timestamp = current_time( 'timestamp' ); // Use WP's current time

        // If time has passed today, schedule for tomorrow same time
        if ( $scheduled_timestamp_today <= $now_timestamp ) {
            $scheduled_dt_today->modify('+1 day');
            $first_run = $scheduled_dt_today->getTimestamp();
        } else {
            $first_run = $scheduled_timestamp_today;
        }
    }

    wp_schedule_event( $first_run, 'daily', SUMAI_CRON_HOOK );
    sumai_log_event( 'Daily summary event scheduled. Next run approx: ' . wp_date( 'Y-m-d H:i:s T', $first_run ) );
}

// Reschedule when settings are updated
add_action( 'update_option_' . SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event', 10, 0 );
add_action( SUMAI_CRON_HOOK, 'sumai_generate_daily_summary' );
add_action( SUMAI_ROTATE_TOKEN_HOOK, function() {
    update_option( SUMAI_CRON_TOKEN_OPTION, bin2hex( random_bytes( 16 ) ) );
    sumai_log_event( 'Cron security token rotated.' );
});
add_action( SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs' );

/* -------------------------------------------------------------------------
 * 3. EXTERNAL CRON TRIGGER
 * ------------------------------------------------------------------------- */

add_action( 'init', 'sumai_check_external_trigger' );

/**
 * Check for external cron trigger requests.
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

        // Prevent multiple rapid runs via external trigger using a transient
        if ( false === get_transient( 'sumai_external_trigger_lock' ) ) {
            set_transient( 'sumai_external_trigger_lock', time(), MINUTE_IN_SECONDS * 5 ); // Lock for 5 minutes
            sumai_log_event( 'Executing summary generation via external trigger.' );
            // Use spawn_cron() for potentially better background processing if available
            // spawn_cron(); // This might trigger all WP cron events, maybe too broad?
            // Safer: Directly trigger our specific hook non-blockingly if possible,
            // or just run it synchronously here (simpler). Let's run synchronously for now.
            do_action( SUMAI_CRON_HOOK );
            // Optional: Exit after processing if this is meant only for the trigger
            // exit('Sumai trigger processed.');
        } else {
            sumai_log_event( 'External cron trigger skipped, process locked (ran recently).', true );
             // Optional: Exit
            // exit('Sumai trigger skipped, ran recently.');
        }
    } else {
        sumai_log_event( 'Invalid external cron trigger token received.', true );
         // Optional: Exit with error
        // status_header(403);
        // exit('Invalid token.');
    }
}

/* -------------------------------------------------------------------------
 * 4. MAIN SUMMARY GENERATION
 * ------------------------------------------------------------------------- */

/**
 * Main function to generate the daily summary post.
 *
 * @param bool $force_fetch If true, ignores processed GUID check (for manual trigger/testing).
 * @return int|false Post ID on success, false on failure.
 */
/**
 * Wrapper function triggered by the cron hook to ensure admin files are loaded.
 */
function sumai_run_daily_summary_hook() {
    sumai_log_event( 'Cron hook triggered. Ensuring admin files are loaded...' );

    // Ensure core post functions are available, including wp_unique_post_title()
    if ( ! function_exists( 'wp_insert_post' ) ) { // Check for a common function in post.php
        sumai_log_event( 'Loading wp-admin/includes/post.php...' );
        require_once ABSPATH . 'wp-admin/includes/post.php';
        if ( ! function_exists( 'wp_insert_post' ) ) {
             sumai_log_event( 'FATAL: Failed to load wp-admin/includes/post.php!', true );
             return; // Stop if loading failed
        }
    } else {
         sumai_log_event( 'wp-admin/includes/post.php seems already loaded.' );
    }

     // Now call the main generation function
     sumai_log_event( 'Calling sumai_generate_daily_summary()...' );
     sumai_generate_daily_summary();
}

function sumai_generate_daily_summary( bool $force_fetch = false ) {
    sumai_log_event( 'Starting summary generation...' . ($force_fetch ? ' (Forced Fetch)' : '') );

    $options = get_option( SUMAI_SETTINGS_OPTION, array() );
    $api_key = sumai_get_api_key();

    // --- Pre-checks ---
    if ( empty( $api_key ) ) {
        sumai_log_event( 'Error: OpenAI API key is missing or invalid.', true );
        return false;
    }
    $feed_urls_raw = isset( $options['feed_urls'] ) ? $options['feed_urls'] : '';
    if ( empty( $feed_urls_raw ) ) {
        sumai_log_event( 'Error: No RSS feed URLs configured.', true );
        return false;
    }
    $feed_urls = array_slice( array_filter( array_map( 'trim', explode( "\n", $feed_urls_raw ) ) ), 0, SUMAI_MAX_FEED_URLS );
    if ( empty( $feed_urls ) ) {
        sumai_log_event( 'Error: No valid RSS feed URLs after filtering.', true );
        return false;
    }
    // --- End Pre-checks ---

    try {
        // --- Fetch New Content ---
        sumai_log_event( 'Fetching new article content...' );
        list( $new_content, $processed_guids_updates ) = sumai_fetch_new_articles_content( $feed_urls, $force_fetch );

        if ( empty( $new_content ) ) {
            sumai_log_event( 'No new content found in feeds. Summary generation skipped.' );
            return false; // Success, but nothing to do
        }
        sumai_log_event( 'Fetched ' . mb_strlen( $new_content ) . ' characters of new content.' );
        // --- End Fetch New Content ---

        // --- Generate Summary & Title ---
        sumai_log_event( 'Generating summary and title via OpenAI...' );
        $context_prompt = isset( $options['context_prompt'] ) ? $options['context_prompt'] : '';
        $title_prompt = isset( $options['title_prompt'] ) ? $options['title_prompt'] : '';
        $summary_result = sumai_summarize_text( $new_content, $context_prompt, $title_prompt, $api_key );

        // Explicitly release potentially large $new_content variable
        unset($new_content);

        if ( ! $summary_result || empty( $summary_result['content'] ) || empty( $summary_result['title'] ) ) {
            sumai_log_event( 'Error: Failed to generate summary or title from OpenAI.', true );
            return false;
        }
        sumai_log_event( 'Summary and title generated.' );
        // --- End Generate Summary & Title ---

        // --- Create Post ---
        sumai_log_event( 'Preparing to create WordPress post...' );
        $draft_mode = isset( $options['draft_mode'] ) ? (int) $options['draft_mode'] : 0;

        // *** FIX START: Ensure wp_unique_post_title() is loaded ***
        if ( ! function_exists( 'wp_unique_post_title' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
            // sumai_log_event( 'Loaded wp-admin/includes/post.php for wp_unique_post_title().' ); // Optional: Log that we loaded it
        }
        // *** FIX END ***

        // Ensure title uniqueness using WP core function right before insert
        // Remove potential quotes often added by AI models
        $clean_title = trim( $summary_result['title'], '"\' ' );
        $unique_title = wp_unique_post_title( $clean_title ); // This should now work reliably
        if ($unique_title !== $clean_title) {
             sumai_log_event( "Title adjusted for uniqueness: '{$clean_title}' -> '{$unique_title}'" );
        }

        // Determine post author - Use admin user ID 1 as fallback for non-interactive context
        $author_id = get_current_user_id(); // Will be 0 if run by system cron
        if ( empty( $author_id ) ) {
            // Check if user ID 1 exists and can publish posts
            $user1 = get_userdata( 1 );
            $author_id = ( $user1 && $user1->has_cap('publish_posts') ) ? 1 : null;

            // Fallback: find *any* admin user? More complex. Sticking to ID 1 is common.
            if ( ! $author_id ) {
                 sumai_log_event( 'Error: Could not determine a valid post author ID (tried current user and admin ID 1).', true );
                 return false;
            }
             sumai_log_event( "Running in non-interactive context, using author ID: {$author_id}" );
        }


        $post_data = array(
            'post_title'    => $unique_title,
            'post_content'  => $summary_result['content'], // Signature added via filter
            'post_status'   => $draft_mode ? 'draft' : 'publish',
            'post_type'     => 'post',
            'post_author'   => $author_id,
        );

        $post_id = wp_insert_post( $post_data, true ); // Return WP_Error on failure

        if ( is_wp_error( $post_id ) ) {
            sumai_log_event( "Error creating post: " . $post_id->get_error_message(), true );
            return false;
        }

        sumai_log_event( "Successfully created post ID: {$post_id} (Status: {$post_data['post_status']})." );
        // --- End Create Post ---

        // --- Update Processed GUIDs ---
        if ( ! empty( $processed_guids_updates ) ) {
            $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, array() );
            $processed_guids = array_merge( $processed_guids, $processed_guids_updates );

            // Prune old entries immediately
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

        return $post_id;

    } catch ( \Throwable $e ) {
        // Catch any unexpected fatal errors/exceptions
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
 * @return array [string $combined_content, array $processed_guids_updates]
 */
function sumai_fetch_new_articles_content( array $feed_urls, bool $force_fetch = false ): array {
    if ( ! function_exists( 'fetch_feed' ) ) {
        // This should ideally be loaded earlier, but ensure it's available
        include_once ABSPATH . WPINC . '/feed.php';
        if ( ! function_exists( 'fetch_feed' ) ) {
            sumai_log_event( 'Error: fetch_feed() function not available after including feed.php.', true );
            return ['', []];
        }
    }

    $combined_content = '';
    $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, array() );
    $newly_processed_guids = array();
    $current_time = time();

    foreach ( $feed_urls as $url ) {
        $url = esc_url_raw( trim( $url ) );
        if ( empty( $url ) ) continue;

        sumai_log_event( "Processing feed: {$url}" );
        $feed = fetch_feed( $url ); // WP handles caching internally

        if ( is_wp_error( $feed ) ) {
            sumai_log_event( "Error fetching feed {$url}: " . $feed->get_error_message(), true );
            continue;
        }

        $items = $feed->get_items( 0, SUMAI_FEED_ITEM_LIMIT ); // Use constant for limit

        if ( empty( $items ) ) {
            sumai_log_event( "No items found in feed: {$url}" );
        } else {
            sumai_log_event( "Found " . count( $items ) . " items in feed: {$url} (Limit: " . SUMAI_FEED_ITEM_LIMIT . ")" );
            foreach ( $items as $item ) {
                $guid = $item->get_id( true );
                if ( ! $force_fetch && isset( $processed_guids[ $guid ] ) ) continue;
                if ( isset( $newly_processed_guids[ $guid ] ) ) continue; // Skip duplicates within this run

                $title = $item->get_title() ?: 'Untitled';
                $content = $item->get_content();
                $description = $item->get_description();
                $text_content = wp_strip_all_tags( ! empty( $content ) ? $content : $description );
                $text_content = trim( preg_replace( '/\s+/', ' ', $text_content ) ); // Normalize whitespace

                if ( ! empty( $text_content ) ) {
                    $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST); // Use host as fallback title
                    $combined_content .= "Source: " . esc_html($feed_title) . "\n";
                    $combined_content .= "Title: " . esc_html(wp_strip_all_tags($title)) . "\n";
                    $combined_content .= "Content:\n" . $text_content . "\n\n---\n\n";
                    $newly_processed_guids[ $guid ] = $current_time;
                    // sumai_log_event( "Adding new item (GUID: {$guid}) Title: {$title}" ); // Reduce log noise
                }
            }
        }
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
function sumai_summarize_text( string $text, string $context_prompt, string $title_prompt, string $api_key ) {
    if ( empty( $text ) ) {
        sumai_log_event( 'Error: Empty text provided for summarization.', true );
        return false;
    }

    // Truncate based on constant
    if ( mb_strlen( $text ) > SUMAI_MAX_INPUT_CHARS ) {
        $text = mb_substr( $text, 0, SUMAI_MAX_INPUT_CHARS );
        sumai_log_event( 'Input text truncated to ' . SUMAI_MAX_INPUT_CHARS . ' characters for summarization.' );
    }

    $messages = [];
    $messages[] = [
        'role' => 'system',
        'content' => "You are an expert summarizer. Generate a concise summary and a unique title based on the provided text from multiple articles. "
                   . "Summary Context: " . ($context_prompt ?: "Focus on the main points and key information.") . " "
                   . "Title Context: " . ($title_prompt ?: "Create a compelling title reflecting the summary's content.") . " "
                   . "Output format MUST be JSON like this: {\"title\": \"Generated Title\", \"summary\": \"Generated Summary Content\"}"
    ];
    $messages[] = [ 'role' => 'user', 'content' => "Text to summarize:\n\n" . $text ];

    $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    $request_body = array(
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 1000, // Max tokens for the output
        'temperature' => 0.6,
        'response_format' => [ 'type' => 'json_object' ]
    );

    $request_args = array(
        'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ],
        'body' => json_encode( $request_body ),
        'method' => 'POST',
        'timeout' => 90, // Increased timeout slightly for potentially large summaries
    );

    sumai_log_event( 'Sending request to OpenAI API (model: gpt-4o-mini)...' );
    $response = wp_remote_post( $api_endpoint, $request_args );

    if ( is_wp_error( $response ) ) {
        sumai_log_event( 'OpenAI API WP Error: ' . $response->get_error_message(), true );
        return false;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    if ( $status_code !== 200 ) {
        sumai_log_event( "OpenAI API HTTP Error: Status {$status_code}. Body: " . $response_body, true );
        return false;
    }

    sumai_log_event( 'OpenAI API request successful (Status: 200).' );
    $data = json_decode( $response_body, true );

    // More robust check for response structure
    if ( ! isset( $data['choices'][0]['message']['content'] ) || ! is_string($data['choices'][0]['message']['content']) ) {
        sumai_log_event( 'Error: Invalid OpenAI API response structure (missing/invalid content). Body: ' . $response_body, true );
        return false;
    }

    $json_content_string = $data['choices'][0]['message']['content'];
    $parsed_content = json_decode( $json_content_string, true );

    // Check if JSON decoding failed or if required keys are missing/empty
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array($parsed_content) || empty( $parsed_content['title'] ) || empty( $parsed_content['summary'] ) ) {
         sumai_log_event( 'Error: Failed to parse valid JSON object with title/summary from OpenAI response. Raw content: ' . $json_content_string, true );
         return false;
    }

    return array(
        'title' => trim( $parsed_content['title'] ), // Trim whitespace
        'content' => wp_kses_post( trim( $parsed_content['summary'] ) ),
    );
}

/**
 * Retrieves and decrypts the OpenAI API key from settings.
 */
function sumai_get_api_key(): string {
    static $cached_key = null; // Cache result per request

    if ( $cached_key !== null ) return $cached_key; // Return cached result ('', or the key)

    // Prioritize constant defined in wp-config.php
    if ( defined( 'SUMAI_OPENAI_API_KEY' ) && ! empty( SUMAI_OPENAI_API_KEY ) ) {
        $cached_key = SUMAI_OPENAI_API_KEY;
        return $cached_key;
    }

    // Fallback to database option if constant is not defined
    $options = get_option( SUMAI_SETTINGS_OPTION );
    $encrypted_key = isset( $options['api_key'] ) ? $options['api_key'] : '';

    if ( empty( $encrypted_key ) ) {
        $cached_key = ''; return ''; // Cache empty
    }
    if ( ! function_exists('openssl_decrypt') || ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
         sumai_log_event( 'Cannot decrypt API key from DB: OpenSSL missing or AUTH_KEY not defined.', true );
         $cached_key = ''; return ''; // Cache empty
    }

    $decoded = base64_decode( $encrypted_key, true );
    if ($decoded === false) {
        sumai_log_event( 'Failed to base64 decode API key from DB.', true );
        $cached_key = ''; return '';
    }

    $cipher = 'aes-256-cbc';
    $ivlen = openssl_cipher_iv_length( $cipher );
     if ($ivlen === false || strlen($decoded) <= $ivlen) {
        sumai_log_event( 'Invalid encrypted API key format from DB (IV length).', true );
        $cached_key = ''; return '';
    }

    $iv = substr( $decoded, 0, $ivlen );
    $ciphertext_raw = substr( $decoded, $ivlen );
    $decryption_key = AUTH_KEY;
    $decrypted = openssl_decrypt( $ciphertext_raw, $cipher, $decryption_key, OPENSSL_RAW_DATA, $iv );

    if ( $decrypted === false ) {
        sumai_log_event( 'Failed to decrypt API key from DB. Check AUTH_KEY and if key was saved correctly.', true );
        $cached_key = ''; return '';
    }

    // Cache the successfully decrypted key
    $cached_key = $decrypted;
    return $cached_key;
}

/**
 * Validates the OpenAI API Key via a simple API call.
 */
function sumai_validate_api_key( string $api_key ): bool {
    if ( empty( $api_key ) ) return false;

    $response = wp_remote_get( 'https://api.openai.com/v1/models', [
        'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
        'timeout' => 15,
    ]);

    if ( is_wp_error( $response ) ) {
        sumai_log_event( 'API Key Validation WP Error: ' . $response->get_error_message(), true );
        return false;
    }
    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code === 200 ) {
        sumai_log_event( 'API Key validation successful.' );
        return true;
    } else {
        sumai_log_event( "API Key validation failed: Status {$status_code}.", true );
        return false;
    }
}

/* -------------------------------------------------------------------------
 * 7. POST SIGNATURE
 * ------------------------------------------------------------------------- */

/**
 * Appends the configured signature to the post content on the frontend.
 */
function sumai_append_signature_to_content( $content ) {
    if ( is_singular( 'post' ) && ! is_admin() && in_the_loop() && is_main_query() ) {
         $options = get_option( SUMAI_SETTINGS_OPTION, array() );
         $signature = isset( $options['post_signature'] ) ? trim( $options['post_signature'] ) : '';
         if ( ! empty( $signature ) && strpos( $content, $signature ) === false ) { // Basic check to avoid duplicates
              $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . wp_kses_post( $signature );
         }
    }
    return $content;
}
add_filter( 'the_content', 'sumai_append_signature_to_content', 99 );

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action( 'admin_menu', 'sumai_add_admin_menu' );
add_action( 'admin_init', 'sumai_register_settings' );

function sumai_add_admin_menu() {
    add_options_page( __( 'Sumai Settings', 'sumai' ), __( 'Sumai', 'sumai' ), 'manage_options', 'sumai-settings', 'sumai_render_settings_page' );
}

function sumai_register_settings() {
    register_setting( 'sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings' );
}

/**
 * Sanitize settings before saving.
 */
function sumai_sanitize_settings( $input ): array {
    $sanitized_input = array();
    $current_settings = get_option( SUMAI_SETTINGS_OPTION, array() );

    // Feed URLs
    if ( isset( $input['feed_urls'] ) ) {
        $urls = array_map( 'trim', explode( "\n", sanitize_textarea_field( $input['feed_urls'] ) ) );
        $valid_urls = [];
        $invalid_detected = false;
        foreach ($urls as $url) {
             if (!empty($url)) {
                 if (filter_var($url, FILTER_VALIDATE_URL)) {
                     $valid_urls[] = $url;
                 } else {
                     $invalid_detected = true;
                 }
             }
        }
        if ($invalid_detected) {
             add_settings_error( 'sumai_settings', 'invalid_feed_url', __( 'One or more invalid feed URLs were removed.', 'sumai' ), 'warning' );
        }
        if ( count( $valid_urls ) > SUMAI_MAX_FEED_URLS ) {
            $valid_urls = array_slice( $valid_urls, 0, SUMAI_MAX_FEED_URLS );
            add_settings_error( 'sumai_settings', 'feed_limit_exceeded', sprintf( __( 'Only the first %d valid feed URLs were saved.', 'sumai' ), SUMAI_MAX_FEED_URLS ), 'warning' );
        }
        $sanitized_input['feed_urls'] = implode( "\n", $valid_urls );
    }

    // Prompts
    $sanitized_input['context_prompt'] = isset( $input['context_prompt'] ) ? sanitize_textarea_field( $input['context_prompt'] ) : '';
    $sanitized_input['title_prompt'] = isset( $input['title_prompt'] ) ? sanitize_textarea_field( $input['title_prompt'] ) : '';

    // Schedule Time
    if ( isset( $input['schedule_time'] ) ) {
         $time = sanitize_text_field( $input['schedule_time'] );
         $sanitized_input['schedule_time'] = preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time )
            ? $time
            : ($current_settings['schedule_time'] ?? '03:00'); // Revert if invalid
    }

    // Draft Mode
    $sanitized_input['draft_mode'] = ( isset( $input['draft_mode'] ) && $input['draft_mode'] == '1' ) ? 1 : 0;

    // Post Signature
    $sanitized_input['post_signature'] = isset( $input['post_signature'] ) ? wp_kses_post( $input['post_signature'] ) : '';

    // API Key (Encrypt if changed)
    if ( isset( $input['api_key'] ) ) {
        $new_api_key = sanitize_text_field( $input['api_key'] );
        $current_encrypted = $current_settings['api_key'] ?? '';
        // Only try decrypting if there's a current encrypted key
        $current_decrypted = '';
        if ($current_encrypted) {
            // Manually decrypt to compare; sumai_get_api_key caches
            if ( function_exists('openssl_decrypt') && defined('AUTH_KEY') && AUTH_KEY ) {
                $decoded = base64_decode( $current_encrypted, true );
                if ($decoded !== false) {
                    $cipher = 'aes-256-cbc';
                    $ivlen = openssl_cipher_iv_length( $cipher );
                    if ($ivlen !== false && strlen($decoded) > $ivlen) {
                        $iv = substr( $decoded, 0, $ivlen );
                        $ciphertext_raw = substr( $decoded, $ivlen );
                        $decryption_key = AUTH_KEY;
                        $decrypted_temp = openssl_decrypt( $ciphertext_raw, $cipher, $decryption_key, OPENSSL_RAW_DATA, $iv );
                        if ($decrypted_temp !== false) {
                            $current_decrypted = $decrypted_temp;
                        }
                    }
                }
            }
        }

        // Re-encrypt if new key provided AND it's different from the current one (or if no current one)
        // Also handle the case where the placeholder '********************' is submitted - treat as no change
        if ( ! empty( $new_api_key ) && $new_api_key !== '********************' && $new_api_key !== $current_decrypted ) {
            if ( function_exists('openssl_encrypt') && defined('AUTH_KEY') && AUTH_KEY ) {
                $cipher = 'aes-256-cbc';
                $ivlen = openssl_cipher_iv_length( $cipher );
                 if (false === $ivlen) {
                      add_settings_error( 'sumai_settings', 'api_key_encrypt_fail', __( 'Failed get IV length for encryption. Key not saved.', 'sumai' ), 'error' );
                      $sanitized_input['api_key'] = $current_encrypted; // Keep old one
                 } else {
                    $iv = openssl_random_pseudo_bytes( $ivlen );
                    $encrypted = openssl_encrypt( $new_api_key, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv );
                    if ( $encrypted !== false && $iv !== false ) {
                        $sanitized_input['api_key'] = base64_encode( $iv . $encrypted );
                        sumai_log_event('New OpenAI API key encrypted and saved.');
                    } else {
                        add_settings_error( 'sumai_settings', 'api_key_encrypt_fail', __( 'Failed to encrypt new API key. Key not saved.', 'sumai' ), 'error' );
                        $sanitized_input['api_key'] = $current_encrypted; // Keep old one
                    }
                }
            } else {
                 add_settings_error( 'sumai_settings', 'api_key_encrypt_env', __( 'Cannot encrypt API key (OpenSSL/AUTH_KEY missing). Key not saved.', 'sumai' ), 'error' );
                 $sanitized_input['api_key'] = $current_encrypted; // Keep old one
            }
        } elseif ( empty( $new_api_key ) && !empty($current_encrypted) ) {
             // If field is submitted empty, and a key was previously set, keep the old one
             $sanitized_input['api_key'] = $current_encrypted;
             add_settings_error( 'sumai_settings', 'api_key_not_cleared', __( 'API key field was empty, existing key retained. To clear, delete and reactivate plugin or manage via database.', 'sumai' ), 'warning' );
        } else {
            // Key is the same, wasn't set, or placeholder submitted, keep current value (which might be empty)
            $sanitized_input['api_key'] = $current_encrypted;
        }
    } else {
         // If api_key is not in the submitted $input array at all, keep the existing one.
         $sanitized_input['api_key'] = $current_settings['api_key'] ?? '';
    }


    return $sanitized_input;
}


/**
 * Render the settings page HTML.
 */
function sumai_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Handle manual generation trigger POST request
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['sumai_generate_now'] ) && check_admin_referer( 'sumai_generate_now_action' ) ) {
        // Ensure required admin files are loaded for manual trigger context
        if ( ! function_exists( 'wp_insert_post' ) ) {
             require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $result = sumai_generate_daily_summary( true ); // Force fetch for manual run
        if ( $result !== false ) {
            add_settings_error( 'sumai_settings', 'manual_gen_success', sprintf(__( 'Manual summary post generated (Post ID: %d).', 'sumai' ), $result), 'success' );
        } else {
            add_settings_error( 'sumai_settings', 'manual_gen_error', __( 'Manual summary generation failed. Check Sumai logs.', 'sumai' ), 'error' );
        }
         // Persist notices after potential redirect by settings save
         set_transient('settings_errors', get_settings_errors(), 30);
         // Redirect to clear POST and show messages
         wp_safe_redirect(admin_url('options-general.php?page=sumai-settings'));
         exit;
    }
     // Display persistent notices
    $admin_notices = get_transient('settings_errors');
    if ($admin_notices) {
        settings_errors('sumai_settings', false, true); // Display notices stored in transient
        delete_transient('settings_errors');
    }


    $options = get_option( SUMAI_SETTINGS_OPTION, array() );
    $options = wp_parse_args($options, array( /* Default values just in case */
        'feed_urls' => '', 'context_prompt' => '', 'title_prompt' => '',
        'api_key' => '', 'draft_mode' => 0, 'schedule_time' => '03:00',
        'post_signature' => '',
    ));
    $api_key_display = ! empty( $options['api_key'] ) ? '********************' : '';
    $feed_urls_count = count( array_filter( explode( "\n", $options['feed_urls'] ) ) );
    ?>
    <div class="wrap sumai-settings-wrap">
        <h1><?php esc_html_e( 'Sumai Settings', 'sumai' ); ?></h1>

        <?php settings_errors( 'sumai_settings' ); // Display standard settings errors ?>

        <div id="sumai-tabs">
             <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'sumai' ); ?>">
                <a href="#tab-main" class="nav-tab nav-tab-active"><?php esc_html_e( 'Main Settings', 'sumai' ); ?></a>
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
                                    <textarea id="sumai-feed-urls" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[feed_urls]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Enter one valid RSS/Atom feed URL per line', 'sumai' ); ?>"><?php echo esc_textarea( $options['feed_urls'] ); ?></textarea>
                                    <p class="description">
                                        <?php printf( esc_html__( 'Enter up to %d feed URLs. Currently saved: %d.', 'sumai' ), SUMAI_MAX_FEED_URLS, $feed_urls_count ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-api-key"><?php esc_html_e( 'OpenAI API Key', 'sumai' ); ?></label></th>
                                <td>
                                    <input type="password" id="sumai-api-key" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[api_key]" value="<?php echo esc_attr( $api_key_display ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter new key to update', 'sumai' ); ?>" autocomplete="new-password" />
                                    <button type="button" id="test-api-button" class="button button-secondary"><?php esc_html_e( 'Test API Key', 'sumai' ); ?></button>
                                    <span id="api-test-result" style="margin-left: 10px;"></span>
                                    <p class="description">
                                        <?php esc_html_e( 'Securely stored OpenAI API key.', 'sumai' ); ?>
                                        <?php if ( ! empty( $options['api_key'] ) ) : ?>
                                            <br/><em><?php esc_html_e( 'Key is set. Leave blank to keep it, enter new key to replace.', 'sumai' ); ?></em>
                                        <?php endif; ?>
                                         <?php if ( ! function_exists('openssl_encrypt') || ! defined('AUTH_KEY') || !AUTH_KEY) : ?>
                                            <br/><strong style="color: red;"><?php esc_html_e( 'Warning: Cannot securely store API key (OpenSSL/AUTH_KEY missing).', 'sumai' ); ?></strong>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="sumai-context-prompt"><?php esc_html_e( 'AI Summary Prompt', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-context-prompt" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[context_prompt]" rows="4" class="large-text"><?php echo esc_textarea( $options['context_prompt'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Instructions for AI summary generation (tone, length, focus).', 'sumai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-title-prompt"><?php esc_html_e( 'AI Title Prompt', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-title-prompt" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[title_prompt]" rows="3" class="large-text"><?php echo esc_textarea( $options['title_prompt'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Instructions for AI post title generation.', 'sumai' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Publish Status', 'sumai' ); ?></th>
                                <td>
                                    <fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Publish Status', 'sumai' ); ?></span></legend>
                                        <label><input type="radio" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[draft_mode]" value="0" <?php checked( 0, $options['draft_mode'] ); ?>> <?php esc_html_e( 'Publish Immediately', 'sumai' ); ?></label><br>
                                        <label><input type="radio" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[draft_mode]" value="1" <?php checked( 1, $options['draft_mode'] ); ?>> <?php esc_html_e( 'Save as Draft', 'sumai' ); ?></label>
                                        <p class="description"><?php esc_html_e( 'Generated post status.', 'sumai' ); ?></p>
                                    </fieldset>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="sumai-schedule-time"><?php esc_html_e( 'Schedule Time', 'sumai' ); ?></label></th>
                                <td>
                                    <input type="time" id="sumai-schedule-time" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[schedule_time]" value="<?php echo esc_attr( $options['schedule_time'] ); ?>" class="regular-text" />
                                    <p class="description">
                                        <?php esc_html_e( 'Daily generation time (HH:MM, 24h). Site timezone:', 'sumai' ); ?>
                                        <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>.
                                        <?php
                                            $next_run = wp_next_scheduled( SUMAI_CRON_HOOK );
                                            echo '<br/>' . ($next_run
                                                ? sprintf(esc_html__('Next scheduled run: %s', 'sumai'), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ))
                                                : esc_html__('Not currently scheduled. Save settings to schedule.', 'sumai'));
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sumai-post-signature"><?php esc_html_e( 'Post Signature', 'sumai' ); ?></label></th>
                                <td>
                                    <textarea id="sumai-post-signature" name="<?php echo esc_attr( SUMAI_SETTINGS_OPTION ); ?>[post_signature]" rows="5" class="large-text"><?php echo esc_textarea( $options['post_signature'] ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Text/HTML appended to each generated post. Leave blank for no signature.', 'sumai' ); ?></p>
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
                     <p><?php esc_html_e( 'Manually trigger the summary generation process using current settings.', 'sumai' ); ?></p>
                     <form method="post" action="">
                         <?php wp_nonce_field( 'sumai_generate_now_action' ); ?>
                         <input type="submit" name="sumai_generate_now" class="button button-primary" value="<?php esc_attr_e( 'Generate Summary Now', 'sumai' ); ?>">
                     </form>
                 </div>
                 <div class="card">
                     <h3><?php esc_html_e( 'Test Feed Fetching', 'sumai' ); ?></h3>
                     <p><?php esc_html_e( 'Test configured RSS feeds access and check for new content.', 'sumai' ); ?></p>
                     <button type="button" id="test-feed-button" class="button button-secondary"><?php esc_html_e( 'Test Feeds', 'sumai' ); ?></button>
                     <div id="feed-test-result" class="sumai-test-results"></div>
                 </div>
                 <div class="card">
                    <h3><?php esc_html_e( 'External Cron Trigger', 'sumai' ); ?></h3>
                    <p><?php esc_html_e( 'Use this URL with an external cron service if WP-Cron is unreliable:', 'sumai' ); ?></p>
                    <?php
                        $cron_token = get_option( SUMAI_CRON_TOKEN_OPTION );
                        if ($cron_token) {
                            $trigger_url = add_query_arg( [ 'sumai_trigger' => '1', 'token' => $cron_token ], site_url( '/' ) );
                            echo '<input type="text" class="large-text" value="' . esc_url($trigger_url) . '" readonly onfocus="this.select();">';
                            echo '<p class="description">' . esc_html__('This URL uses a hex token (safe for URLs). When using in shell commands (like wget or curl), it\'s safest to wrap the URL in single quotes (\'URL\'). Example:', 'sumai') . '<br>';
                            echo '<code>wget -qO- \'' . esc_url($trigger_url) . '\' > /dev/null 2>&1</code></p>';
                        } else {
                             echo '<p>' . esc_html__('Cron token not generated. Activate or re-save settings.', 'sumai') . '</p>';
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
        .sumai-settings-wrap .card { padding: 15px; border: 1px solid #ccc; background: #fff; margin: 20px 0; }
        .sumai-settings-wrap .nav-tab-wrapper { margin-bottom: 20px; }
        .sumai-test-results { margin-top: 10px; padding: 10px; background-color: #f9f9f9; border: 1px solid #ccc; max-height: 400px; overflow-y: auto; display: none; white-space: pre-wrap; font-family: monospace; }
        #api-test-result span { font-weight: bold; }
    </style>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Tab Navigation
        var $tabs = $('#sumai-tabs');
        var $navLinks = $tabs.find('.nav-tab');
        var $tabContent = $tabs.find('.tab-content');

        function showTab(targetHash) {
            $navLinks.removeClass('nav-tab-active');
            $tabContent.hide();
            var $activeLink = $navLinks.filter('[href="' + targetHash + '"]');
            if ($activeLink.length === 0) {
                 $activeLink = $navLinks.first(); // Default to first if hash invalid
                 targetHash = $activeLink.attr('href');
            }
            $activeLink.addClass('nav-tab-active');
            $(targetHash).show();
        }

        $navLinks.on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');
            showTab(target);
            // Update hash in URL without jumping
            if (history.pushState) { history.pushState(null, null, target); } else { window.location.hash = target; }
        });

        // Show initial tab based on hash or default to first
        showTab(window.location.hash || $navLinks.first().attr('href'));

        // API Key Test AJAX
        $('#test-api-button').on('click', function() {
            var $button = $(this);
            var $resultSpan = $('#api-test-result');
            var apiKeyInput = $('#sumai-api-key').val();
            var keyToSend = (apiKeyInput === '********************') ? '' : apiKeyInput; // Send empty to test saved, else send input

            $button.prop('disabled', true).text('<?php esc_js( __( 'Testing...', 'sumai' ) ); ?>');
            $resultSpan.html('<?php esc_js( __( 'Testing...', 'sumai' ) ); ?>').css('color', 'inherit');

            $.ajax({
                 url: ajaxurl, type: 'POST',
                data: { action: 'sumai_test_api_key', _ajax_nonce: '<?php echo wp_create_nonce( 'sumai_test_api_key_nonce' ); ?>', api_key_to_test: keyToSend },
                success: function(response) {
                    if (response.success) { $resultSpan.html(response.data.message).css('color', 'green'); }
                    else { $resultSpan.html(response.data.message).css('color', 'red'); }
                },
                error: function(xhr) {
                    $resultSpan.html('<?php esc_js( __( 'AJAX Error', 'sumai' ) ); ?>').css('color', 'red');
                    console.error('Sumai API Test Error:', xhr.responseText);
                },
                complete: function() { $button.prop('disabled', false).text('<?php esc_js( __( 'Test API Key', 'sumai' ) ); ?>'); }
            });
        });

        // Feed Test AJAX
        $('#test-feed-button').on('click', function() {
            var $button = $(this);
            var $resultDiv = $('#feed-test-result');
            $button.prop('disabled', true).text('<?php esc_js( __( 'Testing...', 'sumai' ) ); ?>');
            $resultDiv.html('<?php esc_js( __( 'Testing feeds...', 'sumai' ) ); ?>').css('color', 'inherit').show();
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'sumai_test_feeds', _ajax_nonce: '<?php echo wp_create_nonce( 'sumai_test_feeds_nonce' ); ?>' },
                success: function(response) {
                    if (response.success) { $resultDiv.html(response.data.message); } // Message contains pre-formatted text
                    else { $resultDiv.html(response.data.message).css('color', 'red'); }
                },
                error: function(xhr) {
                    $resultDiv.html('<?php esc_js( __( 'AJAX Error', 'sumai' ) ); ?>').css('color', 'red');
                     console.error('Sumai Feed Test Error:', xhr.responseText);
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
    check_ajax_referer( 'sumai_test_api_key_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sumai' ) ], 403 );

    $key_to_test = isset( $_POST['api_key_to_test'] ) ? sanitize_text_field( $_POST['api_key_to_test'] ) : '';
    $testing_saved = false;
    if ( empty( $key_to_test ) ) {
        $key_to_test = sumai_get_api_key();
        if ( empty( $key_to_test ) ) wp_send_json_error( [ 'message' => __( 'API key not set. Save key first.', 'sumai' ) ] );
        $testing_saved = true;
    }

    if ( sumai_validate_api_key( $key_to_test ) ) {
        $msg = $testing_saved ? __( 'Saved API key validation successful.', 'sumai' ) : __( 'Provided API key validation successful.', 'sumai' );
        wp_send_json_success( [ 'message' => $msg ] );
    } else {
        $msg = $testing_saved ? __( 'Saved API key validation failed. Check logs.', 'sumai' ) : __( 'Provided API key validation failed. Check key & logs.', 'sumai' );
        wp_send_json_error( [ 'message' => $msg ] );
    }
}

/** AJAX handler for testing feeds. */
function sumai_ajax_test_feeds() {
    check_ajax_referer( 'sumai_test_feeds_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => __( 'Permission denied.', 'sumai' ) ], 403 );

    $options = get_option( SUMAI_SETTINGS_OPTION, [] );
    $feed_urls_raw = $options['feed_urls'] ?? '';
    $feed_urls = array_slice( array_filter( array_map( 'trim', explode( "\n", $feed_urls_raw ) ) ), 0, SUMAI_MAX_FEED_URLS );

    if ( empty( $feed_urls ) ) wp_send_json_error( [ 'message' => __( 'No feed URLs configured.', 'sumai' ) ] );

    $test_output = sumai_test_feeds( $feed_urls );
    wp_send_json_success( [ 'message' => esc_html($test_output) ] ); // Ensure output is escaped for safety, although pre tag handles it mostly
}

/** Tests configured RSS feeds. */
function sumai_test_feeds( array $feed_urls ): string {
    if ( ! function_exists( 'fetch_feed' ) ) include_once ABSPATH . WPINC . '/feed.php';

    $output = "--- Sumai Feed Test Results ---\n";
    $output .= "Time: " . wp_date('Y-m-d H:i:s T') . "\n";
    $processed_guids = get_option( SUMAI_PROCESSED_GUIDS_OPTION, [] );
    $output .= count( $processed_guids ) . " processed GUIDs tracked.\n\n";
    $found_new_overall = false;

    foreach ( $feed_urls as $index => $url ) {
        $url = esc_url_raw( trim( $url ) );
        if ( empty( $url ) ) continue;

        $output .= "--- Feed #" . ($index + 1) . ": {$url} ---\n";
        // Attempt to clear cache for test - may not always work
        // delete_transient( 'feed_' . md5( $url ) ); delete_transient( 'feed_mod_' . md5( $url ) );
        $feed = fetch_feed( $url );

        if ( is_wp_error( $feed ) ) { $output .= "❌ Error: " . esc_html( $feed->get_error_message() ) . "\n\n"; continue; }

        $items = $feed->get_items( 0, 5 ); // Check latest 5 for test display
        if ( empty( $items ) ) { $output .= "⚠️ Feed OK but no items found (or in first 5).\n\n"; continue; }

        $output .= "✅ Feed OK. Found " . count( $items ) . " items (showing details for up to 5):\n";
        $found_new_this_feed = false;
        foreach ($items as $item_index => $item) {
             $guid = $item->get_id( true );
             $title = $item->get_title() ?: 'N/A';
             $date = $item->get_date('U');
             $output .= "- Item " . ($item_index + 1) . ": " . esc_html( $title ) . " (GUID: " . esc_html( $guid ) . ")\n";
             if ( isset( $processed_guids[ $guid ] ) ) {
                 $output .= "  Status: Processed (" . wp_date('Y-m-d H:i', $processed_guids[$guid]) . ")\n";
             } else {
                 $output .= "  Status: ✨ NEW\n";
                 $found_new_this_feed = true;
                 $found_new_overall = true;
             }
        }
        if (!$found_new_this_feed) $output .= "  ℹ️ No new items in latest checked.\n";
        $output .= "\n";
        unset( $feed, $items ); // Cleanup
    }
    $output .= "--- Summary ---\n";
    $output .= $found_new_overall ? "✅ New content available for next run." : "ℹ️ No new content detected in latest checks.";
    return $output;
}


/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING
 * ------------------------------------------------------------------------- */

/** Ensure log directory exists and is writable. Returns log file path or false. */
function sumai_ensure_log_dir() {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit( $upload_dir['basedir'] ) . SUMAI_LOG_DIR_NAME;
    $log_file = trailingslashit( $log_dir ) . SUMAI_LOG_FILE_NAME;

    if ( ! is_dir( $log_dir ) ) {
        if ( ! wp_mkdir_p( $log_dir ) ) {
             error_log("Sumai Log Error: Failed to create directory: " . $log_dir); // Use PHP error log if our dir fails
             return false;
        }
        // Add protection files after successful creation
        @file_put_contents( $log_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
        @file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
    }

    // Check writability specifically
    if ( ! is_writable( $log_dir ) ) {
        error_log( "Sumai Log Error: Directory not writable: " . $log_dir );
        return false;
    }
    // Check if file exists and is writable, create if not
    if ( ! file_exists( $log_file ) ) {
         if ( false === @file_put_contents( $log_file, '' ) ) { // Try creating it
              error_log( "Sumai Log Error: Failed to create log file: " . $log_file );
              return false;
         }
    }
     if ( ! is_writable( $log_file ) ) {
         error_log( "Sumai Log Error: Log file not writable: " . $log_file );
         return false;
     }

    return $log_file;
}

/** Log plugin events. */
function sumai_log_event( string $message, bool $is_error = false ) {
    $log_file = sumai_ensure_log_dir();
    if ( ! $log_file ) return;

    $prefix = '[' . wp_date( 'Y-m-d H:i:s T' ) . ']' . ($is_error ? ' [ERROR]' : ' [INFO]');
    $log_line = $prefix . ' ' . wp_strip_all_tags( trim( $message ) ) . PHP_EOL; // Trim message
    @file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX );
}

/** Prune old log entries. */
function sumai_prune_logs() {
    sumai_log_event( 'Running scheduled log pruning...' );
    $log_file = sumai_ensure_log_dir();
    if ( ! $log_file || ! file_exists( $log_file ) || ! is_readable( $log_file ) ) return;

    $lines = @file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( $lines === false || empty($lines) ) return;

    $cutoff_timestamp = time() - SUMAI_LOG_TTL;
    $retained_lines = [];
    $lines_pruned = 0;

    foreach ( $lines as $line ) {
        if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/', $line, $matches ) ) { // Allow for timezone offsets like +00:00
            // Try parsing the date string to get a timestamp
            $timestamp = false;
            try {
                 // Use DateTime to handle timezones correctly if present
                 $dt = new DateTime($matches[1]);
                 $timestamp = $dt->getTimestamp();
            } catch (Exception $e) {
                 // Fallback for simpler formats if DateTime fails
                 $timestamp = strtotime( $matches[1] );
            }

            if ( $timestamp !== false && $timestamp >= $cutoff_timestamp ) {
                $retained_lines[] = $line;
            } else {
                $lines_pruned++;
            }
        } else {
             $retained_lines[] = $line; // Keep lines without standard timestamp format
        }
    }

    if ( $lines_pruned > 0 ) {
        if ( false !== @file_put_contents( $log_file, implode( PHP_EOL, $retained_lines ) . PHP_EOL, LOCK_EX ) ) {
             sumai_log_event( "Log pruning complete. Removed {$lines_pruned} old entries." );
        } else {
             sumai_log_event( "Log pruning failed: Could not write updated log file.", true );
        }
    } else {
        sumai_log_event( "Log pruning complete. No old entries found." );
    }
}

/** Retrieves debug information. */
function sumai_get_debug_info(): array {
    $debug_info = []; $options = get_option( SUMAI_SETTINGS_OPTION, [] );
    if ( isset( $options['api_key'] ) && ! empty( $options['api_key'] ) ) $options['api_key'] = '***';
    $debug_info['settings'] = $options;
    $cron_jobs = _get_cron_array() ?: [];
    $debug_info['cron_jobs'] = [];
    foreach ( $cron_jobs as $time => $hooks ) {
        foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $hook_name) {
            if ( isset( $hooks[$hook_name] ) ) {
                 $event_key = key($hooks[$hook_name]); // Get the first key for this hook/time
                 $schedule = isset($hooks[$hook_name][$event_key]['schedule']) ? $hooks[$hook_name][$event_key]['schedule'] : 'N/A';
                $debug_info['cron_jobs'][$hook_name] = [
                    'next_run_utc' => get_date_from_gmt( date( 'Y-m-d H:i:s', $time ), 'Y-m-d H:i:s T' ),
                    'next_run_site' => wp_date( 'Y-m-d H:i:s T', $time ),
                    'schedule' => $schedule
                ];
            }
        }
    }
    $log_file = sumai_ensure_log_dir();
    $debug_info['log_file_path'] = $log_file ?: 'Log directory unwritable or not found.';
    $debug_info['log_writable'] = $log_file && is_writable($log_file);
    $debug_info['log_readable'] = $log_file && is_readable($log_file);
    if ( $debug_info['log_readable'] ) {
        // Read entire file, might be large but pruning should keep it manageable
        $log_content = @file_get_contents( $log_file );
        if ($log_content !== false) {
            $log_lines = explode( "\n", trim( $log_content ) );
            $debug_info['recent_logs'] = array_slice( $log_lines, -50 ); // Show last 50 lines
        } else {
             $debug_info['recent_logs'] = ['Error: Could not read log file content.'];
        }
    } else {
        $debug_info['recent_logs'] = ['Error: Log file not found or not readable at the path above.'];
    }
    global $wp_version;
    $debug_info['system'] = [
        'php_version' => phpversion(), 'wp_version' => $wp_version,
        'wp_timezone' => wp_timezone_string(), 'wp_cron_enabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'No' : 'Yes',
        'wp_memory_limit' => WP_MEMORY_LIMIT,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        'openssl_loaded' => extension_loaded('openssl') ? 'Yes' : 'No (' . (defined('AUTH_KEY') && AUTH_KEY ? 'AUTH_KEY is set' : 'AUTH_KEY missing') . ')',
        'curl_loaded' => extension_loaded('curl') ? 'Yes' : 'No',
        'mbstring_loaded' => extension_loaded('mbstring') ? 'Yes' : 'No',
    ];
    return $debug_info;
}

/** Renders the debug information. */
function sumai_render_debug_info() {
    $debug_info = sumai_get_debug_info();
    ?>
    <div class="sumai-debug-info">
        <h3><?php esc_html_e( 'Plugin Settings', 'sumai' ); ?></h3>
        <table class="wp-list-table widefat fixed striped"><tbody>
            <?php foreach ($debug_info['settings'] as $key => $value): ?>
                <tr><td style="width: 30%;"><strong><?php echo esc_html( $key ); ?></strong></td><td><pre><?php echo esc_html( is_array($value) ? print_r($value, true) : $value ); ?></pre></td></tr>
            <?php endforeach; ?>
            <tr><td><strong><?php esc_html_e( 'Processed GUIDs', 'sumai' ); ?></strong></td><td><?php echo count(get_option(SUMAI_PROCESSED_GUIDS_OPTION, [])); ?></td></tr>
            <tr><td><strong><?php esc_html_e( 'Cron Token', 'sumai' ); ?></strong></td><td><?php echo esc_html( get_option(SUMAI_CRON_TOKEN_OPTION) ? substr(get_option(SUMAI_CRON_TOKEN_OPTION), 0, 8) . '...' : 'Not Set' ); ?></td></tr>
        </tbody></table>

        <h3><?php esc_html_e( 'Scheduled Tasks (Sumai)', 'sumai' ); ?></h3>
        <?php if ( ! empty( $debug_info['cron_jobs'] ) ): ?>
            <table class="wp-list-table widefat fixed striped"><thead><tr><th>Hook</th><th>Next Run (Site Time)</th><th>Schedule</th></tr></thead><tbody>
                <?php foreach ($debug_info['cron_jobs'] as $hook => $details): ?>
                    <tr><td><?php echo esc_html($hook); ?></td><td><?php echo esc_html($details['next_run_site']); ?> (<?php echo esc_html($details['next_run_utc']); ?>)</td><td><?php echo esc_html($details['schedule']); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        <?php else: ?><p><?php esc_html_e( 'No Sumai cron jobs scheduled.', 'sumai' ); ?></p><?php endif; ?>

        <h3><?php esc_html_e( 'System Information', 'sumai' ); ?></h3>
         <table class="wp-list-table widefat fixed striped"><tbody>
            <?php foreach ($debug_info['system'] as $key => $value): ?>
                <tr><td style="width: 30%;"><strong><?php echo esc_html( str_replace('_', ' ', ucfirst($key)) ); ?></strong></td><td><?php echo esc_html( $value ); ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>

        <h3><?php esc_html_e( 'Recent Log Entries (Last 50)', 'sumai' ); ?></h3>
        <p><em><?php printf(esc_html__('Log File Path: %s', 'sumai'), esc_html($debug_info['log_file_path'])); ?></em>
           <em>(<?php
              echo $debug_info['log_readable'] ? 'Readable' : '<span style="color:red;">Not Readable</span>';
              echo ', ';
              echo $debug_info['log_writable'] ? 'Writable' : '<span style="color:red;">Not Writable</span>';
           ?>)</em></p>
        <div class="sumai-log-entries" style="max-height: 400px; overflow-y: auto; background: #f1f1f1; border: 1px solid #ccc; padding: 10px; font-family: monospace; white-space: pre-wrap; word-break: break-word;">
            <?php echo esc_html( implode( "\n", $debug_info['recent_logs'] ) ); ?>
        </div>
    </div>
    <?php
}