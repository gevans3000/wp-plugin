<?php
/**
 * Admin error reporting interface for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the error reporting admin page.
 */
function sumai_register_error_reporting_page() {
    add_submenu_page(
        'sumai-settings',
        'Error Reports',
        'Error Reports',
        'manage_options',
        'sumai-error-reports',
        'sumai_render_error_reporting_page'
    );
}
add_action('admin_menu', 'sumai_register_error_reporting_page', 20);

/**
 * Renders the error reporting admin page.
 */
function sumai_render_error_reporting_page() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    // Handle clear errors action
    if (isset($_POST['sumai_clear_errors']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'sumai_clear_errors')) {
        sumai_clear_error_history();
        echo '<div class="notice notice-success"><p>Error history has been cleared.</p></div>';
    }
    
    // Get error history
    $errors = sumai_get_error_history();
    
    // Get log file info
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . SUMAI_LOG_DIR_NAME;
    $log_file = trailingslashit($log_dir) . SUMAI_LOG_FILE_NAME;
    $log_exists = file_exists($log_file);
    $log_size = $log_exists ? size_format(filesize($log_file)) : '0 KB';
    
    // Get recent log entries
    $recent_logs = $log_exists ? sumai_read_log_tail($log_file, 20) : [];
    
    ?>
    <div class="wrap">
        <h1>Sumai Error Reports</h1>
        
        <div class="notice notice-info">
            <p>This page shows recent errors and logs from the Sumai plugin. Use this information to troubleshoot issues.</p>
        </div>
        
        <div class="card">
            <h2>Error Notifications</h2>
            <form method="post" action="options.php">
                <?php settings_fields('sumai_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Email Notifications</th>
                        <td>
                            <?php 
                            $settings = sumai_get_cached_settings();
                            $checked = !empty($settings['error_notifications']) && $settings['error_notifications'] === 'on';
                            ?>
                            <label>
                                <input type="checkbox" name="sumai_settings[error_notifications]" <?php checked($checked); ?> value="on">
                                Send email notifications for critical errors
                            </label>
                            <p class="description">Notifications will be sent to <?php echo esc_html(get_option('admin_email')); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Notification Settings'); ?>
            </form>
        </div>
        
        <div class="card">
            <h2>Recent Errors</h2>
            <?php if (empty($errors)) : ?>
                <p>No errors have been recorded.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error) : ?>
                            <tr>
                                <td><?php echo esc_html($error['time']); ?></td>
                                <td><span class="error-type error-type-<?php echo esc_attr($error['type']); ?>"><?php echo esc_html(ucfirst($error['type'])); ?></span></td>
                                <td><?php echo esc_html($error['message']); ?></td>
                                <td>
                                    <?php if (!empty($error['context'])) : ?>
                                        <button type="button" class="button button-small toggle-context">Show Details</button>
                                        <div class="error-context" style="display:none;">
                                            <pre><?php echo esc_html(json_encode($error['context'], JSON_PRETTY_PRINT)); ?></pre>
                                        </div>
                                    <?php else : ?>
                                        <em>No additional details</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('sumai_clear_errors'); ?>
                    <input type="submit" name="sumai_clear_errors" class="button button-secondary" value="Clear Error History" onclick="return confirm('Are you sure you want to clear the error history?');">
                </form>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>Log File</h2>
            <p>
                <strong>Log File:</strong> <?php echo esc_html($log_file); ?><br>
                <strong>Size:</strong> <?php echo esc_html($log_size); ?>
            </p>
            
            <?php if (!empty($recent_logs)) : ?>
                <h3>Recent Log Entries</h3>
                <div class="log-entries" style="max-height: 300px; overflow-y: auto; background: #f5f5f5; padding: 10px; font-family: monospace;">
                    <?php foreach ($recent_logs as $log_entry) : ?>
                        <div class="log-entry <?php echo strpos($log_entry, '[ERROR]') !== false ? 'log-error' : ''; ?>">
                            <?php echo esc_html($log_entry); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p>No log entries found or log file does not exist.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            margin-top: 20px;
            padding: 15px;
        }
        .error-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .error-type-api {
            background: #ffecec;
            color: #d63638;
        }
        .error-type-system {
            background: #fff8e5;
            color: #996800;
        }
        .error-type-data {
            background: #e5f5fa;
            color: #007cba;
        }
        .error-type-security {
            background: #fcf0f1;
            color: #8c1c1c;
        }
        .log-entry {
            margin-bottom: 5px;
            line-height: 1.4;
        }
        .log-error {
            color: #d63638;
        }
        .error-context pre {
            background: #f5f5f5;
            padding: 10px;
            overflow: auto;
            max-height: 150px;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('.toggle-context').on('click', function() {
            var $context = $(this).next('.error-context');
            $context.toggle();
            $(this).text($context.is(':visible') ? 'Hide Details' : 'Show Details');
        });
    });
    </script>
    <?php
}

/**
 * Adds error reporting settings to the Sumai settings page.
 *
 * @param array $settings The current settings array.
 * @return array The modified settings array.
 */
function sumai_add_error_reporting_settings($settings) {
    // Add error notification setting if it doesn't exist
    if (!isset($settings['error_notifications'])) {
        $settings['error_notifications'] = 'on'; // Enable by default
    }
    
    return $settings;
}
add_filter('sumai_default_settings', 'sumai_add_error_reporting_settings');
