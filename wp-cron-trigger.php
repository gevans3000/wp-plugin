<?php
/**
 * WordPress Cron Job Trigger
 * This script triggers WordPress cron jobs when called
 */

// Load WordPress core
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Verify cron token
$stored_token = get_option('sumai_cron_token', wp_generate_password(32));
if (!empty($_REQUEST['token']) && hash_equals($stored_token, sanitize_key($_REQUEST['token']))) {
    // Trigger WP-Cron
    if ( !defined('DOING_CRON') ) {
        define('DOING_CRON', true);
    }

    // Get the time when this script started
    $cron_start = microtime(true);

    // Trigger WordPress cron
    do_action('wp_cron');

    // Calculate execution time
    $cron_end = microtime(true);
    $execution_time = ($cron_end - $cron_start);

    // Log execution (optional)
    error_log(sprintf(
        '[WordPress Cron] Execution completed in %.4f seconds',
        $execution_time
    ));
}
