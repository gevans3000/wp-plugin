<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Fetches RSS articles, summarizes with OpenAI, and posts a daily summary.
 * Version:     1.1.8
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
    add_option( SUMAI_SETTINGS_OPTION, $defaults, '', 'no' );
    sumai_ensure_log_dir();
    sumai_schedule_daily_event();
    if (!wp_next_scheduled(SUMAI_ROTATE_TOKEN_HOOK)) wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK);
    if (!get_option(SUMAI_CRON_TOKEN_OPTION)) sumai_rotate_cron_token();
    if (!wp_next_scheduled(SUMAI_PRUNE_LOGS_HOOK)) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin activated. V' . get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
}

/**
 * Plugin Deactivation Handler.
 */
function sumai_deactivate() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    wp_clear_scheduled_hook(SUMAI_ROTATE_TOKEN_HOOK);
    wp_clear_scheduled_hook(SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin deactivated.');
}

/* -------------------------------------------------------------------------
 * 2. CRON SCHEDULING & HOOKS
 * ------------------------------------------------------------------------- */

/**
 * Schedule the main daily summary event based on settings.
 */
function sumai_schedule_daily_event() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $schedule_time_str = (isset($options['schedule_time']) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $options['schedule_time'])) ? $options['schedule_time'] : '03:00';
    $site_timezone = wp_timezone();
    $now_gmt = current_time('timestamp', true);
    try {
        $scheduled_dt_today = new DateTime(date('Y-m-d').' '.$schedule_time_str.':00', $site_timezone);
        $scheduled_ts_gmt = $scheduled_dt_today->getTimestamp();
        if ($scheduled_ts_gmt <= $now_gmt) {
            $scheduled_dt_today->modify('+1 day');
            $first_run_gmt = $scheduled_dt_today->getTimestamp();
        } else {
            $first_run_gmt = $scheduled_ts_gmt;
        }
        wp_schedule_event($first_run_gmt, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled. Next: '.wp_date('Y-m-d H:i:s T', $first_run_gmt));
    } catch (Exception $e) {
        sumai_log_event('Error calculating schedule time: '.$e->getMessage().'. Using fallback.', true);
        $first_run_gmt = strtotime('tomorrow 03:00', $now_gmt);
        wp_schedule_event($first_run_gmt, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled (fallback). Next: '.wp_date('Y-m-d H:i:s T', $first_run_gmt));
    }
}

/**
 * Rotates the secure token used for external cron triggers.
 */
function sumai_rotate_cron_token() {
    update_option(SUMAI_CRON_TOKEN_OPTION, bin2hex(random_bytes(16)));
    sumai_log_event('Cron token rotated.');
}

// Reschedule when settings are updated
add_action('update_option_'.SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event', 10, 0);
// Hook the main function directly to the cron event
add_action(SUMAI_CRON_HOOK, 'sumai_generate_daily_summary');
// Hook token rotation and log pruning
add_action(SUMAI_ROTATE_TOKEN_HOOK, 'sumai_rotate_cron_token');
add_action(SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs');

/* -------------------------------------------------------------------------
 * 3. EXTERNAL CRON TRIGGER
 * ------------------------------------------------------------------------- */

add_action('init', 'sumai_check_external_trigger', 5);

/**
 * Check for external cron trigger requests. Validates token and calls the hook.
 */
function sumai_check_external_trigger() {
    if (!isset($_GET['sumai_trigger'], $_GET['token']) || $_GET['sumai_trigger'] !== '1') return;

    $provided_token = sanitize_text_field($_GET['token']);
    $stored_token = get_option(SUMAI_CRON_TOKEN_OPTION);

    if ($stored_token && hash_equals($stored_token, $provided_token)) {
        sumai_log_event('External cron trigger received and validated.');
        $lock_transient_key = 'sumai_external_trigger_lock';
        if (false === get_transient($lock_transient_key)) {
            set_transient($lock_transient_key, time(), MINUTE_IN_SECONDS * 5);
            sumai_log_event('Executing summary generation via external trigger...');
            // Trigger the same hook as WP-Cron, letting the main function handle dependencies
            do_action(SUMAI_CRON_HOOK);
            // Consider adding exit('Sumai trigger processed.'); here if needed.
        } else {
            sumai_log_event('External cron trigger skipped, lock active.', true);
        }
    } else {
        sumai_log_event('Invalid external cron trigger token received.', true);
        // Consider status_header(403); exit('Invalid token.');
    }
}

/* -------------------------------------------------------------------------
 * 4. MAIN SUMMARY GENERATION
 * ------------------------------------------------------------------------- */

/**
 * Main function to generate the daily summary post.
 * Fetches feeds, calls API, creates post. Handles dependency loading internally.
 *
 * @param bool $force_fetch If true, ignores processed GUID check (for manual trigger/testing).
 * @return int|false Post ID on success, false on failure.
 */
function sumai_generate_daily_summary( bool $force_fetch = false ) {
    // --- FORCE LOAD ADMIN FILES --- Attempt loading right at the start.
    if ( ! function_exists( 'wp_insert_post' ) ) {
        $admin_includes_path = ABSPATH . 'wp-admin/includes/';
        $post_file = $admin_includes_path . 'post.php';
        $admin_file = $admin_includes_path . 'admin.php'; // Often needed

        sumai_log_event( 'Function wp_insert_post() not found. Attempting load...' );
        if ( file_exists( $admin_file ) ) require_once $admin_file; else sumai_log_event('Warning: admin.php not found.');
        if ( file_exists( $post_file ) ) require_once $post_file; else { sumai_log_event('FATAL: post.php not found. Aborting.', true); return false; }

        if ( ! function_exists( 'wp_insert_post' ) ) {
            sumai_log_event( 'FATAL: FAILED to load post functions after require_once. Aborting.', true );
            return false; // Critical failure
        }
        sumai_log_event( 'Admin includes loaded for background task.' );
    }
    // --- END FORCE LOAD ---

    sumai_log_event('Starting summary generation job.'.($force_fetch ? ' (Forced)' : ''));
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $api_key = sumai_get_api_key();

    // --- Pre-checks ---
    if (empty($api_key)) { sumai_log_event('Error: API key missing.', true); return false; }
    if (empty($options['feed_urls'])) { sumai_log_event('Error: No feed URLs configured.', true); return false; }
    $feed_urls = array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (empty($feed_urls)) { sumai_log_event('Error: No valid feed URLs found.', true); return false; }

    try {
        // --- Fetch New Content ---
        sumai_log_event('Fetching new content...');
        list($new_content, $processed_guids_updates) = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
        if (empty($new_content)) { sumai_log_event('No new content found. Summary skipped.'); return false; }
        sumai_log_event('Fetched '.mb_strlen($new_content).' chars new content.');

        // --- Generate Summary & Title ---
        sumai_log_event('Generating summary via OpenAI...');
        $summary_result = sumai_summarize_text($new_content, $options['context_prompt'] ?? '', $options['title_prompt'] ?? '', $api_key);
        unset($new_content); // Free memory
        if (!$summary_result || empty($summary_result['title'])) { sumai_log_event('Error: Failed to get summary/title from API.', true); return false; }
        sumai_log_event('Summary & title generated.');

        // --- Create Post ---
        sumai_log_event('Preparing to create post...');
        $draft_mode = $options['draft_mode'] ?? 0;

        // Title Uniqueness (wp_unique_post_title function existence checked above)
        $clean_title = trim($summary_result['title'], '"\' ');
        $unique_title = wp_unique_post_title($clean_title);
        if ($unique_title !== $clean_title) sumai_log_event("Title adjusted: '{$clean_title}' -> '{$unique_title}'");

        // Determine Author
        $author_id = 1; // Default to admin 1 for cron
        if (is_user_logged_in() && ($uid = get_current_user_id()) > 0) $author_id = $uid;
        $author_data = get_userdata($author_id);
        if (!$author_data || !$author_data->has_cap('publish_posts')) {
             sumai_log_event("Warning: Author ID {$author_id} invalid/incapable. Falling back to ID 1.", true);
             $author_id = 1;
             $author_data = get_userdata($author_id);
              if (!$author_data || !$author_data->has_cap('publish_posts')) {
                  sumai_log_event('Error: Author ID 1 invalid/incapable. Cannot create post.', true);
                  return false;
              }
        }
        sumai_log_event("Using author ID: {$author_id}.");

        // Prepare Post Data
        $post_data = [
            'post_title'    => $unique_title,
            'post_content'  => $summary_result['content'], // Signature added via filter
            'post_status'   => $draft_mode ? 'draft' : 'publish',
            'post_type'     => 'post',
            'post_author'   => $author_id,
            'meta_input'    => ['_sumai_generated' => true]
        ];

        // Insert Post (wp_insert_post existence checked above)
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) { sumai_log_event("Error creating post: ".$post_id->get_error_message(), true); return false; }
        sumai_log_event("Post created ID: {$post_id}, Status: {$post_data['post_status']}.");

        // --- Update Processed GUIDs ---
        if (!empty($processed_guids_updates)) {
            $processed_guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
            $processed_guids = array_merge($processed_guids, $processed_guids_updates);
            $current_time = time(); $pruned_count = 0;
            foreach ($processed_guids as $guid => $timestamp) { if ($timestamp < ($current_time - SUMAI_PROCESSED_GUID_TTL)) { unset($processed_guids[$guid]); $pruned_count++; } }
            update_option(SUMAI_PROCESSED_GUIDS_OPTION, $processed_guids);
            sumai_log_event("Processed GUIDs updated. Added: ".count($processed_guids_updates).", Pruned: {$pruned_count}. Total: ".count($processed_guids));
        }

        return $post_id;

    } catch (\Throwable $e) {
        sumai_log_event("FATAL error during summary generation: ".$e->getMessage()." in ".$e->getFile()." on line ".$e->getLine(), true);
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
    if (!function_exists('fetch_feed')) { include_once ABSPATH.WPINC.'/feed.php'; }
    if (!function_exists('fetch_feed')) { sumai_log_event('Error: fetch_feed() unavailable.', true); return ['', []]; }

    $combined_content = '';
    $newly_processed_guids = [];
    $current_time = time();
    $content_char_count = 0;
    $processed_guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);

    foreach ($feed_urls as $url) {
        $url = esc_url_raw(trim($url)); if (empty($url)) continue;
        sumai_log_event("Processing feed: {$url}");
        $feed = fetch_feed($url);

        if (is_wp_error($feed)) { sumai_log_event("Error fetch feed {$url}: ".$feed->get_error_message(), true); continue; }
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT);
        if (empty($items)) { sumai_log_event("No items in feed: {$url}"); continue; }

        $added_from_feed = 0;
        sumai_log_event("Found ".count($items)." items in feed: {$url} (Limit: ".SUMAI_FEED_ITEM_LIMIT.")");
        foreach ($items as $item) {
            $guid = $item->get_id(true);
            // Skip if already added in this run OR already processed (unless forcing)
            if (isset($newly_processed_guids[$guid]) || (!$force_fetch && isset($processed_guids[$guid]))) continue;

            $text_content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($item->get_content() ?: $item->get_description())));
            if (empty($text_content)) continue;

            $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST);
            $item_title = strip_tags($item->get_title() ?: 'Untitled');
            $item_full_content = "Source: ".esc_html($feed_title)."\nTitle: ".esc_html($item_title)."\nContent:\n".$text_content."\n\n---\n\n";
            $item_char_count = mb_strlen($item_full_content);

            if (($content_char_count + $item_char_count) > SUMAI_MAX_INPUT_CHARS) {
                sumai_log_event("Skipping item '{$item_title}' - adding it would exceed max input chars ({$content_char_count} + {$item_char_count} > ".SUMAI_MAX_INPUT_CHARS.").");
                break; // Stop processing this feed
            }

            $combined_content .= $item_full_content;
            $content_char_count += $item_char_count;
            $newly_processed_guids[$guid] = $current_time;
            $added_from_feed++;
        }
        if ($added_from_feed > 0) sumai_log_event("Added {$added_from_feed} new items from {$url}. Total chars now: {$content_char_count}");
        unset($feed, $items); // Free memory
    }
    return [$combined_content, $newly_processed_guids];
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
 * @return array|null Array with 'title' and 'content' keys, or null on failure.
 */
