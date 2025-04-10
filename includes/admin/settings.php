<?php
/**
 * Admin settings page functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin scripts and styles.
 * 
 * @param string $hook The current admin page.
 */
function sumai_admin_enqueue_scripts($hook) {
    if ('settings_page_sumai-settings' !== $hook) {
        return;
    }
    
    // Enqueue admin styles
    wp_enqueue_style(
        'sumai-admin',
        plugin_dir_url(SUMAI_PLUGIN_FILE) . 'assets/css/admin.css',
        [],
        SUMAI_VERSION
    );
    
    // Enqueue admin script
    wp_enqueue_script(
        'sumai-admin',
        plugin_dir_url(SUMAI_PLUGIN_FILE) . 'assets/js/admin.js',
        array('jquery'),
        SUMAI_VERSION,
        true
    );
    
    // Localize script with AJAX data
    wp_localize_script(
        'sumai-admin',
        'sumaiAdmin',
        array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sumai_nonce'),
            'messages' => array(
                'processing' => __('Processing your request...', 'sumai'),
                'success' => __('Operation completed successfully.', 'sumai'),
                'error' => __('An error occurred. Please try again.', 'sumai'),
                'confirm_generate' => __('This will generate a new summary post from your RSS feeds. Continue?', 'sumai'),
                'confirm_clear_all' => __('Are you sure you want to clear all processed articles? This will allow them to be processed again.', 'sumai'),
                'no_results' => __('No results found.', 'sumai')
            )
        )
    );
}
add_action('admin_enqueue_scripts', 'sumai_admin_enqueue_scripts');

/**
 * Adds the admin menu item for Sumai settings.
 */
function sumai_add_admin_menu() {
    add_options_page(
        'Sumai Settings',
        'Sumai',
        'manage_options',
        'sumai-settings',
        'sumai_render_settings_page'
    );
}
add_action('admin_menu', 'sumai_add_admin_menu');

/**
 * Registers the plugin settings.
 */
function sumai_register_settings() {
    register_setting(
        'sumai_options_group',
        SUMAI_SETTINGS_OPTION,
        'sumai_sanitize_settings'
    );
}
add_action('admin_init', 'sumai_register_settings');

/**
 * Sanitizes the settings input.
 * 
 * @param array $input The raw input from the settings form.
 * @return array The sanitized settings.
 */
function sumai_sanitize_settings($input): array {
    $s = [];
    
    // Feed URLs (sanitize each URL on its own line)
    if (isset($input['feed_urls'])) {
        $urls = explode("\n", $input['feed_urls']);
        $clean_urls = [];
        
        foreach ($urls as $url) {
            $url = trim($url);
            if (!empty($url)) {
                $clean_urls[] = esc_url_raw($url);
            }
        }
        
        $s['feed_urls'] = implode("\n", $clean_urls);
    } else {
        $s['feed_urls'] = '';
    }
    
    // Context prompt
    $s['context_prompt'] = isset($input['context_prompt']) 
        ? wp_kses_post(trim($input['context_prompt'])) 
        : 'Summarize the key points concisely.';
    
    // Title prompt
    $s['title_prompt'] = isset($input['title_prompt']) 
        ? wp_kses_post(trim($input['title_prompt'])) 
        : 'Generate a compelling and unique title.';
    
    // Retention period (days)
    if (isset($input['retention_period'])) {
        $retention_period = intval($input['retention_period']);
        // Ensure retention period is between 1 and 365 days
        $s['retention_period'] = max(1, min(365, $retention_period));
    } else {
        $s['retention_period'] = 30; // Default to 30 days
    }
    
    // API Key handling
    if (isset($input['api_key']) && !empty($input['api_key'])) {
        $api_key = trim($input['api_key']);
        
        // Skip if constant is defined
        if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) {
            $s['api_key'] = ''; // Don't store if using constant
        } else {
            // Validate API key format
            if (strpos($api_key, 'sk-') === 0) {
                // Encrypt API key
                $encrypted = sumai_encrypt_api_key($api_key);
                if ($encrypted !== false) {
                    $s['api_key'] = $encrypted;
                } else {
                    add_settings_error(
                        'sumai_options',
                        'encrypt_failed',
                        'Failed to encrypt API key. Check if your WordPress is properly configured.',
                        'error'
                    );
                    $old_options = get_option(SUMAI_SETTINGS_OPTION);
                    $s['api_key'] = $old_options['api_key'] ?? '';
                }
            } else {
                add_settings_error(
                    'sumai_options',
                    'invalid_api_key',
                    'Invalid API key format. It should start with "sk-".',
                    'error'
                );
                $old_options = get_option(SUMAI_SETTINGS_OPTION);
                $s['api_key'] = $old_options['api_key'] ?? '';
            }
        }
    } else {
        // Keep existing API key if empty
        $old_options = get_option(SUMAI_SETTINGS_OPTION);
        $s['api_key'] = $old_options['api_key'] ?? '';
    }
    
    // Draft mode (boolean)
    $s['draft_mode'] = isset($input['draft_mode']) ? 1 : 0;
    
    // Schedule time
    if (isset($input['schedule_time']) && preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $input['schedule_time'])) {
        $s['schedule_time'] = $input['schedule_time'];
        
        // Update cron schedule if time changed
        $old_options = get_option(SUMAI_SETTINGS_OPTION);
        if (!isset($old_options['schedule_time']) || $old_options['schedule_time'] !== $s['schedule_time']) {
            sumai_schedule_daily_event();
        }
    } else {
        $s['schedule_time'] = '03:00'; // Default to 3 AM
    }
    
    // Post signature
    $s['post_signature'] = isset($input['post_signature']) 
        ? wp_kses_post(trim($input['post_signature'])) 
        : '';
    
    // Error notifications (boolean checkbox)
    $s['error_notifications'] = isset($input['error_notifications']) ? 'on' : 'off';
    
    return $s;
}

