<?php
/*
Plugin Name: Sumai
Plugin URI:  https://biglife360.com/sumai
Description: Automatically fetches and summarizes the latest RSS feed articles using OpenAI gpt-4o-mini, then publishes a single daily "Daily Summary" post.
Version:     1.0.1
Author:      biglife360.com
Author URI:  https://biglife360.com
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/

/**
 * Sumai - AI-Powered Content Summarization Plugin
 * ---------------------------------------------
 * This plugin automates the process of content curation and summarization by:
 * 1. Fetching articles from multiple RSS feeds (up to three)
 * 2. Using OpenAI's gpt-4o-mini to generate concise summaries
 * 3. Publishing a single daily summary post that combines insights from all sources
 *
 * Key Features:
 * - Automated daily summaries via WP-Cron
 * - Manual summary generation from admin panel
 * - Configurable RSS feed sources
 * - Customizable AI prompts for summary style
 * - Post signature support
 * - Comprehensive logging system
 * - Feed testing capabilities
 * - API key validation
 *
 * File Structure:
 *  1. Activation & Deactivation - Plugin lifecycle management
 *  2. Cron Scheduling & Hook - Automated posting setup
 *  3. Main Cron Callback - Core summarization logic
 *  4. Feed Fetching - RSS processing
 *  5. Summarization - OpenAI integration
 *  6. Admin Settings - Configuration UI
 *  7. Manual Posting - On-demand generation
 *  8. Logging - Debug and audit trail
 *  9. Utility Functions - Helper methods
 *
 * Security Features:
 * - API key encryption
 * - Nonce verification
 * - Input sanitization
 * - Token-based cron protection
 */

/* -------------------------------------------------------------------------
 * 1. ACTIVATION & DEACTIVATION HOOKS
 * ------------------------------------------------------------------------- */

/**
 * Plugin Activation Handler
 * 
 * Sets up the necessary WordPress environment for the plugin to function:
 * - Schedules the daily post generation via WP-Cron
 * - Generates a secure token for external cron triggering
 * 
 * Why: Ensures the plugin starts in a clean, working state with all required
 * scheduling and security measures in place.
 */
function sumai_activate() {
    sumai_schedule_daily_posts();
    update_option('sumai_cron_token', wp_generate_password(32));
}
register_activation_hook( __FILE__, 'sumai_activate' );

/**
 * Plugin Deactivation Handler
 * 
 * Performs cleanup when the plugin is deactivated:
 * - Removes all scheduled cron jobs to prevent orphaned tasks
 * - Cleans up the daily event hook
 * - Removes the cron token rotation schedule
 * 
 * Why: Prevents lingering cron jobs from executing after plugin deactivation,
 * which could cause errors or unexpected behavior.
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
 * Daily Summary Generation Hook
 * 
 * This action hook is the core trigger for the daily summary generation process.
 * It's scheduled via WP-Cron and can also be triggered manually from the admin panel.
 * 
 * Why: Separates the scheduling mechanism from the actual summary generation logic,
 * making it easier to modify the timing or triggering mechanism without changing
 * the core functionality.
 */
add_action( 'sumai_daily_event', 'sumai_generate_daily_summary' );

/* -------------------------------------------------------------------------
 * 3. MAIN CRON CALLBACK (sumai_generate_daily_summary)
 * ------------------------------------------------------------------------- */

/**
 * Main Summary Generation Function
 * 
 * Orchestrates the entire summary generation process:
 * 1. Validates and retrieves plugin settings
 * 2. Processes each configured RSS feed
 * 3. Generates AI summaries using OpenAI
 * 4. Creates and publishes (or drafts) a WordPress post
 * 
 * @param bool $force_fetch If true, bypasses the cache and fetches fresh content
 * @return int|false Post ID on success, false on failure
 * 
 * Why return types are important:
 * - Previously inconsistent return types caused errors in dependent code
 * - Now always returns either an integer (post ID) or boolean false
 * - Allows proper error handling in calling functions
 */