function sumai_summarize_text( string $text, string $context_prompt, string $title_prompt, string $api_key ): ?array {
    if (empty($text)) { sumai_log_event('Error: Empty text provided for summarization.', true); return null; }
    // Failsafe truncate, though feed fetcher should prevent this
    if (mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);

    $messages = [
        ['role' => 'system', 'content' => "You are an expert summarizer. Output valid JSON {\"title\": \"...\", \"summary\": \"...\"}. Context: ".($context_prompt ?: "Summarize key points concisely.")." Title Context: ".($title_prompt ?: "Generate a compelling title.")],
        ['role' => 'user', 'content' => "Text:\n\n".$text]
    ];
    $request_body = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 1500,
        'temperature' => 0.6,
        'response_format' => ['type' => 'json_object']
    ];
    $request_args = [
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer '.$api_key],
        'body' => json_encode($request_body),
        'method' => 'POST',
        'timeout' => 90
    ];

    sumai_log_event('Sending request to OpenAI API...');
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $request_args);

    if (is_wp_error($response)) { sumai_log_event('OpenAI WP Error: '.$response->get_error_message(), true); return null; }
    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    if ($status_code !== 200) { sumai_log_event("OpenAI HTTP Error: {$status_code}. Body: ".$response_body, true); return null; }

    $data = json_decode($response_body, true);
    $json_content_string = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($json_content_string)) { sumai_log_event('Error: Invalid API response structure.', true); return null; }

    $parsed_content = json_decode($json_content_string, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed_content) || empty($parsed_content['title']) || !isset($parsed_content['summary'])) {
        sumai_log_event('Error: Failed parsing valid JSON title/summary from API. Raw: '.$json_content_string, true); return null;
    }

    return ['title' => trim($parsed_content['title']), 'content' => trim($parsed_content['summary'])];
}

