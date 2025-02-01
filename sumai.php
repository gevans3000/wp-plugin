<?php
/*
Plugin Name: Sumai
Plugin URI:  https://biglife360.com/sumai
Description: Automatically fetches and summarizes the latest RSS feed articles using OpenAI gpt-4o-mini, then publishes a single daily “Daily Summary” post.
Version:     1.0
Author:      biglife360.com
Author URI:  https://biglife360.com
*/

/**
 * Sumai
 * -----
 * This plugin fetches up to three RSS feeds, extracts the most recent article from each,
 * summarizes their content using OpenAI's gpt-4o-mini, and publishes a single daily summary post.
 * Users can also manually trigger the summary from the plugin's settings page.
 *
 * Steps in this file:
 *  1. Activation & Deactivation
 *  2. Cron Scheduling & Hook
 *  3. Main Cron Callback (sumai_generate_daily_summary)
 *  4. Feed Fetching (sumai_fetch_latest_articles)
 *  5. Summarization (sumai_summarize_text)
 *  6. Admin Settings Page
 *  7. Manual Posting Button
 *  8. Logging & Log Cleanup
 *  9. Utility Functions
 */

/* -------------------------------------------------------------------------
 * 1. ACTIVATION & DEACTIVATION HOOKS
 * ------------------------------------------------------------------------- */

/**
 * On plugin activation:
 * - Schedule posts based on settings
 */
function sumai_activate() {
    sumai_schedule_daily_posts();
    update_option('sumai_cron_token', wp_generate_password(32));
}
register_activation_hook( __FILE__, 'sumai_activate' );

/**
 * On plugin deactivation:
 * - Clear the scheduled event to prevent orphaned cron jobs.
 */
function sumai_deactivate() {
    wp_clear_scheduled_hook( 'sumai_daily_event' );
    wp_clear_scheduled_hook( 'sumai_rotate_cron_token' );
}
register_deactivation_hook( __FILE__, 'sumai_deactivate' );

/* -------------------------------------------------------------------------
 * 2. CRON SCHEDULING & HOOK
 * ------------------------------------------------------------------------- */

/**
 * This hook is fired daily (or as scheduled by WP-Cron).
 * The callback triggers the daily summary routine.
 */
add_action( 'sumai_daily_event', 'sumai_generate_daily_summary' );

/* -------------------------------------------------------------------------
 * 3. MAIN CRON CALLBACK (sumai_generate_daily_summary)
 * ------------------------------------------------------------------------- */

/**
 * Fetches the latest articles from configured feeds, summarizes them, and publishes a post.
 * @return void
 */
function sumai_generate_daily_summary() {

    // Load settings
    $options       = get_option( 'sumai_settings', array() );
    $feed_urls     = isset( $options['feed_urls'] ) ? $options['feed_urls'] : '';
    $context_prompt  = isset( $options['context_prompt'] ) ? $options['context_prompt'] : '';
    $draft_mode = isset( $options['draft_mode'] ) ? $options['draft_mode'] : true;

    // Prune old logs to keep them at 30 days
    sumai_prune_logs_older_than_30_days();

    // Fetch combined article text (just the latest from each feed)
    $combined_text = sumai_fetch_latest_articles( $feed_urls );

    // If no content is available, log and exit
    if ( empty( $combined_text ) ) {
        sumai_log_event( 'No feed data available. Skipping daily post.', true );
        return;
    }

    // Summarize the combined text
    $summary = sumai_summarize_text( $combined_text, $context_prompt );

    // If summarization failed, log and exit
    if ( empty( $summary ) ) {
        sumai_log_event( 'OpenAI summarization failed. Skipping daily post.', true );
        return;
    }

    // Create post
    $post_data = array(
        'post_title'    => 'Daily Summary for ' . current_time( 'Y-m-d' ),
        'post_content'  => $summary,
        'post_status'   => $draft_mode ? 'draft' : 'publish',
        'post_type'     => 'post',
        'post_author'   => 1
    );

    error_log('[SUMAI] Inserting post: ' . print_r($post_data, true));
    $post_id = wp_insert_post( $post_data );

    if ( $post_id && !is_wp_error($post_id) ) {
        error_log('[SUMAI] Draft created - ID: ' . $post_id);
        update_post_meta($post_id, '_sumai_feed_source', $feed_urls);
        $status = $draft_mode ? 'draft' : 'published';
        sumai_log_event( "Successfully created $status post with ID: $post_id" );
    } else {
        error_log('[SUMAI] Error creating post: ' . $post_id->get_error_message());
        sumai_log_event( 'Failed to create post', true );
    }
}

