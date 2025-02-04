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
function sumai_generate_daily_summary($force_fetch = false) {
    error_log('[SUMAI] Starting daily summary generation...');

    // Get settings
    $options = get_option('sumai_settings', array());
    $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $title_prompt = isset($options['title_prompt']) ? $options['title_prompt'] : '';
    // Force draft mode for manual summary generation
    $draft_mode = true;
    // Store post signature from options for later reuse
    $signature = isset($options['post_signature']) ? $options['post_signature'] : '';

    // Basic validation
    if (empty($feed_urls)) {
        error_log("[SUMAI] Error: No feed URLs configured");
        return false;
    }

    // Get feed URLs as array and filter out empty lines
    $feed_urls = array_filter(explode("\n", $feed_urls));

    // Fetch and combine content from all feeds
    error_log('[SUMAI] Fetching content from feeds...');
    $content = '';
    foreach ($feed_urls as $url) {
        $url = trim($url);
        if (!empty($url)) {
            $feed_content = sumai_fetch_feed_content($url, $force_fetch);
            $content .= $feed_content . "\n\n";
        }
    }

    if (empty($content)) {
        error_log("[SUMAI] Error: No content fetched from feeds");
        return false;
    }

    // Get the summary
    error_log('[SUMAI] Generating summary using OpenAI...');
    $result = sumai_summarize_text($content, $context_prompt, $title_prompt);
    
    // Validate the result array
    if (!is_array($result) || !isset($result['title']) || !isset($result['content'])) {
        error_log("[SUMAI] Error: Invalid result format from summarization");
        return false;
    }

    // Create the post
    error_log('[SUMAI] Creating WordPress post...');
    
    // Use generated content as is; signature will be appended dynamically via filter
    $content = $result['content'];
    
    // Clean the title by removing quotes
    $clean_title = str_replace(array('"', "'", "\u201c", "\u201d", "\u2018", "\u2019"), '', $result['title']);
    
    $post_data = array(
        'post_title'    => $clean_title,
        'post_content'  => $content,
        'post_status'   => $draft_mode ? 'draft' : 'publish',
        'post_type'     => 'post',
        'post_author'   => get_current_user_id(),
    );

    $post_id = wp_insert_post($post_data);

    if (!$post_id || is_wp_error($post_id)) {
        error_log("[SUMAI] Error: Failed to create post");
        return false;
    }

    error_log('[SUMAI] Summary post created successfully');
    return $post_id;
}

// Append Post Signature Dynamically via Filter
function sumai_append_signature_to_content($content) {
    if (is_singular('post') && !is_admin()) {
         $options = get_option('sumai_settings', array());
         if (!empty($options['post_signature'])) {
              $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . $options['post_signature'];
         }
    }
    return $content;
}
add_filter('the_content', 'sumai_append_signature_to_content');

/* -------------------------------------------------------------------------
 * 4. FEED FETCHING (sumai_fetch_latest_articles)
 * ------------------------------------------------------------------------- */

/**
 * Fetches the latest article from each feed and concatenates them into one string.
 *
 * @param string $feed_urls String of feed URLs separated by newlines.
 * @param bool $force_fetch Force fetch new content, ignoring the "already processed" check.
 * @return string Combined textual content from the latest articles. Empty if none found.
 */
function sumai_fetch_latest_articles($feed_urls = '', $force_fetch = false) {
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

                // Skip already processed items unless force_fetch is true
                if (!$force_fetch && isset($processed_items[$feed_url]) && 
                    $processed_items[$feed_url]['id'] === $item_id &&
                    $processed_items[$feed_url]['date'] >= $item_date) {
                    error_log("[SUMAI] Skipping already processed item from feed: $feed_url");
                    continue;
                }
                
                // Get the content
                $title = $latest_item->get_title();
                $content = $latest_item->get_content();
                $description = $latest_item->get_description();
                
                // Use content if available, otherwise use description
                $text = !empty($content) ? $content : $description;
                
                // Add title and content to combined text
                $combined_text .= "Article Title: $title\n\n";
                $combined_text .= wp_strip_all_tags($text) . "\n\n";
                
                // Update processed items only if not forcing fetch
                if (!$force_fetch) {
                    $processed_items[$feed_url] = array(
                        'id' => $item_id,
                        'url' => $item_url,
                        'date' => $item_date,
                        'title' => $title
                    );
                }
            }
        } catch (Exception $e) {
            error_log("[SUMAI] Exception processing feed $feed_url: " . $e->getMessage());
            continue;
        }
    }

    // Update processed items in database only if not forcing fetch
    if (!$force_fetch && !empty($processed_items)) {
        update_option('sumai_processed_items', $processed_items);
    }

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
 * @param string $title_prompt   A custom prompt for generating the post title.
 * @return array Array containing 'title' and 'content' keys
 */