/**
 * Retrieves and decrypts the OpenAI API key from settings or constant.
 */
function sumai_get_api_key(): string {
    static $cached_key = null;
    if ($cached_key !== null) return $cached_key; // Return cached

    if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) {
        sumai_log_event('Using API Key from constant.');
        return $cached_key = SUMAI_OPENAI_API_KEY;
    }

    $options = get_option(SUMAI_SETTINGS_OPTION);
    $encrypted_key = $options['api_key'] ?? '';
    if (empty($encrypted_key)) return $cached_key = ''; // Not set

    if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
        sumai_log_event('Cannot decrypt API key (OpenSSL/AUTH_KEY missing).', true);
        return $cached_key = '';
    }

    $decoded = base64_decode($encrypted_key, true);
    $cipher = 'aes-256-cbc';
    $ivlen = openssl_cipher_iv_length($cipher);
    if ($decoded === false || $ivlen === false || strlen($decoded) <= $ivlen) {
        sumai_log_event('Error: Invalid stored API key format/decode.', true);
        return $cached_key = '';
    }

    $iv = substr($decoded, 0, $ivlen);
    $ciphertext_raw = substr($decoded, $ivlen);
    $decrypted = openssl_decrypt($ciphertext_raw, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv);

    if ($decrypted === false) {
        sumai_log_event('Error: Failed to decrypt API key.', true);
        return $cached_key = '';
    }
    sumai_log_event('Decrypted API Key from database.');
    return $cached_key = $decrypted;
}

/**
 * Validates the OpenAI API Key format and via a simple API call.
 */
function sumai_validate_api_key(string $api_key): bool {
    if (empty($api_key)) { sumai_log_event('API Key validation: Empty key.', true); return false; }
    if (strpos($api_key, 'sk-') !== 0) { sumai_log_event('API Key validation: Invalid format.', true); return false; }

    $response = wp_remote_get('https://api.openai.com/v1/models', ['headers'=>['Authorization'=>'Bearer '.$api_key],'timeout'=>15]);
    if (is_wp_error($response)) { sumai_log_event('API Key validation WP Error: '.$response->get_error_message(), true); return false; }
    $status_code = wp_remote_retrieve_response_code($response);
    $is_valid = ($status_code === 200);
    if (!$is_valid) {
         $body = wp_remote_retrieve_body($response);
         sumai_log_event("API Key validation failed: Status {$status_code}. Body: ".$body, true);
    } else {
        sumai_log_event('API Key validation successful via API.');
    }
    return $is_valid;
}