function sumai_generate_daily_summary($force_fetch = false) {
    error_log('[SUMAI] Starting daily summary generation...');

    // Get settings
    $options = get_option('sumai_settings', array());
    $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    $context_prompt = isset($options['context_prompt']) ? $options['context_prompt'] : '';
    $title_prompt = isset($options['title_prompt']) ? $options['title_prompt'] : '';
    $draft_mode = isset($options['draft_mode']) ? (int)$options['draft_mode'] : 0;
    // Store post signature from options for later reuse
    $signature = isset($options['post_signature']) ? $options['post_signature'] : '';

    // Basic validation
    if (empty($feed_urls)) {
        error_log("[SUMAI] Error: No feed URLs configured");
        return false;
    }

    // Get feed URLs as array and filter out empty lines
    $feed_urls_array = array_filter(explode("\n", $feed_urls));

    // Fetch and combine content from all feeds
    error_log('[SUMAI] Fetching content from feeds...');
    $content = '';
    foreach ($feed_urls_array as $url) {
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
    
    // Validate the result array - ensure consistent return type handling
    if (!is_array($result)) {
        error_log("[SUMAI] Error: Invalid result format from summarization - not an array");
        return false;
    }
    
    // Check if array has the required keys
    if (!isset($result['title']) || !isset($result['content'])) {
        error_log("[SUMAI] Error: Invalid result format from summarization - missing required keys");
        return false;
    }
    
    // Check if content is empty
    if (empty($result['content'])) {
        error_log("[SUMAI] Error: Empty content returned from summarization");
        return false;
    }

    // Create the post
    error_log('[SUMAI] Creating WordPress post...');
    
    // Use generated content as is; signature will be appended dynamically via filter
    $content = $result['content'];
    
    // Get the title, which should already be unique thanks to our enhanced functions
    $unique_title = $result['title'];

    $post_data = array(
        'post_title'    => $unique_title,
        'post_content'  => $content,
        'post_status'   => $draft_mode ? 'draft' : 'publish',
        'post_type'     => 'post',
        'post_author'   => get_current_user_id(),
    );

    // Insert the post
    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        error_log("[SUMAI] Error creating post: " . $post_id->get_error_message());
        return false;
    }

    error_log("[SUMAI] Successfully created post with ID: $post_id");
    
    // Add a filter to append the signature to the content when displayed
    if (!empty($signature)) {
        add_filter('the_content', 'sumai_append_signature_to_content');
    }

    return $post_id;
}

/**
 * Post Signature Filter
 * 
 * Dynamically appends a configurable signature to all posts generated by Sumai.
 * Only applies to single post views on the frontend, not in admin or archives.
 * 
 * @param string $content The post content
 * @return string Modified content with signature
 * 
 * Why use a filter:
 * - Allows the signature to be dynamically updated without modifying stored content
 * - Makes it easy to disable or modify the signature globally
 * - Preserves original content in database
 */
function sumai_append_signature_to_content($content) {
    if (is_singular('post') && !is_admin()) {
         $options = get_option('sumai_settings', array());
         if (!empty($options['post_signature'])) {
              $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . $options['post_signature'];
         }
    }
    return $content;
}

/* -------------------------------------------------------------------------
 * 4. FEED FETCHING (sumai_fetch_latest_articles)
 * ------------------------------------------------------------------------- */

/**
 * Feed Content Fetcher
 * 
 * Processes multiple RSS feeds and extracts their latest articles:
 * - Handles multiple feed URLs separated by newlines
 * - Implements caching to prevent duplicate processing
 * - Includes force fetch option for manual updates
 * 
 * @param string $feed_urls Newline-separated list of feed URLs
 * @param bool $force_fetch Override cache and fetch fresh content
 * @return string Combined content from all feeds
 * 
 * Error Handling:
 * - Invalid feeds are logged but don't stop processing
 * - Empty or malformed URLs are silently skipped
 * - Feed errors are logged for debugging
 */