/* -------------------------------------------------------------------------
 * 4. FEED FETCHING (sumai_fetch_latest_articles)
 * ------------------------------------------------------------------------- */

/**
 * Fetches the latest article from each feed and concatenates them into one string.
 *
 * @param string $feed_urls String of feed URLs separated by newlines.
 * @return string Combined textual content from the latest articles. Empty if none found.
 */
function sumai_fetch_latest_articles( $feed_urls = '' ) {
    $combined_text = '';

    if ( empty( $feed_urls ) ) {
        return $combined_text;
    }

    $feed_urls = array_filter(explode("\n", $feed_urls));

    foreach ( $feed_urls as $feed_url ) {
        $feed_url = trim( $feed_url );
        if ( empty( $feed_url ) ) {
            continue;
        }

        // Enhanced feed processing with error handling
        $response = wp_remote_get($feed_url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'User-Agent' => 'SUMAI/1.0 (+https://biglife360.com)'
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('[SUMAI] Feed Error: ' . $response->get_error_message());
            continue;
        }

        $xml = simplexml_load_string(wp_remote_retrieve_body($response));
        if (!$xml) {
            error_log('[SUMAI] Invalid XML from feed');
            continue;
        }

        // Attempt to extract the first (most recent) item
        if ( isset( $xml->channel->item ) ) {
            $item = $xml->channel->item[0]; // the most recent item
        } elseif ( isset( $xml->entry ) ) {
            // Some feeds (ATOM) may use <entry> instead of <item>
            $item = $xml->entry[0];
        } else {
            error_log('[SUMAI] No valid RSS <item> found for feed: ' . $feed_url);
            continue;
        }

        $description = '';
        // For RSS
        if ( isset( $item->description ) ) {
            $description = (string) $item->description;
        }
        // For ATOM, you might need <content> or <summary>
        if ( empty( $description ) && isset( $item->summary ) ) {
            $description = (string) $item->summary;
        }
        if ( empty( $description ) && isset( $item->content ) ) {
            $description = (string) $item->content;
        }

        // Combine
        $combined_text .= "\n" . $description;

    }

    return trim( $combined_text );
}

/* -------------------------------------------------------------------------
 * 5. SUMMARIZATION (sumai_summarize_text)
 * ------------------------------------------------------------------------- */

/**
 * Summarizes the given text using the provided OpenAI API key and context prompt.
 * Uses a fictional "gpt-4o-mini" model endpoint as described in the task.
 *
 * @param string $text           The full text to summarize.
 * @param string $context_prompt A custom prompt to shape the AI summary output.
 * @return string Summarized text or empty string if there was an error.
 */