/* -------------------------------------------------------------------------
 * 7. POST SIGNATURE
 * ------------------------------------------------------------------------- */

/**
 * Appends the configured signature to the post content on the frontend.
 */
function sumai_append_signature_to_content($content) {
    if (is_singular('post') && !is_admin() && in_the_loop() && is_main_query()) {
        $options = get_option(SUMAI_SETTINGS_OPTION, []);
        $signature = trim($options['post_signature'] ?? '');
        if (!empty($signature)) {
            $signature_html = wp_kses_post($signature);
            // Append only if signature HTML isn't already present
            if (strpos($content, $signature_html) === false) {
                $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . $signature_html;
            }
        }
    }
    return $content;
}
add_filter('the_content', 'sumai_append_signature_to_content', 99);

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'sumai_add_admin_menu');
add_action('admin_init', 'sumai_register_settings');

function sumai_add_admin_menu() {
    add_options_page('Sumai Settings', 'Sumai', 'manage_options', 'sumai-settings', 'sumai_render_settings_page');
}
function sumai_register_settings() {
    register_setting('sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings');
}

/**
 * Sanitize settings before saving.
 */
function sumai_sanitize_settings($input): array {
    $sanitized = [];
    $current = get_option(SUMAI_SETTINGS_OPTION, []);
    $current_encrypted = $current['api_key'] ?? '';

    // Feed URLs
    $valid_urls = []; $invalid_detected = false;
    if (isset($input['feed_urls'])) {
        $urls = array_map('trim', preg_split('/\r\n|\r|\n/', sanitize_textarea_field($input['feed_urls'])));
        foreach ($urls as $url) {
             if (empty($url)) continue;
             if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) {
                 $valid_urls[] = $url;
             } else { $invalid_detected = true; }
        }
        if ($invalid_detected) add_settings_error(SUMAI_SETTINGS_OPTION, 'invalid_feed_url', 'Invalid feed URLs removed.', 'warning');
        if (count($valid_urls) > SUMAI_MAX_FEED_URLS) {
            $valid_urls = array_slice($valid_urls, 0, SUMAI_MAX_FEED_URLS);
            add_settings_error(SUMAI_SETTINGS_OPTION, 'feed_limit_exceeded', 'Feed URL limit reached.', 'warning');
        }
    }
    $sanitized['feed_urls'] = implode("\n", $valid_urls);

    // Prompts
    $sanitized['context_prompt'] = isset($input['context_prompt']) ? sanitize_textarea_field($input['context_prompt']) : '';
    $sanitized['title_prompt']   = isset($input['title_prompt']) ? sanitize_textarea_field($input['title_prompt']) : '';

    // Schedule Time
    $time_input = isset($input['schedule_time']) ? sanitize_text_field($input['schedule_time']) : '03:00';
    $sanitized['schedule_time']  = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time_input) ? $time_input : ($current['schedule_time'] ?? '03:00');
    if ($sanitized['schedule_time'] !== $time_input) add_settings_error(SUMAI_SETTINGS_OPTION, 'invalid_time', 'Invalid schedule time format.', 'warning');

    // Draft Mode
    $sanitized['draft_mode'] = (isset($input['draft_mode']) && $input['draft_mode'] == '1') ? 1 : 0;

    // Post Signature
    $sanitized['post_signature'] = isset($input['post_signature']) ? wp_kses_post($input['post_signature']) : '';

    // API Key
    if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) {
        // If constant is set, database value is effectively ignored, keep it as is.
        $sanitized['api_key'] = $current_encrypted;
    } elseif (isset($input['api_key'])) {
        $new_key_input = sanitize_text_field(trim($input['api_key']));
        $is_placeholder = ($new_key_input === '********************');

        if ($is_placeholder) {
            // Placeholder submitted, means no change intended
            $sanitized['api_key'] = $current_encrypted;
        } elseif (empty($new_key_input)) {
            // Empty field submitted, clear the key
            $sanitized['api_key'] = '';
            if (!empty($current_encrypted)) {
                add_settings_error(SUMAI_SETTINGS_OPTION, 'api_key_cleared', 'API key cleared.', 'updated');
                sumai_log_event('API key cleared via settings.');
            }
        } else {
            // New key provided, attempt to encrypt
            if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
                add_settings_error(SUMAI_SETTINGS_OPTION, 'api_key_encrypt_env', 'Cannot encrypt API key (OpenSSL/AUTH_KEY missing). Key NOT saved.', 'error');
                $sanitized['api_key'] = $current_encrypted; // Keep old
            } else {
                $cipher = 'aes-256-cbc';
                $ivlen = openssl_cipher_iv_length($cipher);
                if ($ivlen === false) {
                     add_settings_error(SUMAI_SETTINGS_OPTION, 'api_key_encrypt_fail', 'Cannot get IV length. Key NOT saved.', 'error');
                     $sanitized['api_key'] = $current_encrypted;
                } else {
                    $iv = openssl_random_pseudo_bytes($ivlen);
                    $encrypted = openssl_encrypt($new_key_input, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
                    if ($encrypted !== false && $iv !== false) {
                        $new_encrypted = base64_encode($iv.$encrypted);
                        if ($new_encrypted !== $current_encrypted) {
                            add_settings_error(SUMAI_SETTINGS_OPTION, 'api_key_saved', 'New API key saved.', 'updated');
                            sumai_log_event('New API key saved via settings.');
                        }
                        $sanitized['api_key'] = $new_encrypted;
                    } else {
                        add_settings_error(SUMAI_SETTINGS_OPTION, 'api_key_encrypt_fail', 'Failed to encrypt API key. Key NOT saved.', 'error');
                        $sanitized['api_key'] = $current_encrypted;
                    }
                }
            }
        }
    } else {
         // Key not in input, keep current
         $sanitized['api_key'] = $current_encrypted;
    }

    return $sanitized;
}

