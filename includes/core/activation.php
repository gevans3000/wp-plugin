<?php
/**
 * Activation and deactivation functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 * Sets up default options, schedules events, and initializes logs.
 */
function sumai_activate() {
    // Track activation status for admin notice
    $activation_errors = [];
    
    // Default settings
    $defaults = [
        'feed_urls' => '', 
        'context_prompt' => "Summarize the key points concisely.",
        'title_prompt' => "Generate a compelling and unique title.", 
        'api_key' => '', 
        'draft_mode' => 0,
        'schedule_time' => '03:00', 
        'post_signature' => '',
        'retention_period' => 30
    ];
    
    // Add options to database
    try {
        add_option(SUMAI_SETTINGS_OPTION, $defaults, '', 'no');
        sumai_log_event('Settings initialized successfully');
    } catch (Exception $e) {
        $error_message = 'Failed to initialize settings: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
        
        // Fallback: Try to update existing option if it exists
        try {
            if (get_option(SUMAI_SETTINGS_OPTION) !== false) {
                update_option(SUMAI_SETTINGS_OPTION, array_merge(get_option(SUMAI_SETTINGS_OPTION, []), $defaults));
                sumai_log_event('Settings updated as fallback');
            }
        } catch (Exception $e) {
            sumai_log_event('Settings fallback failed: ' . $e->getMessage(), true);
        }
    }
    
    // Create log directory
    try {
        sumai_ensure_log_dir();
        sumai_log_event('Log directory created successfully');
    } catch (Exception $e) {
        $error_message = 'Failed to create log directory: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Schedule daily event for summary generation
    try {
        sumai_schedule_daily_event();
        sumai_log_event('Daily summary event scheduled successfully');
    } catch (Exception $e) {
        $error_message = 'Failed to schedule daily event: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Schedule weekly token rotation
    try {
        if (!wp_next_scheduled(SUMAI_ROTATE_TOKEN_HOOK)) {
            $scheduled = wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK);
            if ($scheduled === false) {
                throw new Exception('wp_schedule_event returned false');
            }
            sumai_log_event('Weekly token rotation scheduled successfully');
        }
    } catch (Exception $e) {
        $error_message = 'Failed to schedule token rotation: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Generate initial cron token if needed
    try {
        if (!get_option(SUMAI_CRON_TOKEN_OPTION)) {
            sumai_rotate_cron_token();
            sumai_log_event('Initial cron token generated successfully');
        }
    } catch (Exception $e) {
        $error_message = 'Failed to generate cron token: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Schedule daily log pruning
    try {
        if (!wp_next_scheduled(SUMAI_PRUNE_LOGS_HOOK)) {
            $scheduled = wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK);
            if ($scheduled === false) {
                throw new Exception('wp_schedule_event returned false');
            }
            sumai_log_event('Daily log pruning scheduled successfully');
        }
    } catch (Exception $e) {
        $error_message = 'Failed to schedule log pruning: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Initialize processed articles storage if needed
    try {
        if (get_option(SUMAI_PROCESSED_GUIDS_OPTION) === false) {
            add_option(SUMAI_PROCESSED_GUIDS_OPTION, [], '', 'no');
            sumai_log_event('Processed GUIDs storage initialized');
        }
        
        if (get_option(SUMAI_PROCESSED_HASHES_OPTION) === false) {
            add_option(SUMAI_PROCESSED_HASHES_OPTION, [], '', 'no');
            sumai_log_event('Processed hashes storage initialized');
        }
    } catch (Exception $e) {
        $error_message = 'Failed to initialize processed articles storage: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $activation_errors[] = $error_message;
    }
    
    // Store activation errors for admin notice if any occurred
    if (!empty($activation_errors)) {
        update_option(SUMAI_ACTIVATION_ERRORS_OPTION, $activation_errors);
    }
    
    // Log activation with version
    $plugin_data = get_file_data(SUMAI_PLUGIN_FILE, ['Version' => 'Version']);
    $version = isset($plugin_data['Version']) ? $plugin_data['Version'] : 'unknown';
    
    if (empty($activation_errors)) {
        sumai_log_event('Plugin activated successfully. V' . $version);
    } else {
        sumai_log_event('Plugin activated with ' . count($activation_errors) . ' errors. V' . $version, true);
    }
}

/**
 * Runs on plugin deactivation.
 * Cleans up scheduled events.
 */
function sumai_deactivate() {
    $deactivation_errors = [];
    
    // Clear all scheduled hooks
    try {
        wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
        sumai_log_event('Daily summary cron hook cleared');
    } catch (Exception $e) {
        $error_message = 'Failed to clear daily summary hook: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $deactivation_errors[] = $error_message;
    }
    
    try {
        wp_clear_scheduled_hook(SUMAI_ROTATE_TOKEN_HOOK);
        sumai_log_event('Token rotation hook cleared');
    } catch (Exception $e) {
        $error_message = 'Failed to clear token rotation hook: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $deactivation_errors[] = $error_message;
    }
    
    try {
        wp_clear_scheduled_hook(SUMAI_PRUNE_LOGS_HOOK);
        sumai_log_event('Log pruning hook cleared');
    } catch (Exception $e) {
        $error_message = 'Failed to clear log pruning hook: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        $deactivation_errors[] = $error_message;
    }
    
    // Log deactivation status
    if (empty($deactivation_errors)) {
        sumai_log_event('Plugin deactivated successfully.');
    } else {
        sumai_log_event('Plugin deactivated with ' . count($deactivation_errors) . ' errors.', true);
    }
}

/**
 * Displays admin notices for activation errors.
 * Called on admin_notices hook.
 */
function sumai_activation_admin_notices() {
    // Check if we have activation errors to display
    $activation_errors = get_option(SUMAI_ACTIVATION_ERRORS_OPTION);
    
    if (!empty($activation_errors) && is_array($activation_errors)) {
        // Display error notice
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>Sumai Plugin Activation Issues</strong></p>';
        echo '<p>The plugin was activated but encountered the following issues:</p>';
        echo '<ul style="margin-left: 1.5em; list-style-type: disc;">';
        
        foreach ($activation_errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        
        echo '</ul>';
        echo '<p>The plugin may not function correctly. Please check the logs for more details.</p>';
        echo '</div>';
        
        // Clear the errors after displaying them once
        delete_option(SUMAI_ACTIVATION_ERRORS_OPTION);
    }
}

/**
 * Checks if the plugin's dependencies are met.
 * 
 * @return array Array of dependency check results.
 */
function sumai_check_dependencies() {
    $results = [
        'success' => true,
        'issues' => []
    ];
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $results['success'] = false;
        $results['issues'][] = 'PHP version 7.4 or higher is required. Your server is running PHP ' . PHP_VERSION;
    }
    
    // Check WordPress version
    global $wp_version;
    if (version_compare($wp_version, '5.6', '<')) {
        $results['success'] = false;
        $results['issues'][] = 'WordPress version 5.6 or higher is required. Your site is running WordPress ' . $wp_version;
    }
    
    // Check if we can create/write to log directory
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/sumai-logs';
    
    if (!is_dir($log_dir) && !wp_mkdir_p($log_dir)) {
        $results['success'] = false;
        $results['issues'][] = 'Unable to create log directory at ' . $log_dir;
    } elseif (!is_writable($log_dir)) {
        $results['success'] = false;
        $results['issues'][] = 'Log directory exists but is not writable: ' . $log_dir;
    }
    
    // Check for Action Scheduler availability
    $action_scheduler_available = false;
    
    // Check if Action Scheduler functions are already available
    if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
        $action_scheduler_available = true;
    } else {
        // Check for WooCommerce's Action Scheduler
        if (file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php')) {
            $action_scheduler_available = true;
        }
        // Check for standalone Action Scheduler plugin
        elseif (file_exists(WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php')) {
            $action_scheduler_available = true;
        }
    }
    
    if (!$action_scheduler_available) {
        $results['success'] = false;
        $results['issues'][] = 'Action Scheduler is required for background processing. Please install the Action Scheduler plugin or WooCommerce.';
    }
    
    // Get detailed Action Scheduler information if it's available
    if ($action_scheduler_available && function_exists('sumai_get_action_scheduler_info')) {
        $as_info = sumai_get_action_scheduler_info();
        $results['action_scheduler'] = $as_info;
        
        // Log the Action Scheduler information
        if (function_exists('sumai_log_event')) {
            sumai_log_event('Action Scheduler detected: Source=' . $as_info['source'] . ', Version=' . $as_info['version']);
        }
    }
    
    // Store dependency check results for later use
    update_option(SUMAI_DEPENDENCY_CHECK_OPTION, $results);
    
    return $results;
}

/**
 * Display admin notices for dependency issues.
 */
function sumai_dependency_admin_notices() {
    // Check if we have dependency issues
    $dependency_check = get_option(SUMAI_DEPENDENCY_CHECK_OPTION, ['success' => true, 'issues' => []]);
    
    if (!$dependency_check['success'] && !empty($dependency_check['issues'])) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>Sumai Plugin - Dependency Issues:</strong></p>';
        echo '<ul>';
        
        foreach ($dependency_check['issues'] as $issue) {
            echo '<li>' . esc_html($issue) . '</li>';
        }
        
        echo '</ul>';
        
        // Add specific guidance based on the issues
        if (isset($dependency_check['action_scheduler']) && !$dependency_check['action_scheduler']['available']) {
            echo '<p><strong>Action Scheduler Installation Guide:</strong></p>';
            echo '<ol>';
            echo '<li>Install and activate the <a href="https://wordpress.org/plugins/action-scheduler/" target="_blank">Action Scheduler plugin</a>, or</li>';
            echo '<li>Install and activate <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a> which includes Action Scheduler</li>';
            echo '</ol>';
        }
        
        echo '<p>The Sumai plugin will operate with limited functionality until these issues are resolved.</p>';
        echo '</div>';
    }
    
    // Display warnings for non-critical issues
    $warnings = [];
    
    // Check if Action Scheduler is available but not optimal
    if (isset($dependency_check['action_scheduler']) && 
        $dependency_check['action_scheduler']['available'] && 
        $dependency_check['action_scheduler']['source'] === 'unknown_integrated') {
        $warnings[] = 'Action Scheduler is available through an unknown integration. For optimal performance, consider installing the standalone Action Scheduler plugin.';
    }
    
    // Display warnings if any
    if (!empty($warnings)) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Sumai Plugin - Recommendations:</strong></p>';
        echo '<ul>';
        
        foreach ($warnings as $warning) {
            echo '<li>' . esc_html($warning) . '</li>';
        }
        
        echo '</ul>';
        echo '</div>';
    }
}

/**
 * Get a summary of dependency status for display in the admin interface.
 * 
 * @return array Dependency status summary
 */
function sumai_get_dependency_status_summary() {
    $dependency_check = get_option(SUMAI_DEPENDENCY_CHECK_OPTION, ['success' => true, 'issues' => []]);
    
    $summary = [
        'status' => $dependency_check['success'] ? 'good' : 'error',
        'issues_count' => count($dependency_check['issues']),
        'issues' => $dependency_check['issues'],
        'action_scheduler' => isset($dependency_check['action_scheduler']) ? $dependency_check['action_scheduler'] : null,
    ];
    
    return $summary;
}