function sumai_summarize_text( $text, $context_prompt ) {
    $api_key = sumai_get_api_key();
    if (empty($api_key)) {
        error_log('Sumai: OpenAI API key not found in .env file');
        return '';
    }

    // Basic input validation
    if ( empty( $text ) ) {
        return '';
    }

    // A quick limit to ~1600 words in the input (very rough approach)
    $word_limit    = 1600;
    $words         = explode( ' ', $text );
    if ( count( $words ) > $word_limit ) {
        $words = array_slice( $words, 0, $word_limit );
    }
    $truncated_text = implode( ' ', $words );

    // Build the request body (fictional endpoint for "gpt-4o-mini")
    // Adjust as needed for your actual OpenAI / ChatGPT request if the endpoint differs
    $api_endpoint = 'https://api.openai.com/v1/chat/completions';

    // We will create a "system" style context by prepending the context prompt.
    // Alternatively, you can do a user + system approach, but here we keep it simple:
    $prompt_text = $context_prompt . "\n\n" . $truncated_text;

    $request_body = array(
        'model'       => 'gpt-4o-mini', // per instructions
        'messages'    => array(
            array( 'role' => 'user', 'content' => $prompt_text ),
        ),
        'max_tokens'  => 800,
        'temperature' => 0.7,
    );

    $request_args = array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => json_encode( $request_body ),
        'method'  => 'POST',
        'timeout' => 30,
    );

    // Send request to OpenAI
    $response = wp_remote_post( $api_endpoint, $request_args );

    if ( is_wp_error( $response ) ) {
        sumai_log_event( 'OpenAI API request failed: ' . $response->get_error_message() );
        return '';
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code != 200 ) {
        sumai_log_event( 'OpenAI API returned non-200 status: ' . $status_code );
        return '';
    }

    $response_body = wp_remote_retrieve_body( $response );
    if ( empty( $response_body ) ) {
        sumai_log_event( 'OpenAI API response was empty.' );
        return '';
    }

    $data = json_decode( $response_body, true );
    if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
        sumai_log_event( 'OpenAI API response did not contain expected fields.' );
        return '';
    }

    $summary = trim( $data['choices'][0]['message']['content'] );

    // Additional truncation just to ensure we stay within ~1600 words
    $summary_words = explode( ' ', $summary );
    if ( count( $summary_words ) > $word_limit ) {
        $summary_words = array_slice( $summary_words, 0, $word_limit );
    }
    $final_summary = implode( ' ', $summary_words );

    return $final_summary;
}

function sumai_get_api_key() {
    static $api_key = null;
    if ($api_key === null) {
        // Get home directory path
        $home_dir = dirname(dirname(ABSPATH)); // /home/tzjwuepq
        $env_path = $home_dir . '/.env';
        
        if (file_exists($env_path)) {
            $env_content = file_get_contents($env_path);
            foreach (explode("\n", $env_content) as $line) {
                $line = trim($line);
                if (strpos($line, 'OPENAI_API_KEY=') === 0) {
                    $api_key = trim(substr($line, strlen('OPENAI_API_KEY=')));
                    break;
                }
            }
        }
        
        if ($api_key === null) {
            error_log('Sumai: OpenAI API key not found in ' . $env_path);
        }
    }
    return $api_key;
}

/* -------------------------------------------------------------------------
 * 6. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

/**
 * Register admin menu for plugin settings.
 */
function sumai_add_admin_menu() {
    add_menu_page(
        'Sumai Settings', // Page title
        'Sumai', // Menu title
        'manage_options', // Capability required
        'sumai-settings', // Menu slug
        'sumai_render_settings_page', // Function to render the page
        'dashicons-rss', // Icon (using WordPress RSS dashicon)
        30 // Position in menu
    );
}
add_action('admin_menu', 'sumai_add_admin_menu');

/**
 * Register settings for the plugin.
 */
add_action( 'admin_init', 'sumai_register_settings' );
function sumai_register_settings() {
    register_setting( 'sumai_settings', 'sumai_settings', 'sumai_sanitize_settings' );
}

/**
 * Sanitize and validate submitted settings.
 *
 * @param array $input
 * @return array
 */