/**
 * Render the settings page HTML.
 */
function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    // Manual Generation Trigger
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now_action')) {
        sumai_log_event('Manual generation trigger initiated.');
        // Main function handles dependency loading now
        $result = sumai_generate_daily_summary(true); // Force fetch
        $msg_type = ($result !== false && is_int($result)) ? 'success' : 'error';
        $msg_text = ($msg_type === 'success') ? sprintf('Manual summary generated (Post ID: %d).', $result) : 'Manual summary failed or skipped. Check logs.';
        add_settings_error('sumai_settings', 'manual_gen_result', $msg_text, $msg_type);
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('options-general.php?page=sumai-settings'));
        exit;
    }
    // Display notices from redirect or standard save
    $transient_notices = get_transient('settings_errors');
    if ($transient_notices) { settings_errors('sumai_settings'); delete_transient('settings_errors'); }
    else { settings_errors('sumai_settings'); }

    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $options = wp_parse_args($options, ['feed_urls'=>'','context_prompt'=>'','title_prompt'=>'','api_key'=>'','draft_mode'=>0,'schedule_time'=>'03:00','post_signature'=>'']);
    $api_key_defined_const = defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY);
    $api_key_set_db = !empty($options['api_key']);
    $api_key_display = $api_key_defined_const ? '*** Defined in wp-config.php ***' : ($api_key_set_db ? '********************' : '');
    $feed_urls_count = count(array_filter(preg_split('/\r\n|\r|\n/', $options['feed_urls'])));
    ?>
    <div class="wrap sumai-settings-wrap">
        <h1><?php esc_html_e('Sumai Settings', 'sumai'); ?></h1>
        <div id="sumai-tabs">
            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="#tab-main" class="nav-tab"><?php esc_html_e('Main Settings', 'sumai'); ?></a>
                <a href="#tab-advanced" class="nav-tab"><?php esc_html_e('Advanced & Tools', 'sumai'); ?></a>
                <a href="#tab-debug" class="nav-tab"><?php esc_html_e('Debug Info', 'sumai'); ?></a>
            </nav>

            <div id="tab-main" class="tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields('sumai_options_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="sumai_feed_urls"><?php esc_html_e('RSS Feed URLs', 'sumai'); ?></label></th>
                            <td><textarea id="sumai_feed_urls" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[feed_urls]" rows="3" class="large-text" placeholder="<?php esc_attr_e('One feed URL per line (http/https)', 'sumai'); ?>"><?php echo esc_textarea($options['feed_urls']); ?></textarea>
                                <p class="description"><?php printf(esc_html__('Enter up to %d feeds. Saved: %d.', 'sumai'), SUMAI_MAX_FEED_URLS, $feed_urls_count); ?></p></td></tr>
                        <tr><th scope="row"><label for="sumai_api_key"><?php esc_html_e('OpenAI API Key', 'sumai'); ?></label></th>
                            <td><?php if ($api_key_defined_const): ?>
                                    <input type="text" value="<?php echo esc_attr($api_key_display); ?>" class="regular-text" readonly disabled />
                                    <p class="description"><?php esc_html_e('Key defined in wp-config.php.', 'sumai'); ?></p>
                                <?php else: ?>
                                    <input type="password" id="sumai_api_key" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[api_key]" value="<?php echo esc_attr($api_key_display); ?>" class="regular-text" placeholder="<?php echo $api_key_set_db ? esc_attr__('Enter new key to update', 'sumai') : esc_attr__('Enter API Key', 'sumai'); ?>" autocomplete="new-password" />
                                    <button type="button" id="test-api-button" class="button button-secondary"><?php esc_html_e('Test Key', 'sumai'); ?></button>
                                    <span id="api-test-result" style="margin-left: 10px; vertical-align: middle;"></span>
                                    <p class="description"><?php esc_html_e('Securely stored.', 'sumai'); ?> <?php if ($api_key_set_db) esc_html_e('Leave stars to keep, enter new to replace, clear to remove.', 'sumai'); else printf(wp_kses(__('Get key from <a href="%s" target="_blank">OpenAI</a>.', 'sumai'), ['a'=>['href'=>[],'target'=>[]]]), 'https://platform.openai.com/api-keys'); ?>
                                    <?php if (!function_exists('openssl_encrypt')||!defined('AUTH_KEY')||!AUTH_KEY) echo '<br><strong style="color:red;">'.esc_html__('Warning: Cannot encrypt in DB. Use wp-config constant.', 'sumai').'</strong>'; ?></p>
                                <?php endif; ?></td></tr>
                        <tr><th scope="row"><label for="sumai_context_prompt"><?php esc_html_e('Summary Prompt', 'sumai'); ?></label></th>
                            <td><textarea id="sumai_context_prompt" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[context_prompt]" rows="3" class="large-text" placeholder="<?php esc_attr_e('e.g., Summarize concisely.', 'sumai'); ?>"><?php echo esc_textarea($options['context_prompt']); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional AI instructions for summary. Default used if blank.', 'sumai'); ?></p></td></tr>
                        <tr><th scope="row"><label for="sumai_title_prompt"><?php esc_html_e('Title Prompt', 'sumai'); ?></label></th>
                            <td><textarea id="sumai_title_prompt" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[title_prompt]" rows="2" class="large-text" placeholder="<?php esc_attr_e('e.g., Create compelling title.', 'sumai'); ?>"><?php echo esc_textarea($options['title_prompt']); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional AI instructions for title. Default used if blank.', 'sumai'); ?></p></td></tr>
                        <tr><th scope="row"><?php esc_html_e('Publish Status', 'sumai'); ?></th>
                            <td><fieldset><label><input type="radio" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[draft_mode]" value="0" <?php checked(0, $options['draft_mode']); ?>> <?php esc_html_e('Publish Immediately', 'sumai'); ?></label><br>
                                <label><input type="radio" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[draft_mode]" value="1" <?php checked(1, $options['draft_mode']); ?>> <?php esc_html_e('Save as Draft', 'sumai'); ?></label></fieldset></td></tr>
                        <tr><th scope="row"><label for="sumai_schedule_time"><?php esc_html_e('Schedule Time', 'sumai'); ?></label></th>
                            <td><input type="time" id="sumai_schedule_time" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[schedule_time]" value="<?php echo esc_attr($options['schedule_time']); ?>" class="regular-text" required pattern="([01]?\d|2[0-3]):[0-5]\d" />
                                <p class="description"><?php esc_html_e('Daily generation time (HH:MM, 24h). Site Timezone:', 'sumai'); ?> <strong><?php echo esc_html(wp_timezone_string()); ?></strong>.<?php $next_run = wp_next_scheduled(SUMAI_CRON_HOOK); echo '<br/>'.($next_run ? sprintf(esc_html__('Next run: %s', 'sumai'), wp_date(get_option('date_format').' '.get_option('time_format'), $next_run)) : esc_html__('Not scheduled.', 'sumai')); ?></p></td></tr>
                        <tr><th scope="row"><label for="sumai_post_signature"><?php esc_html_e('Post Signature', 'sumai'); ?></label></th>
                            <td><textarea id="sumai_post_signature" name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[post_signature]" rows="3" class="large-text" placeholder="<?php esc_attr_e('Optional HTML/Text', 'sumai'); ?>"><?php echo esc_textarea($options['post_signature']); ?></textarea>
                                <p class="description"><?php esc_html_e('Appended to posts. Basic HTML allowed.', 'sumai'); ?></p></td></tr>
                    </table>
                    <?php submit_button(__('Save Settings', 'sumai')); ?>
                </form>
            </div><!-- /#tab-main -->

            <div id="tab-advanced" class="tab-content" style="display:none;">
                <h2><?php esc_html_e('Advanced & Tools', 'sumai'); ?></h2>
                <div class="card"><h3><?php esc_html_e('Generate Summary Now', 'sumai'); ?></h3>
                    <p><?php esc_html_e('Manually trigger generation using current settings (force fetch).', 'sumai'); ?></p>
                    <form method="post" action=""><input type="submit" name="sumai_generate_now" class="button button-primary" value="<?php esc_attr_e('Generate Now', 'sumai'); ?>"><?php wp_nonce_field('sumai_generate_now_action'); ?></form></div>
                <div class="card"><h3><?php esc_html_e('Test Feed Fetching', 'sumai'); ?></h3>
                    <p><?php esc_html_e('Check feed access and see which items are NEW.', 'sumai'); ?></p>
                    <button type="button" id="test-feed-button" class="button button-secondary"><?php esc_html_e('Test Feeds', 'sumai'); ?></button>
                    <div id="feed-test-result" class="sumai-test-results"></div></div>
                <div class="card"><h3><?php esc_html_e('External Cron Trigger', 'sumai'); ?></h3>
                    <p><?php esc_html_e('Use this URL for external cron services:', 'sumai'); ?></p>
                    <?php $cron_token = get_option(SUMAI_CRON_TOKEN_OPTION); if ($cron_token) { $trigger_url = add_query_arg(['sumai_trigger'=>'1','token'=>$cron_token], site_url('/')); echo '<input type="text" value="'.esc_url($trigger_url).'" class="large-text" readonly onfocus="this.select();"><p class="description">e.g., <code>wget -qO- \''.esc_url($trigger_url).'\' > /dev/null</code></p>'; } else { echo '<p>'.esc_html__('Token not generated. Save settings.', 'sumai').'</p>'; } ?></div>
            </div><!-- /#tab-advanced -->

            <div id="tab-debug" class="tab-content" style="display:none;">
                <h2><?php esc_html_e('Debug Information', 'sumai'); ?></h2>
                <?php sumai_render_debug_info(); ?>
            </div><!-- /#tab-debug -->
        </div><!-- /#sumai-tabs -->
    </div><!-- /.wrap -->
    <style>.sumai-settings-wrap .card{padding:15px 20px;border:1px solid #ccd0d4;background:#fff;margin:20px 0;box-shadow:0 1px 1px rgba(0,0,0,.04);}.nav-tab-wrapper{margin-bottom:20px;}.sumai-test-results{margin-top:10px;padding:15px;background:#f6f7f7;border:1px solid #ccd0d4;max-height:400px;overflow-y:auto;display:none;white-space:pre-wrap;font-family:monospace;font-size:12px;}.spinner{vertical-align:middle;}</style>
    <script type="text/javascript">jQuery(document).ready(function($){var $tabs=$('#sumai-tabs'),$links=$tabs.find('.nav-tab'),$content=$tabs.find('.tab-content');function showTab(h){h=h||localStorage.getItem('sumaiActiveTab')||$links.first().attr('href');$links.removeClass('nav-tab-active');$content.hide();var $link=$links.filter('[href="'+h+'"]');if($link.length===0){$link=$links.first();h=$link.attr('href');}$link.addClass('nav-tab-active');$(h).show();try{localStorage.setItem('sumaiActiveTab',h);}catch(e){}} $links.on('click',function(e){e.preventDefault();showTab($(this).attr('href'));});showTab(window.location.hash||localStorage.getItem('sumaiActiveTab'));
    $('#test-api-button').on('click',function(){var $btn=$(this),$res=$('#api-test-result'),$keyFld=$('#sumai_api_key'),keyTest='';if($keyFld.length)keyTest=($keyFld.val()==='********************')?'':$keyFld.val();$btn.prop('disabled',true).text('Testing...');$res.html('<span class="spinner is-active"></span>Testing...').css('color','');$.post(ajaxurl,{action:'sumai_test_api_key',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_api_key_nonce'); ?>',api_key_to_test:keyTest},function(r){if(r.success)$res.html('✅ '+r.data.message).css('color','green');else $res.html('❌ '+r.data.message).css('color','#d63638');},'json').fail(function(){$res.html('❌ AJAX Error').css('color','#d63638');}).always(function(){$btn.prop('disabled',false).text('Test Key');});});
    $('#test-feed-button').on('click',function(){var $btn=$(this),$res=$('#feed-test-result');$btn.prop('disabled',true).text('Testing...');$res.html('<span class="spinner is-active"></span>Testing...').css('color','').show();$.post(ajaxurl,{action:'sumai_test_feeds',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_feeds_nonce'); ?>'},function(r){if(r.success)$res.html(r.data.message).css('color','');else $res.html('❌ Error: '+r.data.message).css('color','#d63638');},'json').fail(function(){$res.html('❌ AJAX Error').css('color','#d63638');}).always(function(){$btn.prop('disabled',false).text('Test Feeds');});});});</script>
    <?php
}