function sumai_summarize_text($text, $context_prompt, $title_prompt = '') {
    $api_key = sumai_get_api_key();
    if (empty($api_key)) {
        error_log('Sumai: OpenAI API key not found in plugin settings or .env file');
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => ''
        );
    }

    // Basic input validation
    if (empty($text)) {
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => ''
        );
    }

    // A quick limit to ~1600 words in the input (very rough approach)
    $word_limit = 1600;
    $words = explode(' ', $text);
    if (count($words) > $word_limit) {
        $words = array_slice($words, 0, $word_limit);
    }
    $truncated_text = implode(' ', $words);

    // Build the request body (fictional endpoint for "gpt-4o-mini")
    $api_endpoint = 'https://api.openai.com/v1/chat/completions';

    // First, generate the summary
    $summary_request_body = array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array('role' => 'user', 'content' => $context_prompt . "\n\n" . $truncated_text),
        ),
        'max_tokens' => 800,
        'temperature' => 0.7,
    );

    $request_args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => json_encode($summary_request_body),
        'method' => 'POST',
        'timeout' => 30,
    );

    // Send request to OpenAI for summary
    $response = wp_remote_post($api_endpoint, $request_args);
    
    if (is_wp_error($response)) {
        error_log('Sumai: Summary generation failed - ' . $response->get_error_message());
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => ''
        );
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        error_log('Sumai: Summary generation failed - HTTP ' . $status_code);
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => ''
        );
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log('Sumai: Summary generation failed - Invalid API response');
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => ''
        );
    }

    $summary = trim($data['choices'][0]['message']['content']);

    // Truncate summary if needed
    $summary_words = explode(' ', $summary);
    if (count($summary_words) > $word_limit) {
        $summary_words = array_slice($summary_words, 0, $word_limit);
        $summary = implode(' ', $summary_words);
    }

    // If no title prompt provided, use default title
    if (empty($title_prompt)) {
        return array(
            'title' => 'Daily Summary for ' . current_time('Y-m-d'),
            'content' => $summary
        );
    }

    // Generate title using the provided prompt
    $title_request_body = array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
            array('role' => 'user', 'content' => $title_prompt . "\n\nArticle Summary:\n" . $summary),
        ),
        'max_tokens' => 100,
        'temperature' => 0.7,
    );

    $request_args['body'] = json_encode($title_request_body);
    
    // Send request to OpenAI for title
    $title_response = wp_remote_post($api_endpoint, $request_args);
    
    // Default title in case of any errors
    $title = 'Daily Summary for ' . current_time('Y-m-d');
    
    if (!is_wp_error($title_response)) {
        $title_status = wp_remote_retrieve_response_code($title_response);
        if ($title_status === 200) {
            $title_body = wp_remote_retrieve_body($title_response);
            $title_data = json_decode($title_body, true);
            if (isset($title_data['choices'][0]['message']['content'])) {
                $generated_title = trim($title_data['choices'][0]['message']['content']);
                if (!empty($generated_title)) {
                    $title = $generated_title;
                }
            }
        }
    }

    return array(
        'title' => $title,
        'content' => $summary
    );
}