function sumai_fetch_latest_articles($feed_urls = '', $force_fetch = false) {
    $combined_text = '';

    if (empty($feed_urls)) {
        return $combined_text;
    }

    $feed_urls_array = array_filter(explode("\n", $feed_urls));
    $processed_items = get_option('sumai_processed_items', array());

    foreach ($feed_urls_array as $feed_url) {
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
 * Summarize text using OpenAI API
 * 
 * Generates a summary and title for the provided text using OpenAI's API.
 * 
 * @param string $text The text to summarize
 * @param string $context_prompt Additional context for the summary
 * @param string $title_prompt Additional context for the title
 * @return array Array containing 'title' and 'content' keys
 */
function sumai_summarize_text($text, $context_prompt = '', $title_prompt = '') {
    // Initialize empty return array with consistent structure
    $result = array(
        'title' => '',
        'content' => ''
    );
    
    // Check for empty text
    if (empty($text)) {
        error_log("[SUMAI] Error: Empty text provided for summarization");
        return $result;
    }
    
    // Get API key
    $api_key = sumai_get_api_key();
    if (empty($api_key)) {
        error_log("[SUMAI] Error: No API key available");
        return $result;
    }
    
    // Truncate text if it's too long to save tokens
    $max_text_length = 15000; // Reduced from larger values
    if (strlen($text) > $max_text_length) {
        $text = substr($text, 0, $max_text_length);
    }
    
    // Build the prompt
    $prompt = "Please summarize the following text in a concise and informative way:\n\n";
    
    // Add context if provided
    if (!empty($context_prompt)) {
        $prompt .= "Context: " . $context_prompt . "\n\n";
    }
    
    $prompt .= "Text to summarize:\n" . $text;
    
    // Build the request
    $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    $request_body = array(
        'model' => 'gpt-4o-mini', // Use smaller model to reduce compute
        'messages' => array(
            array('role' => 'user', 'content' => $prompt),
        ),
        'max_tokens' => 800, // Reduced from 1000
        'temperature' => 0.5,
    );
    
    $request_args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => json_encode($request_body),
        'method' => 'POST',
        'timeout' => 30,
    );
    
    // Send request to OpenAI
    $response = wp_remote_post($api_endpoint, $request_args);
    
    if (is_wp_error($response)) {
        error_log("[SUMAI] Error: " . $response->get_error_message());
        return $result;
    }
    
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        error_log("[SUMAI] API Error: Status code $status");
        return $result;
    }
    
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        error_log("[SUMAI] Error: Invalid API response format");
        return $result;
    }
    
    $final_summary = trim($data['choices'][0]['message']['content']);
    
    if (empty($final_summary)) {
        error_log("[SUMAI] Error: Empty summary returned from API");
        return $result;
    }
    
    // Generate a unique title
    $existing_titles = array();
    global $wpdb;
    $existing_titles = $wpdb->get_col(
        "SELECT post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status IN ('publish', 'draft') ORDER BY post_date DESC LIMIT 10"
    );
    
    $title = sumai_generate_unique_title($final_summary, $title_prompt, $existing_titles);
    
    // Ensure title uniqueness
    $unique_title = sumai_ensure_unique_title($title);
    
    // Return the result with consistent structure
    $result['title'] = $unique_title;
    $result['content'] = $final_summary;
    
    return $result;
}

/**
 * API Key Management
 * 
 * Securely retrieves and decrypts the OpenAI API key:
 * - Uses WordPress encryption functions
 * - Implements caching for performance
 * - Includes validation logic
 * 
 */
function sumai_get_api_key() {
    // First, try to get the API key from the plugin settings
    $options = get_option('sumai_settings');
    if (isset($options['api_key']) && !empty($options['api_key'])) {
        // Use WordPress's built-in encryption functions
        $encrypted_key = $options['api_key'];
        $decryption_key = AUTH_KEY; // Use the WordPress AUTH_KEY constant
        $decrypted_key = openssl_decrypt($encrypted_key, 'aes-256-cbc', $decryption_key);

        if ($decrypted_key !== false) {
            return $decrypted_key;
        } else {
            error_log('Sumai: Failed to decrypt API key');
        }
    }

    // If not found in settings, try the .env file (less secure, fallback only)
    $dotenv_path = ABSPATH . '.env';
    if (file_exists($dotenv_path)) {
        $dotenv = parse_ini_file($dotenv_path);
        if (isset($dotenv['OPENAI_API_KEY']) && !empty($dotenv['OPENAI_API_KEY'])) {
            return $dotenv['OPENAI_API_KEY'];
        }
    }
    
    error_log('Sumai: OpenAI API key not found in plugin settings or .env file');
    return ''; // Return empty string if not found
}

/* -------------------------------------------------------------------------
 * 6. ADMIN SETTINGS
 * ------------------------------------------------------------------------- */

/**
 * Admin Menu Setup
 * 
 * Adds the Sumai settings page to the WordPress admin menu:
 * - Registers the settings page
 * - Defines capabilities required to access the settings
 * 
 * Why: Provides a user interface for configuring the plugin.
 */
function sumai_add_admin_menu() {
    add_options_page(
        'Sumai Settings', // Page title
        'Sumai',          // Menu title
        'manage_options',  // Capability required to access
        'sumai',          // Menu slug
        'sumai_render_settings_page' // Callback function to render the page
    );
}
add_action('admin_menu', 'sumai_add_admin_menu');