/* -------------------------------------------------------------------------
 * 9. AJAX HANDLERS (Minimal)
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_sumai_test_api_key', function(){
    check_ajax_referer('sumai_test_api_key_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403);
    $key_input = isset($_POST['api_key_to_test']) ? trim(sanitize_text_field($_POST['api_key_to_test'])) : '';
    $key_to_test = empty($key_input) ? sumai_get_api_key() : $key_input; $context = empty($key_input) ? 'Current key' : 'Provided key';
    if (empty($key_to_test)) wp_send_json_error(['message'=>'API key not configured.']);
    if (sumai_validate_api_key($key_to_test)) wp_send_json_success(['message'=>$context.' validation OK.']);
    else wp_send_json_error(['message'=>$context.' validation FAILED. Check logs.']);
});

add_action('wp_ajax_sumai_test_feeds', function(){
    check_ajax_referer('sumai_test_feeds_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403);
    $options = get_option(SUMAI_SETTINGS_OPTION, []); $urls = empty($options['feed_urls']) ? [] : array_slice(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (empty($urls)) wp_send_json_error(['message'=>'No feeds configured.']);
    if (!function_exists('fetch_feed')){ include_once ABSPATH.WPINC.'/feed.php'; if (!function_exists('fetch_feed')) wp_send_json_error(['message'=>'WP feed functions unavailable.']); }
    $output = sumai_test_feeds($urls); wp_send_json_success(['message'=>'<pre>'.esc_html($output).'</pre>']);
});

function sumai_test_feeds(array $feed_urls): string {
    $out = "--- Feed Test Results ---\nTime: ".wp_date('Y-m-d H:i:s T')."\n"; $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $out .= count($guids)." processed GUIDs tracked.\n\n"; $new_found = false; $items_total = 0; $new_total = 0;
    foreach ($feed_urls as $i => $url) {
        $out .= "--- Feed #".($i+1).": {$url} ---\n"; wp_feed_cache_transient_lifetime(0); $feed = fetch_feed($url); wp_feed_cache_transient_lifetime(HOUR_IN_SECONDS);
        if (is_wp_error($feed)) { $out .= "❌ Error: ".esc_html($feed->get_error_message())."\n\n"; continue; }
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); $count = count($items); $items_total += $count;
        if (empty($items)) { $out .= "⚠️ OK but no items found.\n\n"; continue; } $out .= "✅ OK. Found {$count} items:\n"; $feed_new = false;
        foreach ($items as $idx => $item) { $guid = $item->get_id(true); $title = mb_strimwidth(strip_tags($item->get_title()?:'N/A'),0,80,'...'); $out .= "- Item ".($idx+1).": ".esc_html($title)."\n"; if (isset($guids[$guid])) $out .= "  Status: Processed\n"; else { $out .= "  Status: ✨ NEW\n"; $feed_new = true; $new_found = true; $new_total++; } }
        if (!$feed_new && $count > 0) $out .= "  ℹ️ No new items in latest checked.\n"; $out .= "\n"; unset($feed, $items);
    } $out .= "--- Summary ---\nChecked ".count($feed_urls)." feeds, {$items_total} items total.\n"; $out .= $new_found ? "✅ Detected {$new_total} NEW items." : "ℹ️ No new content detected."; return $out;
}


/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING (Minimal)
 * ------------------------------------------------------------------------- */

