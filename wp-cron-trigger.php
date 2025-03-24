<?php
/**
 * WordPress Cron Job Trigger for Sumai Plugin
 * ------------------------------------------
 * This script provides an external endpoint to trigger WordPress cron jobs
 * specifically for the Sumai plugin. It's designed to be called by an external
 * cron service (like system cron or a web-based cron service) to ensure
 * reliable execution of scheduled tasks.
 * 
 * Security Features:
 * - Token-based authentication
 * - WordPress core sanitization
 * - Secure token comparison
 * 
 * Why External Trigger:
 * WordPress's default pseudo-cron system relies on site visits to trigger
 * scheduled tasks. This can be unreliable for sites with low traffic or
 * when precise timing is needed. This script provides a dedicated endpoint
 * that can be triggered by a real cron job for more reliable execution.
 * 
 * Usage:
 * 1. Set up a cron job to call this script with the token parameter
 * 2. Example: wget https://yoursite.com/wp-content/plugins/sumai/wp-cron-trigger.php?token=YOUR_TOKEN
 * 
 * @see sumai_activate() in sumai.php for token generation
 */

// Load WordPress core - required for WP functions and database access
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Verify the security token
// The token is generated during plugin activation and stored in WordPress options
$stored_token = get_option('sumai_cron_token', '');
if (!empty($_REQUEST['token']) && !empty($stored_token) && hash_equals($stored_token, $_REQUEST['token'])) {
    // Set DOING_CRON constant to prevent concurrent cron runs
    if ( !defined('DOING_CRON') ) {
        define('DOING_CRON', true);
    }

    // Track execution time for monitoring and optimization
    $cron_start = microtime(true);

    // Trigger WordPress cron system
    // This executes all due scheduled tasks, not just Sumai's
    do_action('wp_cron');

    // Calculate and log execution time for performance monitoring
    $cron_end = microtime(true);
    $execution_time = ($cron_end - $cron_start);

    // Log execution time for monitoring
    // This helps identify performance issues or hanging tasks
    error_log(sprintf(
        '[WordPress Cron] Execution completed in %.4f seconds',
        $execution_time
    ));
}
// No else block to avoid leaking information about invalid tokens