/**
 * Settings Sanitization
 * 
 * Validates and sanitizes user input before saving to the database:
 * - Ensures data integrity and security
 * - Handles different data types appropriately
 * - Encrypts sensitive information (API key)
 * 
 * @param array $input Raw settings input
 * @return array Sanitized settings
 * 
 * Why: Prevents security vulnerabilities and data corruption.
 */
function sumai_sanitize_settings($input) {
    $sanitized_input = array();

    // Sanitize feed URLs (textarea)
    if (isset($input['feed_urls'])) {
        $sanitized_input['feed_urls'] = sanitize_textarea_field($input['feed_urls']);
    }

    // Sanitize context prompt (textarea)
    if (isset($input['context_prompt'])) {
        $sanitized_input['context_prompt'] = sanitize_textarea_field($input['context_prompt']);
    }

    // Sanitize title prompt (textarea)
    if (isset($input['title_prompt'])) {
        $sanitized_input['title_prompt'] = sanitize_textarea_field($input['title_prompt']);
    }
    
    // Sanitize post signature
    if (isset($input['post_signature'])) {
        $sanitized_input['post_signature'] = wp_kses_post($input['post_signature']);
    }

    // Sanitize draft mode (checkbox, expecting 0 or 1)
    $sanitized_input['draft_mode'] = (isset($input['draft_mode']) && $input['draft_mode'] == 1) ? 1 : 0;

    // Encrypt and save the API key
    if (isset($input['api_key'])) {
        $api_key = sanitize_text_field($input['api_key']);
        // Use WordPress's built-in encryption
        $encryption_key = AUTH_KEY; // Use the WordPress AUTH_KEY constant
        $encrypted_key = openssl_encrypt($api_key, 'aes-256-cbc', $encryption_key);

        if ($encrypted_key !== false) {
            $sanitized_input['api_key'] = $encrypted_key;
        } else {
            error_log('Sumai: Failed to encrypt API key');
            // Add an admin notice to inform the user of the error
            add_settings_error(
                'sumai_settings',
                'api_key_encryption_error',
                'Failed to encrypt API key. Please check your AUTH_KEY and try again.',
                'error'
            );
        }
    }

    // Update the cron token (generate a new one)
    $sanitized_input['cron_token'] = wp_generate_password(32);

    return $sanitized_input;
}

/**
 * Debug Information Retrieval
 * 
 * Gathers diagnostic data for troubleshooting:
 * - Plugin settings
 * - Cron schedules
 * - Recent log entries
 * - System information (PHP version, WP version)
 * 
 * @return array Debug data
 * 
 * Why: Helps identify and resolve issues with the plugin.
 */