function sumai_ensure_log_dir(): ?string {
    static $log_file_path=null,$checked=false; if($checked)return $log_file_path; $checked=true; $upload_dir=wp_upload_dir(); if(!empty($upload_dir['error']))return null; $log_dir=trailingslashit($upload_dir['basedir']).SUMAI_LOG_DIR_NAME; $log_file=trailingslashit($log_dir).SUMAI_LOG_FILE_NAME;
    if(!is_dir($log_dir)){if(!wp_mkdir_p($log_dir))return null; @file_put_contents($log_dir.'/.htaccess',"Options -Indexes\nDeny from all"); @file_put_contents($log_dir.'/index.php','<?php // Silence');}
    if(!is_writable($log_dir))return null; if(!file_exists($log_file)){if(false===@file_put_contents($log_file,''))return null; @chmod($log_file,0644);} if(!is_writable($log_file))return null; return $log_file_path=$log_file;
}
function sumai_log_event(string $message, bool $is_error=false) { $log_file=sumai_ensure_log_dir(); if(!$log_file){error_log("Sumai ".($is_error?'[ERR]':'[INFO]')."(Log N/A): ".$message); return;} $ts=wp_date('Y-m-d H:i:s T'); $level=$is_error?' [ERROR] ':' [INFO]  '; $log_line='['.$ts.']'.$level.trim(preg_replace('/\s+/',' ',wp_strip_all_tags($message))).PHP_EOL; @file_put_contents($log_file,$log_line,FILE_APPEND|LOCK_EX); }
function sumai_prune_logs() { $log_file=sumai_ensure_log_dir(); if(!$log_file||!is_readable($log_file)||!is_writable($log_file)) return; $lines=@file($log_file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if(empty($lines))return; $cutoff=time()-SUMAI_LOG_TTL; $keep=[]; $pruned=0; foreach($lines as $line){$ts=false; if(preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/',$line,$m)){try{$dt=new DateTime($m[1]);$ts=$dt->getTimestamp();}catch(Exception $e){$ts=strtotime($m[1]);}} if($ts!==false&&$ts>=$cutoff)$keep[]=$line; else $pruned++;} if($pruned>0){$new_content=empty($keep)?'':implode(PHP_EOL,$keep).PHP_EOL; if(false===@file_put_contents($log_file,$new_content,LOCK_EX))sumai_log_event("Log pruning failed write.",true);} }

function sumai_get_debug_info(): array {
    $dbg = []; $opts = get_option(SUMAI_SETTINGS_OPTION, []); $dbg['settings'] = $opts; $dbg['settings']['api_key'] = (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) ? '*** Constant ***' : (!empty($opts['api_key']) ? '*** DB Set ***' : '*** Not Set ***');
    $crons = _get_cron_array() ?: []; $dbg['cron'] = []; $found = false; foreach ($crons as $t => $h) { foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $n) { if (isset($h[$n])) { $found=true; $k=key($h[$n]); $d=$h[$n][$k]; $dbg['cron'][$n] = ['next' => wp_date('Y-m-d H:i T', $t), 'schedule' => $d['schedule'] ?? 'N/A']; } } } if (!$found) $dbg['cron'] = 'No Sumai tasks scheduled.';
    $file = sumai_ensure_log_dir(); $dbg['log'] = ['path' => $file ?: 'ERROR', 'writable' => $file && is_writable($file), 'readable' => $file && is_readable($file)]; $dbg['log']['recent'] = ($dbg['log']['readable'] && ($c = @file_get_contents($file, false, null, -5120)) !== false) ? array_slice(explode("\n", trim($c)), -20) : ['Log unreadable or empty.'];
    global $wp_version; $dbg['sys'] = ['v' => $wp_version, 'php' => phpversion(), 'tz' => wp_timezone_string(), 'cron' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Disabled' : 'Enabled'), 'mem' => WP_MEMORY_LIMIT];
    return $dbg;
}
function sumai_render_debug_info() { $dbg = sumai_get_debug_info(); echo '<div><style>td{vertical-align:top;}pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:5px;border:1px solid #ccc;margin:0;font-size:12px;max-height:200px;overflow-y:auto;}</style><h3>Settings</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($dbg['settings'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(ucwords(str_replace('_',' ',$k))).'</td><td><pre>'.esc_html(is_array($v)?print_r($v,true):$v).'</pre></td></tr>'; echo '</tbody></table><h3>Scheduled Tasks</h3>'; if(is_array($dbg['cron'])&&!empty($dbg['cron'])){echo '<table class="wp-list-table fixed striped"><thead><tr><th>Hook</th><th>Next Run</th><th>Schedule</th></tr></thead><tbody>'; foreach($dbg['cron'] as $h=>$d) echo '<tr><td><code>'.esc_html($h).'</code></td><td>'.esc_html($d['next']).'</td><td>'.esc_html($d['schedule']).'</td></tr>'; echo '</tbody></table>';} else echo '<p>'.esc_html($dbg['cron']).'</p>'; echo '<h3>System</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($dbg['sys'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(strtoupper($k)).'</td><td>'.esc_html($v).'</td></tr>'; echo '</tbody></table><h3>Logging</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Path</td><td><code>'.esc_html($dbg['log']['path']).'</code></td></tr><tr><td>Status</td><td>'.($dbg['log']['readable']?'R':'Not R').', '.($dbg['log']['writable']?'W':'Not W').'</td></tr></tbody></table><h4>Recent Logs (tail)</h4><pre>'.esc_html(implode("\n",$dbg['log']['recent'])).'</pre></div>'; }

?>