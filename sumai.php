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
    try {
        // Load settings
        $options = get_option('sumai_settings', array());
        $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
        $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
        $draft_mode = isset($options['draft_mode']) ? $options['draft_mode'] : true;

        // Track cron execution
        sumai_track_cron_execution();

        // Validate requirements
        if (empty($feed_urls)) {
            error_log("[SUMAI] No feed URLs configured");
            return;
        }

        // Get the content
        $content = sumai_fetch_latest_articles($feed_urls);
        if (empty($content)) {
            error_log("[SUMAI] No new content to process");
            sumai_track_post_creation(0, false);
            return;
        }

        // Get the summary
        $summary = sumai_summarize_text($content, $context_prompt);
        if (empty($summary)) {
            error_log("[SUMAI] Failed to generate summary");
            sumai_track_post_creation(0, false);
            return;
        }

        // Create the post
        $post_data = array(
            'post_title'    => $summary['title'],
            'post_content'  => $summary['content'],
            'post_status'   => $draft_mode ? 'draft' : 'publish',
            'post_type'     => 'post',
            'post_author'   => 1
        );

        // Insert the post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            error_log("[SUMAI] Failed to create post: " . $post_id->get_error_message());
            sumai_track_post_creation(0, false);
            return;
        }

        // Track successful post creation
        sumai_track_post_creation($post_id, true);
        
        // Update feed processing history with post status
        $processed_items = get_option('sumai_processed_items', array());
        foreach ($processed_items as $feed_url => &$item) {
            $item['post_status'] = $draft_mode ? 'draft' : 'publish';
        }
        update_option('sumai_processed_items', $processed_items);

        return $post_id;
    } catch (Exception $e) {
        error_log("[SUMAI] Exception in daily summary generation: " . $e->getMessage());
        sumai_track_post_creation(0, false);
        return null;
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
function sumai_fetch_latest_articles($feed_urls = '') {
    $combined_text = '';

    if (empty($feed_urls)) {
        return $combined_text;
    }

    $feed_urls = array_filter(explode("\n", $feed_urls));
    $processed_items = get_option('sumai_processed_items', array());

    foreach ($feed_urls as $feed_url) {
        $feed_url = trim($feed_url);
        if (empty($feed_url)) {
            continue;
        }

        try {
            $rss = fetch_feed($feed_url);

            if (is_wp_error($rss)) {
                error_log("[SUMAI] Error fetching feed $feed_url: " . $rss->get_error_message());
                continue;
            }

            $maxitems = $rss->get_item_quantity(1); // Get only the latest item
            $rss_items = $rss->get_items(0, $maxitems);

            if (!empty($rss_items)) {
                $latest_item = $rss_items[0];
                $item_id = $latest_item->get_id();
                $item_url = $latest_item->get_permalink();
                $item_date = $latest_item->get_date('U');

                // Check if we've already processed this item
                if (!isset($processed_items[$feed_url]) || 
                    $processed_items[$feed_url]['id'] !== $item_id ||
                    $processed_items[$feed_url]['date'] < $item_date) {
                    
                    // Get the content
                    $title = $latest_item->get_title();
                    $content = $latest_item->get_content();
                    $description = $latest_item->get_description();
                    
                    // Use content if available, otherwise use description
                    $text = !empty($content) ? $content : $description;
                    
                    // Add title and content to combined text
                    $combined_text .= "Article Title: $title\n\n";
                    $combined_text .= wp_strip_all_tags($text) . "\n\n";
                    
                    // Update processed items
                    $processed_items[$feed_url] = array(
                        'id' => $item_id,
                        'url' => $item_url,
                        'date' => $item_date,
                        'title' => $title
                    );
                } else {
                    error_log("[SUMAI] Skipping already processed item from feed: $feed_url");
                    continue;
                }
            }
        } catch (Exception $e) {
            error_log("[SUMAI] Exception processing feed $feed_url: " . $e->getMessage());
            continue;
        }
    }

    // Save the updated processed items
    update_option('sumai_processed_items', $processed_items);

    return $combined_text;
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

    return array('title' => 'Daily Summary for ' . current_time( 'Y-m-d' ), 'content' => $final_summary);
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
function sumai_get_debug_info() {
    global $wpdb;
    $debug = array();
    
    // Basic WordPress Info
    $debug['wordpress'] = array(
        'version' => get_bloginfo('version'),
        'url' => get_bloginfo('url'),
        'language' => get_bloginfo('language'),
        'timezone' => wp_timezone_string(),
        'debug_mode' => WP_DEBUG ? 'Enabled' : 'Disabled',
        'multisite' => is_multisite() ? 'Yes' : 'No',
        'permalink_structure' => get_option('permalink_structure'),
        'active_theme' => wp_get_theme()->get('Name'),
        'post_types' => implode(', ', get_post_types(['public' => true]))
    );
    
    // Database Info
    $debug['database'] = array(
        'wp_version' => get_option('db_version'),
        'table_prefix' => $wpdb->prefix,
        'charset' => $wpdb->charset,
        'collate' => $wpdb->collate,
        'last_error' => $wpdb->last_error,
        'show_errors' => $wpdb->show_errors ? 'Yes' : 'No'
    );

    // Plugin Settings
    $options = get_option('sumai_settings', array());
    $debug['settings'] = array(
        'feed_urls' => isset($options['feed_urls']) ? explode("\n", $options['feed_urls']) : array(),
        'draft_mode' => isset($options['draft_mode']) ? 'Yes' : 'No',
        'context_prompt_length' => isset($options['context_prompt']) ? strlen($options['context_prompt']) : 0,
        'settings_saved_count' => get_option('sumai_settings_saves', 0)
    );
    
    // Feed Processing History
    $processed_items = get_option('sumai_processed_items', array());
    $debug['feed_history'] = array();
    foreach ($processed_items as $feed_url => $item) {
        $debug['feed_history'][$feed_url] = array(
            'last_processed' => date('Y-m-d H:i:s', $item['date']),
            'last_title' => $item['title'],
            'item_id' => $item['id'],
            'last_status' => isset($item['post_status']) ? $item['post_status'] : 'unknown'
        );
    }
    
    // Post Creation Stats
    $debug['post_stats'] = array(
        'total_posts_created' => get_option('sumai_total_posts', 0),
        'failed_attempts' => get_option('sumai_failed_posts', 0),
        'last_post_id' => get_option('sumai_last_post_id', 'None'),
        'last_post_status' => 'None'
    );
    
    // If we have a last post ID, get its status
    $last_post_id = get_option('sumai_last_post_id', 0);
    if ($last_post_id) {
        $last_post = get_post($last_post_id);
        if ($last_post) {
            $debug['post_stats']['last_post_status'] = $last_post->post_status;
            $debug['post_stats']['last_post_date'] = $last_post->post_date;
            $debug['post_stats']['last_post_modified'] = $last_post->post_modified;
        }
    }
    
    // Cron Status
    $next_scheduled = wp_next_scheduled('sumai_daily_event');
    $debug['cron'] = array(
        'next_run' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled',
        'last_token_rotation' => get_option('sumai_last_token_rotation', 'Never'),
        'cron_token' => substr(get_option('sumai_cron_token', 'Not set'), 0, 8) . '...',
        'wp_cron_enabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'No' : 'Yes',
        'cron_schedules' => implode(', ', array_keys(wp_get_schedules())),
        'missed_schedules' => get_option('sumai_missed_schedules', 0)
    );
    
    // System Information
    $debug['system'] = array(
        'php_version' => PHP_VERSION,
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_vars' => ini_get('max_input_vars'),
        'allow_url_fopen' => ini_get('allow_url_fopen') ? 'Yes' : 'No',
        'curl_version' => function_exists('curl_version') ? curl_version()['version'] : 'Not Available',
        'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'Unknown'
    );
    
    // Plugin Conflicts
    $active_plugins = get_option('active_plugins');
    $conflicting_plugins = array();
    $known_conflicts = array(
        'another-rss-feed' => 'Another RSS Feed Plugin',
        'wp-cron-control' => 'WP Cron Control',
        'wp-super-cache' => 'WP Super Cache',
        'w3-total-cache' => 'W3 Total Cache'
    );
    
    foreach ($active_plugins as $plugin) {
        foreach ($known_conflicts as $slug => $name) {
            if (strpos($plugin, $slug) !== false) {
                $conflicting_plugins[] = $name;
            }
        }
    }
    
    $debug['plugin_conflicts'] = empty($conflicting_plugins) ? array('No known conflicts') : $conflicting_plugins;
    
    // Recent Error Log
    $error_log = array();
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            if ($log_content) {
                // Get last 10 lines containing [SUMAI]
                preg_match_all('/.*\[SUMAI\].*$/m', $log_content, $matches);
                $error_log = array_slice($matches[0], -10);
            }
        }
    }
    $debug['recent_errors'] = $error_log;
    
    return $debug;
}