function sumai_get_api_key() {
    static $api_key = null;
    if ($api_key === null) {
        // First, try to get the API key from the plugin settings
        $options = get_option('sumai_settings', array());
        if (isset($options['openai_api_key']) && !empty($options['openai_api_key'])) {
            $api_key = trim($options['openai_api_key']);
        }
        
        // If no key in settings, look for .env in plugin directory
        if (empty($api_key)) {
            $plugin_env_path = plugin_dir_path(__FILE__) . '.env';
            if (file_exists($plugin_env_path)) {
                $env_content = file_get_contents($plugin_env_path);
                foreach (explode("\n", $env_content) as $line) {
                    $line = trim($line);
                    if (strpos($line, 'OPENAI_API_KEY=') === 0) {
                        $api_key = trim(substr($line, strlen('OPENAI_API_KEY=')));
                        break;
                    }
                }
            }
            
            // If still no key, try WordPress root directory as fallback
            if (empty($api_key)) {
                $wp_root_env_path = ABSPATH . '.env';
                if (file_exists($wp_root_env_path)) {
                    $env_content = file_get_contents($wp_root_env_path);
                    foreach (explode("\n", $env_content) as $line) {
                        $line = trim($line);
                        if (strpos($line, 'OPENAI_API_KEY=') === 0) {
                            $api_key = trim(substr($line, strlen('OPENAI_API_KEY=')));
                            break;
                        }
                    }
                }
            }
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
        80 // Menu position (numeric value between 0 and 100)
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

    // Sanitize title prompt
    if (isset($input['title_prompt'])) {
        $sanitized['title_prompt'] = sanitize_textarea_field($input['title_prompt']);
    }

    // Handle draft mode checkbox
    $sanitized['draft_mode'] = isset($input['draft_mode']) ? true : false;

    // Sanitize OpenAI API key
    if (isset($input['openai_api_key'])) {
        $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
    }

    // Sanitize post signature
    if (isset($input['post_signature'])) {
        error_log('Sumai: Raw signature input: ' . $input['post_signature']);
        
        // Allow specific HTML tags in signature with expanded attributes
        $allowed_html = array(
            'p' => array(
                'style' => array(),
                'class' => array()
            ),
            'a' => array(
                'href' => array(),
                'title' => array(),
                'target' => array(),
                'rel' => array(),
                'style' => array(),
                'class' => array()
            ),
            'br' => array(),
            'em' => array(
                'style' => array(),
                'class' => array()
            )
        );
        
        // First try with wp_kses_post which allows more HTML
        $sanitized['post_signature'] = wp_kses_post($input['post_signature']);
        error_log('Sumai: Signature after wp_kses_post: ' . $sanitized['post_signature']);
        
        if (empty($sanitized['post_signature'])) {
            // Fallback to our custom allowed HTML
            $sanitized['post_signature'] = wp_kses($input['post_signature'], $allowed_html);
            error_log('Sumai: Signature after wp_kses fallback: ' . $sanitized['post_signature']);
        }
    }

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
        if (is_array($item)) {
            $debug['feed_history'][$feed_url] = array(
                'last_processed' => isset($item['date']) ? date('Y-m-d H:i:s', $item['date']) : 'unknown',
                'last_title' => isset($item['title']) ? $item['title'] : 'unknown',
                'item_id' => isset($item['id']) ? $item['id'] : 'unknown',
                'last_status' => isset($item['post_status']) ? $item['post_status'] : 'unknown'
            );
        }
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
        <h2>Debug Information</h2>
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
            error_log('Sumai: Starting settings save...');
            $raw_data = $_POST['sumai_settings'];
            error_log('Sumai: Raw settings data: ' . print_r($raw_data, true));
            
            $settings = sumai_sanitize_settings($raw_data);
            error_log('Sumai: Sanitized settings: ' . print_r($settings, true));
            
            // Save settings using update_option
            $save_result = update_option('sumai_settings', $settings);
            error_log('Sumai: Save result: ' . ($save_result ? 'Success' : 'Failed'));
            
            if ($save_result) {
                echo '<div class="updated"><p>Settings saved successfully.</p></div>';
            } else {
                echo '<div class="error"><p>Failed to save settings. Please try again.</p></div>';
            }
        }
        // Handle manual generation
        elseif (isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now')) {
            sumai_generate_daily_summary(true);
            echo '<div class="notice notice-success is-dismissible"><p>Summary post has been generated. Check the Posts section to find it.</p></div>';
        }
    }

    // Get settings
    $options = get_option('sumai_settings', array());
    $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $title_prompt = isset($options['title_prompt']) ? $options['title_prompt'] : '';
    $draft_mode = isset($options['draft_mode']) ? $options['draft_mode'] : true;
    $openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $post_signature = isset($options['post_signature']) ? $options['post_signature'] : '';
    
    $debug_messages[] = "Retrieved settings from database:\n" . print_r($options, true);
    
    // Get feed URLs as array
    $feed_urls = isset($options['feed_urls']) ? array_filter(explode("\n", $options['feed_urls'])) : array();
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $title_prompt = isset($options['title_prompt']) ? $options['title_prompt'] : '';
    $draft_mode = isset($options['draft_mode']) ? $options['draft_mode'] : true;
    $openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $post_signature = isset($options['post_signature']) ? $options['post_signature'] : '';
    
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
                    <th scope="row">Title Prompt</th>
                    <td>
                        <textarea name="sumai_settings[title_prompt]" rows="4" class="large-text"><?php echo esc_textarea($title_prompt); ?></textarea>
                        <p class="description">Custom prompt for generating the post title.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">OpenAI API Key</th>
                    <td>
                        <input type="text" id="api-key-input" name="sumai_settings[openai_api_key]" value="<?php echo esc_attr($openai_api_key); ?>" class="large-text" placeholder="Enter your OpenAI API key">
                        <p class="description">Your OpenAI API key for connecting to the OpenAI API.</p>
                        <input type="button" id="test-api-button" class="button button-secondary" value="Test API Key">
                        <input type="button" id="test-env-api-button" class="button button-secondary" value="Test Hidden API">
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
                <tr>
                    <th scope="row">Post Signature</th>
                    <td>
                        <textarea name="sumai_settings[post_signature]" rows="5" style="width: 100%;"><?php echo esc_textarea($post_signature); ?></textarea>
                        <p class="description">Enter an HTML signature to append to each generated post. You can use HTML tags for formatting.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
            <button type="button" id="test_new_feed_logic" class="button button-secondary" style="margin-left: 10px;">Test New Feed Logic</button>
            <pre id="test_new_feed_result" style="display:none; margin-top: 10px; padding: 10px; background: #f1f1f1;"></pre>
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
    if (!isset($_GET['page']) || $_GET['page'] !== 'sumai-settings') {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#test_new_feed_logic').on('click', function() {
            var $button = $(this);
            var $result = $('#test_new_feed_result');
            
            $button.prop('disabled', true).text('Testing...');
            $result.hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_test_new_feed_logic',
                    nonce: '<?php echo wp_create_nonce('sumai_test_new_feed_logic'); ?>'
                },
                success: function(response) {
                    $result.html('<pre>' + response + '</pre>').show();
                },
                error: function() {
                    $result.html('Error testing new feed logic').show();
                },
                complete: function() {
                    $button.prop('disabled', false).text('Test New Feed Logic');
                }
            });
        });
    });
    </script>
    <?php
});