/**
 * Renders the settings page HTML.
 */
function sumai_render_settings_page() {
    // Get current settings using the cache manager
    $opts = sumai_get_cached_settings();
    $opts = array_merge(
        [
            'feed_urls' => '',
            'context_prompt' => '',
            'title_prompt' => '',
            'draft_mode' => 0,
            'schedule_time' => '03:00',
            'post_signature' => '',
            'retention_period' => 30,
            'error_notifications' => 'on'
        ],
        $opts
    );
    ?>
    <div class="wrap">
        <h1>Sumai Settings</h1>
        
        <div id="sumai-tabs">
            <h2 class="nav-tab-wrapper">
                <a href="#tab-settings" class="nav-tab nav-tab-active">Settings</a>
                <a href="#tab-manual" class="nav-tab">Manual Generation</a>
                <a href="#tab-test" class="nav-tab">Test Feeds</a>
                <a href="#tab-processed" class="nav-tab">Processed Articles</a>
                <a href="#tab-debug" class="nav-tab">Debug Info</a>
                <a href="#tab-docs" class="nav-tab">Documentation</a>
                <a href="#tab-cache" class="nav-tab">Cache</a>
            </h2>
            
            <div id="tab-settings" class="tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields('sumai_options_group'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="sumai-feed-urls">Feed URLs</label>
                            </th>
                            <td>
                                <textarea 
                                    id="sumai-feed-urls" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[feed_urls]" 
                                    rows="5" 
                                    class="large-text"
                                    placeholder="https://example.com/feed/"
                                ><?php echo esc_textarea($opts['feed_urls']); ?></textarea>
                                <p class="description">Enter one feed URL per line. These will be processed during scheduled or manual generation.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-context-prompt">Context Prompt</label>
                            </th>
                            <td>
                                <textarea 
                                    id="sumai-context-prompt" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[context_prompt]" 
                                    rows="3" 
                                    class="large-text"
                                    placeholder="Summarize the key points concisely."
                                ><?php echo esc_textarea($opts['context_prompt']); ?></textarea>
                                <p class="description">This prompt will be sent to the AI to guide the summarization process.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-title-prompt">Title Prompt</label>
                            </th>
                            <td>
                                <textarea 
                                    id="sumai-title-prompt" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[title_prompt]" 
                                    rows="2" 
                                    class="large-text"
                                    placeholder="Generate a compelling and unique title."
                                ><?php echo esc_textarea($opts['title_prompt']); ?></textarea>
                                <p class="description">This prompt will be sent to the AI to generate the post title.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-api-key">OpenAI API Key</label>
                            </th>
                            <td>
                                <div class="sumai-api-key-container" style="display: flex; align-items: center;">
                                    <input 
                                        type="password" 
                                        id="sumai-api-key" 
                                        name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[api_key]" 
                                        class="regular-text"
                                        placeholder="<?php echo defined('SUMAI_OPENAI_API_KEY') ? 'Using constant from wp-config.php' : 'sk-...'; ?>"
                                        value="<?php echo !empty($opts['api_key']) ? '************' : ''; ?>"
                                        <?php echo defined('SUMAI_OPENAI_API_KEY') ? 'disabled' : ''; ?>
                                    />
                                    <button type="button" id="sumai-toggle-api-key" class="button button-secondary" style="margin-left: 5px;">Show</button>
                                </div>
                                <p class="description">
                                    <?php if (defined('SUMAI_OPENAI_API_KEY')): ?>
                                        API key is defined in wp-config.php and cannot be modified here.
                                    <?php else: ?>
                                        Your OpenAI API key. This will be encrypted before storage.
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-schedule-time">Schedule Time</label>
                            </th>
                            <td>
                                <input 
                                    type="time" 
                                    id="sumai-schedule-time" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[schedule_time]" 
                                    value="<?php echo esc_attr($opts['schedule_time']); ?>"
                                />
                                <p class="description">Time of day to run the scheduled generation (24-hour format, server time).</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-retention-period">Retention Period (days)</label>
                            </th>
                            <td>
                                <input 
                                    type="number" 
                                    id="sumai-retention-period" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[retention_period]" 
                                    value="<?php echo esc_attr($opts['retention_period']); ?>"
                                    min="1"
                                    max="365"
                                    step="1"
                                />
                                <p class="description">Number of days to keep processed articles in memory (1-365).</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-draft-mode">Draft Mode</label>
                            </th>
                            <td>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        id="sumai-draft-mode" 
                                        name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[draft_mode]" 
                                        value="1"
                                        <?php checked(1, $opts['draft_mode']); ?>
                                    />
                                    Create posts as drafts instead of publishing immediately
                                </label>
                                <p class="description">When enabled, generated posts will be saved as drafts for review.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-post-signature">Post Signature</label>
                            </th>
                            <td>
                                <textarea 
                                    id="sumai-post-signature" 
                                    name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[post_signature]" 
                                    rows="2" 
                                    class="large-text"
                                    placeholder="Optional signature to append to generated posts."
                                ><?php echo esc_textarea($opts['post_signature']); ?></textarea>
                                <p class="description">Optional text to append to the end of each generated post.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="sumai-error-notifications">Error Notifications</label>
                            </th>
                            <td>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        id="sumai-error-notifications" 
                                        name="<?php echo esc_attr(SUMAI_SETTINGS_OPTION); ?>[error_notifications]" 
                                        value="on"
                                        <?php checked('on', $opts['error_notifications']); ?>
                                    />
                                    Send email notifications for errors
                                </label>
                                <p class="description">When enabled, you'll receive email notifications when errors occur during processing.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>
            
            <div id="tab-manual" class="tab-content" style="display: none;">
                <div class="card">
                    <h2>Manual Content Generation</h2>
                    <p>Generate a summary post from your configured RSS feeds right now.</p>
                    
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" id="sumai-draft-mode" <?php checked(1, $opts['draft_mode']); ?> />
                            Create as draft
                        </label>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" id="sumai-respect-processed" checked />
                            Skip already processed articles
                        </label>
                        <p class="description">When unchecked, all articles will be processed again regardless of whether they've been processed before.</p>
                    </div>
                    
                    <p>
                        <button id="sumai-generate-btn" class="button button-primary">Generate Now</button>
                    </p>
                </div>
            </div>
            
            <div id="tab-test" class="tab-content" style="display: none;">
                <div class="card">
                    <h2>Test Feed URLs</h2>
                    <p>Test the feed URLs configured in your settings to ensure they are valid and accessible.</p>
                    
                    <p>
                        <button id="test-feed-btn" class="button button-primary">Test</button>
                    </p>
                    
                    <div id="feed-test-res" style="margin-top: 15px; display: none;"></div>
                </div>
            </div>
            
            <div id="tab-processed" class="tab-content" style="display: none;">
                <div class="card">
                    <h2>Processed Articles</h2>
                    <p>View and manage articles that have been processed by the plugin.</p>
                    
                    <div class="sumai-search-box" style="margin-bottom: 15px;">
                        <form id="sumai-search-processed" style="display: flex; gap: 10px; align-items: center;">
                            <input type="text" id="sumai-search-term" placeholder="Search articles..." class="regular-text" />
                            <button type="submit" class="button button-secondary">Search</button>
                            <button type="button" id="sumai-clear-search" class="button button-secondary">Clear</button>
                        </form>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <button id="sumai-clear-all" class="button button-secondary">Clear All Processed Articles</button>
                    </div>
                    
                    <div id="processed-articles">
                        <div class="sumai-status pending">
                            <p>Loading articles...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="tab-debug" class="tab-content" style="display: none;">
                <?php sumai_render_debug_info(); ?>
            </div>
            
            <div id="tab-docs" class="tab-content" style="display: none;">
                <?php sumai_render_documentation_status(); ?>
            </div>
            
            <div id="tab-cache" class="tab-content" style="display: none;">
                <?php sumai_render_cache_management(); ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders the debug information.
 */
function sumai_render_debug_info() {
    // Get debug info
    $debug_info = sumai_get_debug_info();
    
    // Check if log file exists
    $log_exists = isset($debug_info['log_file']) && !empty($debug_info['log_file']) && file_exists($debug_info['log_file']);
    
    // Display debug info sections
    ?>
    <table class="widefat" style="margin-bottom:20px;">
        <thead>
            <tr>
                <th>System Information</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Plugin Version</td>
                <td><?php echo esc_html($debug_info['version']); ?></td>
            </tr>
            <tr>
                <td>WordPress Version</td>
                <td><?php echo esc_html($debug_info['wp_version']); ?></td>
            </tr>
            <tr>
                <td>PHP Version</td>
                <td><?php echo esc_html($debug_info['php_version']); ?></td>
            </tr>
            <tr>
                <td>OpenSSL Version</td>
                <td><?php echo $debug_info['openssl'] ? esc_html($debug_info['openssl']) : '<span style="color:red;">Not available</span>'; ?></td>
            </tr>
            <tr>
                <td>API Key Status</td>
                <td><?php echo esc_html($debug_info['settings']['api_key']); ?></td>
            </tr>
            <tr>
                <td>Action Scheduler</td>
                <td><?php echo $debug_info['action_scheduler'] ? '<span style="color:green;">Available</span>' : '<span style="color:orange;">Not available</span>'; ?></td>
            </tr>
            <tr>
                <td>Next Scheduled Run</td>
                <td><?php echo $debug_info['next_run'] ? esc_html($debug_info['next_run']) : 'Not scheduled'; ?></td>
            </tr>
            <tr>
                <td>Log File</td>
                <td><?php echo $log_exists ? esc_html($debug_info['log_file']) : '<span style="color:red;">Not found</span>'; ?></td>
            </tr>
        </tbody>
    </table>
    
    <?php if ($log_exists): ?>
        <h3>Recent Log Entries</h3>
        <div style="max-height:400px; overflow-y:auto; border:1px solid #ddd; padding:10px; background:#f8f8f8; margin-bottom:20px;">
            <pre style="margin:0; white-space:pre-wrap;"><?php echo esc_html(implode("\n", $debug_info['log_entries'])); ?></pre>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * Gets diagnostic information for the plugin.
 * 
 * @return array Debug information.
 */
function sumai_get_debug_info() {
    // Base info
    $debug_info = [];
    
    // Plugin version
    $debug_info['version'] = SUMAI_VERSION;
    
    // WordPress version
    $debug_info['wp_version'] = get_bloginfo('version');
    
    // PHP version
    $debug_info['php_version'] = phpversion();
    
    // OpenSSL version
    if (function_exists('openssl_get_cipher_methods')) {
        $debug_info['openssl'] = OPENSSL_VERSION_TEXT ?? phpversion('openssl');
    } else {
        $debug_info['openssl'] = false;
    }
    
    // Settings (with sensitive data masked)
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $debug_info['settings'] = $options;
    $debug_info['settings']['api_key'] = (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) 
        ? '*** Constant ***' 
        : (!empty($options['api_key']) ? '*** DB Set ***' : '*** Not Set ***');
    
    // Action Scheduler availability
    $debug_info['action_scheduler'] = sumai_check_action_scheduler();
    
    // Next scheduled run
    $next_run = wp_next_scheduled(SUMAI_CRON_HOOK);
    $debug_info['next_run'] = $next_run ? date('Y-m-d H:i:s', $next_run) : false;
    
    // Log file information
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . SUMAI_LOG_DIR_NAME;
    $log_file = trailingslashit($log_dir) . SUMAI_LOG_FILE_NAME;
    $debug_info['log_file'] = $log_file;
    
    // Get recent log entries
    $debug_info['log_entries'] = sumai_read_log_tail($log_file, 100);
    
    return $debug_info;
}

/**
 * Renders the documentation status information.
 */
function sumai_render_documentation_status() {
    // Check if documentation validation function exists
    if (!function_exists('sumai_validate_documentation')) {
        echo '<div class="notice notice-error"><p>Documentation management system is not available.</p></div>';
        return;
    }
    
    // Run validation
    $validation = sumai_validate_documentation();
    
    // Display validation results
    if ($validation['valid']) {
        echo '<div class="notice notice-success inline"><p>All documentation files are valid and up-to-date.</p></div>';
    } else {
        echo '<div class="notice notice-warning inline"><p>Documentation issues found:</p><ul>';
        foreach ($validation['issues'] as $issue) {
            echo '<li>' . esc_html($issue) . '</li>';
        }
        echo '</ul></div>';
    }
    
    // Display last updated timestamps
    echo '<h4>Last Updated Timestamps</h4>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>File</th><th>Last Updated</th></tr></thead>';
    echo '<tbody>';
    
    $files = ['README.md', 'PLANNING.md', 'TASKS.md', '.windsurfrules'];
    foreach ($files as $file) {
        $file_path = SUMAI_PLUGIN_DIR . $file;
        $timestamp = 'Unknown';
        
        if (file_exists($file_path)) {
            $content = file_get_contents($file_path);
            if ($content !== false) {
                // Try to extract timestamp using regex
                if (preg_match('/Last Update(?:d)?:?\s*([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/', $content, $matches)) {
                    $timestamp = $matches[1];
                } else {
                    $timestamp = date('Y-m-d\TH:i:s-04:00', filemtime($file_path));
                }
            }
        } else {
            $timestamp = 'File not found';
        }
        
        echo '<tr>';
        echo '<td>' . esc_html($file) . '</td>';
        echo '<td>' . esc_html($timestamp) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
}

/**
 * Processes documentation action form submissions.
 */
function sumai_process_documentation_actions() {
    // Check if we're processing a documentation action
    if (!isset($_POST['sumai_documentation_nonce']) || 
        !wp_verify_nonce($_POST['sumai_documentation_nonce'], 'sumai_documentation_actions')) {
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle update timestamps action
    if (isset($_POST['sumai_update_timestamps'])) {
        $files_updated = 0;
        $files = [
            'TASKS.md' => [
                'pattern' => '/(- Last Update: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/',
                'replacement' => '$1%s'
            ],
            'README.md' => [
                'pattern' => '/(Last Updated: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/',
                'replacement' => '$1%s'
            ],
            'PLANNING.md' => [
                'pattern' => '/(Last Updated: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/',
                'replacement' => '$1%s'
            ]
        ];
        
        foreach ($files as $file => $config) {
            $file_path = SUMAI_PLUGIN_DIR . $file;
            if (function_exists('sumai_update_documentation_timestamp')) {
                if (sumai_update_documentation_timestamp($file_path, $config['pattern'], $config['replacement'])) {
                    $files_updated++;
                }
            }
        }
        
        if ($files_updated > 0) {
            add_settings_error(
                'sumai_documentation',
                'timestamps_updated',
                "Updated timestamps in {$files_updated} documentation files.",
                'success'
            );
        } else {
            add_settings_error(
                'sumai_documentation',
                'timestamps_failed',
                'Failed to update documentation timestamps.',
                'error'
            );
        }
    }
    
    // Handle validation action
    if (isset($_POST['sumai_validate_docs'])) {
        if (function_exists('sumai_validate_documentation')) {
            $validation = sumai_validate_documentation();
            
            if ($validation['valid']) {
                add_settings_error(
                    'sumai_documentation',
                    'validation_success',
                    'All documentation files are valid and up-to-date.',
                    'success'
                );
            } else {
                $message = 'Documentation validation failed with the following issues:<ul>';
                foreach ($validation['issues'] as $issue) {
                    $message .= '<li>' . esc_html($issue) . '</li>';
                }
                $message .= '</ul>';
                
                add_settings_error(
                    'sumai_documentation',
                    'validation_failed',
                    $message,
                    'error'
                );
            }
        } else {
            add_settings_error(
                'sumai_documentation',
                'validation_unavailable',
                'Documentation validation function is not available.',
                'error'
            );
        }
    }
    
    // Handle sync versions action
    if (isset($_POST['sumai_sync_versions'])) {
        if (function_exists('sumai_update_version_in_documentation')) {
            $result = sumai_update_version_in_documentation();
            
            if ($result) {
                add_settings_error(
                    'sumai_documentation',
                    'versions_synced',
                    'Documentation version numbers have been synchronized with the plugin version (' . SUMAI_VERSION . ').',
                    'success'
                );
            } else {
                add_settings_error(
                    'sumai_documentation',
                    'versions_failed',
                    'Failed to synchronize documentation version numbers.',
                    'error'
                );
            }
        } else {
            add_settings_error(
                'sumai_documentation',
                'versions_unavailable',
                'Documentation version synchronization function is not available.',
                'error'
            );
        }
    }
}
add_action('admin_init', 'sumai_process_documentation_actions');

/**
 * Renders the cache management interface.
 */
function sumai_render_cache_management() {
    // Check if cache was cleared
    $cache_cleared = isset($_GET['cache_cleared']) ? intval($_GET['cache_cleared']) : 0;
    
    // Display success message if cache was cleared
    if ($cache_cleared > 0) {
        echo '<div class="notice notice-success inline"><p>';
        printf(_n('Successfully cleared %d cache item.', 'Successfully cleared %d cache items.', $cache_cleared, 'sumai'), $cache_cleared);
        echo '</p></div>';
    }
    ?>
    <h2>Cache Management</h2>
    
    <p>Sumai uses caching to improve performance and reduce API calls. You can clear the cache if you're experiencing issues or want to force fresh data.</p>
    
    <h3>Current Cache Status</h3>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Cache Type</th>
                <th>Description</th>
                <th>Expiration</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Settings Cache</td>
                <td>Plugin settings cached to reduce database queries</td>
                <td>1 hour</td>
            </tr>
            <tr>
                <td>Feed Cache</td>
                <td>RSS feed data cached to reduce external requests</td>
                <td>30 minutes</td>
            </tr>
            <tr>
                <td>API Response Cache</td>
                <td>OpenAI API responses cached to reduce API costs and improve performance</td>
                <td>7 days</td>
            </tr>
        </tbody>
    </table>
    
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('sumai_clear_cache', 'sumai_cache_nonce'); ?>
        <input type="hidden" name="action" value="sumai_clear_cache">
        
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Clear All Caches">
        </p>
    </form>
    
    <h3>Cache Benefits</h3>
    <ul>
        <li><strong>Reduced API Costs:</strong> By caching API responses, we minimize the number of calls to OpenAI's API.</li>
        <li><strong>Improved Performance:</strong> Cached data loads faster than making fresh requests.</li>
        <li><strong>Lower Server Load:</strong> Reduces database queries and external HTTP requests.</li>
    </ul>
    
    <p><em>Note: Clearing the cache will not affect processed articles or published content.</em></p>
    <?php
}

/**
 * Processes the clear cache action.
 */
function sumai_process_clear_cache() {
    // Check if user has permission
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    // Verify nonce
    if (!isset($_POST['sumai_cache_nonce']) || !wp_verify_nonce($_POST['sumai_cache_nonce'], 'sumai_clear_cache')) {
        wp_die('Security check failed');
    }
    
    // Clear all caches
    $cleared = sumai_clear_all_caches();
    
    // Redirect back to settings page with success message
    wp_redirect(add_query_arg(
        array(
            'page' => 'sumai-settings',
            'tab' => 'cache',
            'cache_cleared' => $cleared
        ),
        admin_url('options-general.php')
    ));
    exit;
}
add_action('admin_post_sumai_clear_cache', 'sumai_process_clear_cache');