function sumai_render_debug_info($debug_info) {
    ?>
    <div class="card" style="max-width: 1200px; margin-bottom: 20px;">
        <h2 style="padding: 10px; margin: 0; background: #f0f0f1; border-bottom: 1px solid #c3c4c7;">
            Debug Information
            <button type="button" class="button button-small" style="float: right;" onclick="navigator.clipboard.writeText(document.getElementById('debug-content').innerText)">
                Copy Debug Info
            </button>
        </h2>
        <div id="debug-content" style="padding: 15px;">
            <div class="debug-section">
                <h3>WordPress Information</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <?php foreach ($debug_info['wordpress'] as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo ucwords(str_replace('_', ' ', $key)); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="debug-section">
                <h3>Database Information</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <?php foreach ($debug_info['database'] as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo ucwords(str_replace('_', ' ', $key)); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="debug-section">
                <h3>Plugin Settings</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <tr>
                        <td><strong>Feed URLs (<?php echo count($debug_info['settings']['feed_urls']); ?>)</strong></td>
                        <td>
                            <?php foreach ($debug_info['settings']['feed_urls'] as $url): ?>
                                <?php echo esc_html($url); ?><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Draft Mode</strong></td>
                        <td><?php echo esc_html($debug_info['settings']['draft_mode']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Context Prompt Length</strong></td>
                        <td><?php echo esc_html($debug_info['settings']['context_prompt_length']); ?> characters</td>
                    </tr>
                    <tr>
                        <td><strong>Settings Saved Count</strong></td>
                        <td><?php echo esc_html($debug_info['settings']['settings_saved_count']); ?></td>
                    </tr>
                </table>
            </div>

            <div class="debug-section">
                <h3>Feed Processing History</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Feed URL</th>
                            <th>Last Processed</th>
                            <th>Last Title</th>
                            <th>Last Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($debug_info['feed_history'] as $feed_url => $info): ?>
                        <tr>
                            <td><?php echo esc_html($feed_url); ?></td>
                            <td><?php echo esc_html($info['last_processed']); ?></td>
                            <td><?php echo esc_html($info['last_title']); ?></td>
                            <td><?php echo esc_html($info['last_status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="debug-section">
                <h3>Post Creation Stats</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <?php foreach ($debug_info['post_stats'] as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo ucwords(str_replace('_', ' ', $key)); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="debug-section">
                <h3>Cron Status</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <?php foreach ($debug_info['cron'] as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo ucwords(str_replace('_', ' ', $key)); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="debug-section">
                <h3>System Information</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <?php foreach ($debug_info['system'] as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo ucwords(str_replace('_', ' ', $key)); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="debug-section">
                <h3>Plugin Conflicts</h3>
                <table class="widefat striped" style="margin-bottom: 20px;">
                    <tr>
                        <td><strong>Conflicting Plugins</strong></td>
                        <td>
                            <?php foreach ($debug_info['plugin_conflicts'] as $plugin): ?>
                                <?php echo esc_html($plugin); ?><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (!empty($debug_info['recent_errors'])): ?>
            <div class="debug-section">
                <h3>Recent Error Log</h3>
                <div style="background: #f6f7f7; padding: 10px; max-height: 200px; overflow-y: auto;">
                    <?php foreach ($debug_info['recent_errors'] as $error): ?>
                        <div style="font-family: monospace; margin-bottom: 5px;"><?php echo esc_html($error); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

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
            
            // Track settings save
            sumai_track_settings_save();
            
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
    $feed_urls = isset($options['feed_urls']) ? array_filter(explode("\n", $options['feed_urls'])) : array();
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $draft_mode = isset($options['draft_mode']) ? $options['draft_mode'] : true;
    
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
        <?php sumai_render_debug_info(sumai_get_debug_info()); ?>
        
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

        <!-- Test RSS Feeds -->
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 10px;">
            <h2>Test RSS Feeds</h2>
            <p>Click the button below to test the RSS feeds and check for new content.</p>
            <button class="button button-secondary sumai-test-feeds">Test RSS Feeds</button>
            <div id="sumai-test-results" style="margin-top: 10px;"></div>
        </div>

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

// Test the RSS feed fetching and processing
function sumai_test_feeds() {
    $options = get_option('sumai_settings', array());
    $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    
    if (empty($feed_urls)) {
        return "No feed URLs configured.";
    }

    $processed_items = get_option('sumai_processed_items', array());
    $output = "Testing RSS Feeds:\n\n";
    
    // Get new content
    $content = sumai_fetch_latest_articles($feed_urls);
    
    if (empty($content)) {
        $output .= "No new content found.\n\n";
    } else {
        $output .= "New content found!\n\n";
        $output .= "Content preview:\n";
        $output .= substr($content, 0, 500) . "...\n\n";
    }
    
    $output .= "Previously processed items:\n";
    foreach ($processed_items as $feed_url => $item) {
        $output .= "\nFeed: $feed_url\n";
        $output .= "Last processed: " . date('Y-m-d H:i:s', $item['date']) . "\n";
        $output .= "Title: " . $item['title'] . "\n";
    }
    
    return $output;
}

// Add test button to settings page
add_action('admin_footer', function() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.sumai-test-feeds').click(function(e) {
            e.preventDefault();
            var $button = $(this);
            var $results = $('#sumai-test-results');
            
            $button.prop('disabled', true);
            $button.text('Testing...');
            
            $.post(ajaxurl, {
                action: 'sumai_test_feeds',
                nonce: '<?php echo wp_create_nonce('sumai_test_feeds'); ?>'
            }, function(response) {
                $results.html('<pre>' + response + '</pre>');
                $button.prop('disabled', false);
                $button.text('Test RSS Feeds');
            });
        });
    });
    </script>
    <?php
});

// Add AJAX handler for testing
add_action('wp_ajax_sumai_test_feeds', function() {
    check_ajax_referer('sumai_test_feeds', 'nonce');
    echo sumai_test_feeds();
    wp_die();
});

// Add post creation tracking
function sumai_track_post_creation($post_id, $success = true) {
    if ($success) {
        $total_posts = get_option('sumai_total_posts', 0);
        update_option('sumai_total_posts', $total_posts + 1);
        update_option('sumai_last_post_id', $post_id);
    } else {
        $failed_posts = get_option('sumai_failed_posts', 0);
        update_option('sumai_failed_posts', $failed_posts + 1);
    }
}

// Add settings save tracking
function sumai_track_settings_save() {
    $saves = get_option('sumai_settings_saves', 0);
    update_option('sumai_settings_saves', $saves + 1);
}

// Add cron tracking
function sumai_track_cron_execution() {
    $last_run = get_option('sumai_last_cron_run', 0);
    $current_time = time();
    
    if ($last_run > 0) {
        $expected_run = $last_run + DAY_IN_SECONDS;
        if ($current_time - $expected_run > HOUR_IN_SECONDS) {
            $missed = get_option('sumai_missed_schedules', 0);
            update_option('sumai_missed_schedules', $missed + 1);
        }
    }
    
    update_option('sumai_last_cron_run', $current_time);
}