function sumai_sanitize_settings($input) {
    $sanitized = array();

    // Handle feed URLs - store as a simple string with newlines
    if (isset($input['feed_urls'])) {
        $urls = array_filter(
            explode("\n", trim($input['feed_urls'])),
            function($url) { 
                $trimmed = trim($url);
                return !empty($trimmed) && filter_var($trimmed, FILTER_VALIDATE_URL);
            }
        );
        
        // Store as a string with newlines
        $sanitized['feed_urls'] = implode("\n", array_map('trim', $urls));
    }

    // Sanitize context prompt
    if (isset($input['context_prompt'])) {
        $sanitized['context_prompt'] = sanitize_textarea_field($input['context_prompt']);
    }

    // Handle draft mode checkbox
    $sanitized['draft_mode'] = isset($input['draft_mode']) ? true : false;

    return $sanitized;
}

/**
 * Renders the plugin settings page.
 */
function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $debug_messages = array();
    
    // Handle settings update
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['sumai_settings']) && check_admin_referer('sumai_settings_update')) {
            $raw_data = $_POST['sumai_settings'];
            $debug_messages[] = "Raw form data:\n" . print_r($raw_data, true);
            
            $settings = sumai_sanitize_settings($raw_data);
            $debug_messages[] = "Sanitized settings:\n" . print_r($settings, true);
            
            // Save settings using update_option
            $save_result = update_option('sumai_settings', $settings);
            $debug_messages[] = "Save result: " . ($save_result ? "Success" : "Failed");
            
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }
        // Handle manual generation
        elseif (isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now')) {
            sumai_generate_daily_summary();
            echo '<div class="notice notice-success is-dismissible"><p>Summary post has been generated. Check the Posts section to find it.</p></div>';
        }
    }

    // Get settings
    $options = get_option('sumai_settings', array());
    $debug_messages[] = "Retrieved settings from database:\n" . print_r($options, true);
    
    // Get feed URLs as array
    $feed_urls = isset($options['feed_urls']) ? array_filter(explode("\n", $options['feed_urls'])) : array();
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $draft_mode = isset($options['draft_mode']) ? $options['draft_mode'] : true;
    
    $debug_messages[] = "Feed URLs for display (" . count($feed_urls) . "):\n" . print_r($feed_urls, true);
    ?>
    <div class="wrap">
        <h1>Sumai Settings</h1>

        <!-- Debug Information -->
        <div class="card" style="max-width: 800px; margin-bottom: 20px; padding: 10px; background-color: #f8f9fa;">
            <h2>Debug Information</h2>
            <pre style="background: #fff; padding: 10px; overflow: auto; max-height: 200px; white-space: pre-wrap;">