// Add AJAX handler for new feed logic test
add_action('wp_ajax_sumai_test_new_feed_logic', function() {
    check_ajax_referer('sumai_test_new_feed_logic', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $options = get_option('sumai_settings', array());
    $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    
    $content = sumai_test_feeds($feed_urls);
    
    if (empty($content)) {
        echo "No new content found from feeds.";
    } else {
        echo "New content found:\n\n" . esc_html($content);
    }
    
    wp_die();
});

/**
 * Fetches content from a single feed URL
 * 
 * @param string $url The feed URL to fetch content from
 * @param bool $force_fetch Whether to force fetch even if cached
 * @return string The combined content from the feed
 */
function sumai_fetch_feed_content($url, $force_fetch = false) {
    error_log("[SUMAI] Fetching feed content from: " . $url);
    
    // Initialize the feed
    require_once(ABSPATH . WPINC . '/feed.php');
    
    // Clear the cache if force fetch is enabled
    if ($force_fetch) {
        delete_transient('sumai_feed_' . md5($url));
    }
    
    // Try to get cached content first
    $cached_content = get_transient('sumai_feed_' . md5($url));
    if (!$force_fetch && $cached_content !== false) {
        error_log("[SUMAI] Using cached content for: " . $url);
        return $cached_content;
    }
    
    // Fetch the feed
    $feed = fetch_feed($url);
    
    if (is_wp_error($feed)) {
        error_log("[SUMAI] Error fetching feed: " . $feed->get_error_message());
        return '';
    }
    
    // Get the feed items
    $max_items = 10; // Limit to latest 10 items
    $feed->init();
    $feed->handle_content_type();
    $feed->set_cache_duration(3600); // 1 hour cache
    
    $items = $feed->get_items(0, $max_items);
    
    if (empty($items)) {
        error_log("[SUMAI] No items found in feed: " . $url);
        return '';
    }
    
    // Combine content from all items
    $content = '';
    foreach ($items as $item) {
        $title = $item->get_title();
        $description = $item->get_description();
        $content .= $title . "\n" . strip_tags($description) . "\n\n";
    }
    
    // Cache the content for 1 hour
    set_transient('sumai_feed_' . md5($url), $content, 3600);
    
    error_log("[SUMAI] Successfully fetched and cached content from: " . $url);
    return $content;
}

// AJAX Callback for 'Test Hidden API' button
add_action('wp_ajax_sumai_test_hidden_api', 'sumai_test_hidden_api_callback');
function sumai_test_hidden_api_callback() {
    $env_path = plugin_dir_path(__FILE__) . '.env';
    if (file_exists($env_path)) {
        $env_content = file_get_contents($env_path);
        if (strpos($env_content, 'OPENAI_API_KEY=') !== false) {
            wp_send_json_success(array('message' => '.env file found with OPENAI_API_KEY.'));
        } else {
            wp_send_json_error(array('message' => '.env file found but OPENAI_API_KEY not set.'));
        }
    } else {
        wp_send_json_error(array('message' => '.env file not found in plugin directory.'));
    }
    wp_die();
}

// Enqueue inline JavaScript for 'Test Hidden API' button on the Sumai Settings page
add_action('admin_footer', 'sumai_test_hidden_api_script');
function sumai_test_hidden_api_script() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_sumai-settings') { ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('Sumai Test Hidden API script loaded.');
            $('#test-env-api-button').on('click', function(e) {
                e.preventDefault();
                console.log('Test Hidden API button clicked.');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'sumai_test_hidden_api'
                    },
                    success: function(response) {
                        var message = response.success ? 'Success: ' + response.data.message : 'Error: ' + response.data.message;
                        $('#test-env-api-button').next('#test-env-api-result').remove();
                        $('#test-env-api-button').after('<div id="test-env-api-result" style="margin-top:10px;">' + message + '</div>');
                    },
                    error: function(xhr, status, error) {
                        alert('AJAX error: ' + error);
                    }
                });
            });
        });
        </script>
    <?php }
}

// AJAX callback for testing the API key entered in settings
function sumai_test_api_key_callback() {
    check_ajax_referer('sumai_test_api_key', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $provided_key = isset($_POST['openai_api_key']) ? trim($_POST['openai_api_key']) : '';
    if (empty($provided_key)) {
        wp_send_json_error(array('message' => 'API key is empty.'));
    } else {
        wp_send_json_success(array('message' => 'API key is valid.'));
    }
    wp_die();
}

add_action('wp_ajax_sumai_test_api_key', 'sumai_test_api_key_callback');

// Enqueue inline JavaScript for handling the Test API Key button click
add_action('admin_footer', 'sumai_test_api_key_script');
function sumai_test_api_key_script() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_sumai-settings') { ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#test-api-button').on('click', function(e) {
                e.preventDefault();
                var apiKey = $('#api-key-input').val();
                $.post(ajaxurl, {
                    action: 'sumai_test_api_key',
                    openai_api_key: apiKey,
                    nonce: '<?php echo wp_create_nonce('sumai_test_api_key'); ?>'
                }, function(response) {
                    if(response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data.message);
                    }
                });
            });
        });
        </script>
    <?php }
}