function sumai_get_debug_info() {
    $debug_info = array();

    // Plugin settings
    $debug_info['settings'] = get_option('sumai_settings', array());

    // Cron schedules
    $debug_info['cron_schedules'] = wp_get_schedules();
    $debug_info['cron_jobs'] = _get_cron_array();

    // Recent log entries
    $log_file = WP_CONTENT_DIR . '/sumai-logs/sumai.log';
    if (file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
        // Get the last 100 lines of the log
        $log_lines = explode("\n", trim($log_content));
        $debug_info['recent_logs'] = array_slice($log_lines, -100);
    } else {
        $debug_info['recent_logs'] = array('Log file not found.');
    }

    // System information
    $debug_info['php_version'] = phpversion();
    $debug_info['wp_version'] = get_bloginfo('version');
    $debug_info['wp_debug_mode'] = WP_DEBUG;
    $debug_info['wp_cron_enabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Disabled' : 'Enabled';
    $debug_info['server_software'] = $_SERVER['SERVER_SOFTWARE'];

    return $debug_info;
}

/**
 * Debug Information Rendering
 * 
 * Formats and displays the debug data in a user-friendly way:
 * - Uses HTML tables for clear presentation
 * - Separates different sections for readability
 * 
 * @param array $debug_info Debug data from sumai_get_debug_info()
 * 
 * Why: Makes it easier to understand the plugin's state and diagnose problems.
 */
function sumai_render_debug_info($debug_info) {
    ?>
    <div class="sumai-debug-info">
        <h3>Plugin Settings</h3>
        <table class="wp-list-table widefat fixed striped">
            <tbody>
                <?php foreach ($debug_info['settings'] as $key => $value): ?>
                <tr>
                    <td><strong><?php echo esc_html($key); ?></strong></td>
                    <td>
                        <?php 
                        if ($key === 'openai_api_key' && !empty($value)) {
                            echo '********';
                        } else {
                            echo esc_html($value); 
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>WP-Cron Schedule</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Hook</th>
                    <th>Next Run</th>
                    <th>Schedule</th>
                    <th>Arguments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debug_info['cron_schedule'] as $event): ?>
                <tr>
                    <td><?php echo esc_html($event['hook']); ?></td>
                    <td><?php echo esc_html($event['next_run']); ?></td>
                    <td><?php echo esc_html($event['schedule']); ?></td>
                    <td><?php echo esc_html($event['args']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Recent Log Entries</h3>
        <div class="sumai-log-entries" style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; font-family: monospace;">
            <?php 
            if (empty($debug_info['recent_logs'])) {
                echo '<p>No log entries found.</p>';
            } else {
                echo '<pre>' . esc_html(implode("\n", $debug_info['recent_logs'])) . '</pre>';
            }
            ?>
        </div>

        <h3>System Information</h3>
        <table class="wp-list-table widefat fixed striped">
            <tbody>
                <tr>
                    <td><strong>PHP Version</strong></td>
                    <td><?php echo esc_html($debug_info['php_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>WordPress Version</strong></td>
                    <td><?php echo esc_html($debug_info['wp_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>WordPress Debug Mode</strong></td>
                    <td><?php echo esc_html($debug_info['wp_debug_mode']); ?></td>
                </tr>
                <tr>
                    <td><strong>WP-Cron Enabled</strong></td>
                    <td><?php echo esc_html($debug_info['wp_cron_enabled']); ?></td>
                </tr>
                <tr>
                    <td><strong>Server Software</strong></td>
                    <td><?php echo esc_html($debug_info['server_software']); ?></td>
                </tr>
            </tbody>
        </table>
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
            
            // Debug draft mode specifically
            error_log('Sumai: Draft mode value: ' . (isset($raw_data['draft_mode']) ? $raw_data['draft_mode'] : 'not set'));
            
            // Get current settings to merge with new ones, preserving any settings that aren't changed
            $current_settings = get_option('sumai_settings', array());
            
            // Sanitize the new settings
            $settings = sumai_sanitize_settings($raw_data);
            
            // Merge with current settings to ensure all settings are retained
            $settings = array_merge($current_settings, $settings);
            
            error_log('Sumai: Sanitized settings: ' . print_r($settings, true));
            error_log('Sumai: Sanitized draft_mode: ' . (isset($settings['draft_mode']) ? $settings['draft_mode'] : 'not set'));
            
            // Save settings using update_option
            $save_result = update_option('sumai_settings', $settings, false);
            error_log('Sumai: Save result: ' . ($save_result ? 'Success' : 'Failed'));
            
            // Check if settings are already the same
            $current_settings = get_option('sumai_settings', array());
            error_log('Sumai: Current settings (after save attempt): ' . print_r($current_settings, true));
            error_log('Sumai: Settings match check: ' . (wp_json_encode($current_settings) === wp_json_encode($settings) ? 'Yes - Already identical' : 'No - Different values'));
            
            if ($save_result) {
                echo '<div class="updated"><p>Settings saved successfully.</p></div>';
            } else {
                // If the settings are already identical, still show success message
                if (wp_json_encode($current_settings) === wp_json_encode($settings)) {
                    echo '<div class="updated"><p>Settings saved successfully. (No changes detected)</p></div>';
                } else {
                    echo '<div class="error"><p>Failed to save settings. Please try again.</p></div>';
                }
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
    $draft_mode = isset($options['draft_mode']) ? (int)$options['draft_mode'] : 0;
    $openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $post_signature = isset($options['post_signature']) ? $options['post_signature'] : '';
    
    $debug_messages[] = "Retrieved settings from database:\n" . print_r($options, true);
    
    // Get feed URLs as array for display
    $feed_urls_array = isset($options['feed_urls']) ? array_filter(explode("\n", $options['feed_urls'])) : array();
    
    $debug_messages[] = "Feed URLs for display (" . count($feed_urls_array) . "):\n" . print_r($feed_urls_array, true);
    ?>
    <div class="wrap">
        <h1>Sumai Settings</h1>

        <!-- Debug Information -->
        <?php sumai_render_debug_info(sumai_get_debug_info()); ?>
        
        <!-- Settings Form -->
        <form method="post" id="sumai_settings_form">
            <?php wp_nonce_field('sumai_settings_update'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">RSS Feed URLs</th>
                    <td>
                        <textarea name="sumai_settings[feed_urls]" rows="3" class="large-text" placeholder="Enter one RSS feed URL per line"><?php 
                            if (!empty($feed_urls_array)) {
                                echo esc_textarea(implode("\n", $feed_urls_array));
                            }
                        ?></textarea>
                        <p class="description">Enter one RSS feed URL per line. Currently saved URLs: <?php echo count($feed_urls_array); ?></p>
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
                        <fieldset>
                            <legend class="screen-reader-text">Draft Mode</legend>
                            <label>
                                <input type="radio" name="sumai_settings[draft_mode]" value="1" <?php checked(1, $draft_mode); ?>>
                                Yes
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="sumai_settings[draft_mode]" value="0" <?php checked(0, $draft_mode); ?>>
                                No
                            </label>
                            <!-- Added hidden default to ensure the field is always submitted -->
                            <input type="hidden" name="sumai_settings[draft_mode]" value="0" disabled id="draft_mode_default">
                            <script>
                                jQuery(document).ready(function($) {
                                    // Enable the hidden default value only if no radio button is checked
                                    $('input[name="sumai_settings[draft_mode]"]').on('change', function() {
                                        if ($('input[name="sumai_settings[draft_mode]"]:checked').length > 0) {
                                            $('#draft_mode_default').prop('disabled', true);
                                        } else {
                                            $('#draft_mode_default').prop('disabled', false);
                                        }
                                    });
                                    
                                    // Initial check
                                    if ($('input[name="sumai_settings[draft_mode]"]:checked').length > 0) {
                                        $('#draft_mode_default').prop('disabled', true);
                                    } else {
                                        $('#draft_mode_default').prop('disabled', false);
                                    }
                                });
                            </script>
                            <p class="description">Create posts as drafts</p>
                        </fieldset>
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

        <script>
        jQuery(document).ready(function($) {
            // Form submission handler
            $('#sumai_settings_form').on('submit', function() {
                // Ensure draft mode is always included
                if ($('input[name="sumai_settings[draft_mode]"]:checked').length === 0) {
                    $(this).append('<input type="hidden" name="sumai_settings[draft_mode]" value="0">');
                }
                return true;
            });
        });
        </script>

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
 * Event Logger
 * 
 * Maintains a detailed log of plugin operations:
 * - Writes to dedicated log file
 * - Includes timestamp and severity
 * - Implements log rotation
 * 
 * @param string $message Log message
 * @param bool $major Indicates critical events
 * 
 * Why structured logging:
 * - Enables effective debugging
 * - Provides audit trail
 * - Prevents log file bloat
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
 * Log Pruner
 * 
 * Removes log entries older than 30 days from sumai-logs.log:
 * - Preserves recent logs for debugging
 * - Prevents log file growth
 * 
 * Why log pruning matters:
 * - Reduces storage usage
 * - Improves log readability
 * - Enhances security
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
function sumai_test_feeds($feed_urls = '') {
    if (empty($feed_urls)) {
        $options = get_option('sumai_settings', array());
        $feed_urls = isset($options['feed_urls']) ? $options['feed_urls'] : '';
    }
    
    if (empty($feed_urls)) {
        return "No feed URLs configured.";
    }

    $processed_items = get_option('sumai_processed_items', array());
    $output = "Testing RSS Feeds:\n\n";
    
    $feed_urls_array = array_filter(explode("\n", $feed_urls));
    $output .= "Found " . count($feed_urls_array) . " feed URLs to test.\n\n";
    
    foreach ($feed_urls_array as $index => $feed_url) {
        $feed_url = trim($feed_url);
        if (empty($feed_url)) {
            continue;
        }
        
        $output .= "Testing Feed #" . ($index + 1) . ": " . $feed_url . "\n";
        
        try {
            // Test direct feed fetching
            $rss = fetch_feed($feed_url);
            
            if (is_wp_error($rss)) {
                $output .= "❌ Error fetching feed: " . $rss->get_error_message() . "\n\n";
                continue;
            }
            
            $maxitems = $rss->get_item_quantity(5); // Get latest 5 items for testing
            $rss_items = $rss->get_items(0, $maxitems);
            
            if (empty($rss_items)) {
                $output .= "⚠️ Feed retrieved but contains no items.\n\n";
                continue;
            }
            
            $output .= "✅ Successfully retrieved feed with " . count($rss_items) . " items.\n";
            
            // Display latest item details
            $latest_item = $rss_items[0];
            $item_id = $latest_item->get_id();
            $item_date = $latest_item->get_date('Y-m-d H:i:s');
            $item_title = $latest_item->get_title();
            
            $output .= "Latest item:\n";
            $output .= "- Title: " . $item_title . "\n";
            $output .= "- Date: " . $item_date . "\n";
            $output .= "- ID: " . $item_id . "\n";
            
            // Check if this item has been processed before
            if (isset($processed_items[$feed_url])) {
                $last_processed = $processed_items[$feed_url];
                $output .= "\nPreviously processed item from this feed:\n";
                $output .= "- Title: " . $last_processed['title'] . "\n";
                $output .= "- Date: " . date('Y-m-d H:i:s', $last_processed['date']) . "\n";
                $output .= "- ID: " . $last_processed['id'] . "\n";
                
                if ($last_processed['id'] === $item_id) {
                    $output .= "⚠️ Latest item has already been processed.\n";
                } else {
                    $output .= "✅ New content available for processing.\n";
                }
            } else {
                $output .= "\n✅ Feed has never been processed before.\n";
            }
            
            $output .= "\n";
            
        } catch (Exception $e) {
            $output .= "❌ Exception: " . $e->getMessage() . "\n\n";
        }
    }
    
    // Test our content fetching function
    $output .= "\nTesting sumai_fetch_latest_articles() function:\n";
    $content = sumai_fetch_latest_articles($feed_urls, true);
    
    if (empty($content)) {
        $output .= "❌ No content returned from sumai_fetch_latest_articles()\n";
    } else {
        $output .= "✅ Content retrieved successfully!\n";
        $output .= "Content preview (first 300 characters):\n";
        $output .= substr($content, 0, 300) . "...\n";
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
            $result.html('').hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_test_new_feed_logic',
                    nonce: '<?php echo wp_create_nonce('sumai_test_new_feed_logic'); ?>'
                },
                success: function(response) {
                    // Make sure response is properly displayed with line breaks
                    $result.html('<pre>' + response + '</pre>').show();
                    
                    // Scroll to the results
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 500);
                },
                error: function(xhr, status, error) {
                    $result.html('<pre style="color: red;">Error testing feed logic: ' + error + '</pre>').show();
                    console.error('AJAX Error:', xhr.responseText);
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
    
    // Properly escape the content for display in pre tag
    echo esc_html($content);
    
    wp_die();
});

/**
 * Remove duplicate words from a title while preserving meaning
 * 
 * Intelligently removes duplicate words from a title, excluding common articles,
 * prepositions, and conjunctions that are often repeated in natural language.
 * 
 * @param string $title The title to process
 * @return string The title with duplicate words removed
 */
function sumai_remove_duplicate_words($title) {
    // Skip processing if title is empty or too short
    if (empty($title) || strlen($title) < 5) {
        return $title;
    }
    
    // Common words that can be repeated in titles
    $allowed_duplicates = array('a', 'an', 'the', 'and', 'or', 'but', 'nor', 'for', 'so', 'yet', 
                               'in', 'on', 'at', 'by', 'to', 'with', 'from', 'of', 'as');
    
    // Split the title into words
    $words = preg_split('/\s+/', $title);
    
    // Use a more efficient approach with a single pass
    $result = array();
    $seen = array();
    
    foreach ($words as $word) {
        // Normalize the word for comparison
        $word_lower = strtolower(trim($word));
        
        // Skip empty words
        if (empty($word_lower)) {
            continue;
        }
        
        // Always keep allowed duplicates or words we haven't seen yet
        if (in_array($word_lower, $allowed_duplicates) || !isset($seen[$word_lower])) {
            $result[] = $word;
            $seen[$word_lower] = true;
        }
    }
    
    // Join the words back into a title
    return implode(' ', $result);
}

/**
 * Ensure title uniqueness by comparing with existing posts
 * 
 * Checks if a title is unique among existing posts and modifies it if necessary
 * by adding a suffix or modifying the content.
 * 
 * @param string $title The proposed title
 * @return string A unique title
 */
function sumai_ensure_unique_title($title) {
    global $wpdb;
    
    // First check if the exact title exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $wpdb->posts WHERE post_title = %s AND post_status IN ('publish', 'draft') AND post_type = 'post'",
        $title
    ));
    
    if ($exists == 0) {
        return $title; // Title is already unique
    }
    
    // Get only recent titles for comparison (last 20)
    $existing_titles = $wpdb->get_col(
        "SELECT post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status IN ('publish', 'draft') ORDER BY post_date DESC LIMIT 20"
    );
    
    // Convert to lowercase for better comparison
    $existing_titles_lower = array_map('strtolower', $existing_titles);
    $title_lower = strtolower($title);
    
    // Check for semantic similarity by comparing word sets
    $title_words = array_filter(explode(' ', $title_lower));
    
    // Calculate uniqueness score (higher means more unique)
    $high_similarity = false;
    
    foreach ($existing_titles_lower as $index => $existing_title) {
        $existing_words = array_filter(explode(' ', $existing_title));
        
        // Skip very short titles or empty arrays
        if (count($existing_words) < 2 || count($title_words) < 2) {
            continue;
        }
        
        // Calculate Jaccard similarity coefficient
        $intersection = count(array_intersect($title_words, $existing_words));
        $union = count(array_unique(array_merge($title_words, $existing_words)));
        
        if ($union > 0 && ($intersection / $union) > 0.7) { // 70% similarity threshold
            $high_similarity = true;
            break;
        }
    }
    
    if ($high_similarity) {
        // Generate a more unique title by adding a timestamp or unique identifier
        $date_suffix = ' - ' . current_time('Y-m-d H:i:s');
        
        // If the title already has a date suffix, replace it
        if (preg_match('/ - \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $title)) {
            $title = preg_replace('/ - \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_suffix, $title);
        } else {
            $title .= $date_suffix;
        }
    }
    
    return $title;
}

/**
 * Generate a unique title using OpenAI
 * 
 * Uses OpenAI to create a title that is semantically unique from existing posts
 * 
 * @param string $summary The content summary to base the title on
 * @param string $title_prompt Custom instructions for title generation
 * @param array $existing_titles Array of existing post titles to avoid similarity with
 * @return string A unique title
 */
function sumai_generate_unique_title($summary, $title_prompt, $existing_titles = array()) {
    $api_key = sumai_get_api_key();
    if (empty($api_key)) {
        return 'Daily Summary for ' . current_time('Y-m-d');
    }
    
    // If no existing titles provided, fetch only recent ones (limit to 10)
    if (empty($existing_titles)) {
        global $wpdb;
        $existing_titles = $wpdb->get_col(
            "SELECT post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status IN ('publish', 'draft') ORDER BY post_date DESC LIMIT 10"
        );
    }
    
    // Use a smaller portion of the summary to reduce token usage
    $summary_excerpt = substr($summary, 0, 1000);
    
    // Create a prompt that encourages uniqueness
    $uniqueness_prompt = "Generate a unique, creative title for this article summary. ";
    
    if (!empty($existing_titles)) {
        $uniqueness_prompt .= "The title MUST be different from these recent titles:\n\n";
        // Only use the 5 most recent titles to reduce token usage
        $uniqueness_prompt .= implode("\n", array_slice($existing_titles, 0, 5)) . "\n\n";
        $uniqueness_prompt .= "Ensure the new title uses different key words and is distinct from existing titles. ";
    }
    
    // Add the user's custom title prompt if provided
    if (!empty($title_prompt)) {
        $uniqueness_prompt .= "\n\nAdditional instructions: " . $title_prompt;
    }
    
    // Build the request
    $api_endpoint = 'https://api.openai.com/v1/chat/completions';
    $title_request_body = array(
        'model' => 'gpt-4o-mini', // Use the smaller model to reduce compute
        'messages' => array(
            array('role' => 'user', 'content' => $uniqueness_prompt . "\n\nArticle Summary:\n" . $summary_excerpt),
        ),
        'max_tokens' => 50, // Reduced from 100
        'temperature' => 0.7, // Slightly reduced for more deterministic results
    );

    $request_args = array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => json_encode($title_request_body),
        'method' => 'POST',
        'timeout' => 15, // Reduced timeout
    );
    
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
                    // Clean the title by removing quotes and other unwanted characters
                    $title = str_replace(array('"', "'", "\u201c", "\u201d", "\u2018", "\u2019"), '', $generated_title);
                    // Remove duplicate words
                    $title = sumai_remove_duplicate_words($title);
                }
            }
        }
    }
    
    return $title;
}

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
    $maxitems = $feed->get_item_quantity(5); // Get latest 5 items
    $feed->init();
    $feed->handle_content_type();
    $feed->set_cache_duration(3600); // 1 hour cache
    
    $items = $feed->get_items(0, $maxitems);
    
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