<?php foreach ($debug_messages as $message): ?>
<?php echo esc_html($message) . "\n----------------------------------------\n"; ?>
<?php endforeach; ?>
            </pre>
        </div>
        
        <!-- Settings Form -->
        <form method="post">
            <?php wp_nonce_field('sumai_settings_update'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">RSS Feed URLs</th>
                    <td>
                        <textarea name="sumai_settings[feed_urls]" rows="3" class="large-text" placeholder="Enter one RSS feed URL per line"><?php 
                            if (!empty($feed_urls)) {
                                echo esc_textarea(implode("\n", $feed_urls));
                            }
                        ?></textarea>
                        <p class="description">Enter one RSS feed URL per line. Currently saved URLs: <?php echo count($feed_urls); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Context Prompt</th>
                    <td>
                        <textarea name="sumai_settings[context_prompt]" rows="4" class="large-text"><?php echo esc_textarea($context_prompt); ?></textarea>
                        <p class="description">Custom prompt to shape the AI summary output.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Draft Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="sumai_settings[draft_mode]" value="1" <?php checked($draft_mode); ?>>
                            Create posts as drafts
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>

        <!-- Manual Generation Form -->
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 10px;">
            <h2>Manual Generation</h2>
            <p>Click the button below to generate a summary post immediately.</p>
            <form method="post">
                <?php wp_nonce_field('sumai_generate_now'); ?>
                <input type="submit" name="sumai_generate_now" class="button button-primary" value="Generate Summary Now">
            </form>
        </div>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * 7. MANUAL POSTING BUTTON
 * ------------------------------------------------------------------------- */
/* Handled in the sumai_render_settings_page() function above.
 * When the button is clicked, it calls sumai_generate_daily_summary() directly.
 */

/* -------------------------------------------------------------------------
 * 8. LOGGING & LOG CLEANUP
 * ------------------------------------------------------------------------- */

/**
 * Writes a log entry to sumai-logs.log in wp-content/uploads/.
 *
 * @param string $message  The message to write.
 * @param bool   $major    Flag for major failure or error (optional).
 * @return void
 */
function sumai_log_event( $message, $major = false ) {
    $upload_dir = wp_upload_dir();
    $log_path   = $upload_dir['basedir'] . '/sumai-logs.log';

    // Prepend timestamp and (MAJOR) if flagged
    $prefix  = '[' . date( 'Y-m-d H:i:s' ) . ']';
    if ( $major ) {
        $prefix .= ' [MAJOR]';
    }

    $log_line = $prefix . ' ' . $message . PHP_EOL;

    // Append to log file
    @file_put_contents( $log_path, $log_line, FILE_APPEND | LOCK_EX );
}

/**
 * Prunes log entries older than 30 days from sumai-logs.log.
 *
 * @return void
 */
function sumai_prune_logs_older_than_30_days() {
    $upload_dir = wp_upload_dir();
    $log_path   = $upload_dir['basedir'] . '/sumai-logs.log';

    if ( ! file_exists( $log_path ) ) {
        return; // Nothing to prune
    }

    $lines = @file( $log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( $lines === false ) {
        return; // Could not read log file
    }

    $cutoff_timestamp = strtotime( '-30 days' );
    $retained_lines   = array();

    foreach ( $lines as $line ) {
        // Attempt to parse timestamp from the line format: [YYYY-MM-DD HH:MM:SS]
        if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches ) ) {
            $timestamp = strtotime( $matches[1] );
            if ( $timestamp !== false && $timestamp >= $cutoff_timestamp ) {
                $retained_lines[] = $line;
            }
        } else {
            // If line doesn't match the format, keep it
            $retained_lines[] = $line;
        }
    }

    // Rewrite log file with retained lines
    @file_put_contents( $log_path, implode( PHP_EOL, $retained_lines ) . PHP_EOL );
}

/* -------------------------------------------------------------------------
 * 9. UTILITY FUNCTIONS
 * -------------------------------------------------------------------------
 * Additional helper functions (if needed) could be placed here.
 */

// Add new function to schedule multiple posts per day
function sumai_schedule_daily_posts() {
    $options = get_option('sumai_settings', array());
    $posts_per_day = isset($options['posts_per_day']) ? intval($options['posts_per_day']) : 1;
    $posts_per_day = min(max($posts_per_day, 1), 9); // Ensure between 1 and 9

    // Clear existing schedule
    wp_clear_scheduled_hook('sumai_daily_event');

    // Schedule posts throughout the day
    $start_time = strtotime('tomorrow midnight'); // Start from tomorrow
    $day_seconds = 24 * 60 * 60;
    $interval = $day_seconds / $posts_per_day;

    for ($i = 0; $i < $posts_per_day; $i++) {
        $random_offset = rand(0, (int)($interval * 0.5)); // Random offset within half the interval
        $schedule_time = $start_time + ($i * $interval) + $random_offset;
        wp_schedule_event($schedule_time, 'daily', 'sumai_daily_event');
    }
}

// Add hook to reschedule posts when settings are updated
add_action('update_option_sumai_settings', 'sumai_schedule_daily_posts');

// Generate cron token on plugin activation
register_activation_hook(__FILE__, function() {
    update_option('sumai_cron_token', wp_generate_password(32));
});

// Add weekly token rotation
add_action('sumai_rotate_cron_token', function() {
    update_option('sumai_cron_token', wp_generate_password(32));
});
if (!wp_next_scheduled('sumai_rotate_cron_token')) {
    wp_schedule_event(time(), 'weekly', 'sumai_rotate_cron_token');
}
