<?php
/**
 * Cron scheduling functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Schedules the daily event based on settings.
 */
function sumai_schedule_daily_event() {
    $settings = get_option(SUMAI_SETTINGS_OPTION);
    $schedule_time = isset($settings['schedule_time']) ? $settings['schedule_time'] : '03:00';
    
    // Clear existing schedule
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    
    // Parse hour and minute from schedule time
    list($hour, $minute) = explode(':', $schedule_time);
    $hour = intval($hour);
    $minute = intval($minute);
    
    // Calculate next run time
    $timestamp = strtotime("today $hour:$minute");
    if ($timestamp < time()) {
        $timestamp = strtotime("tomorrow $hour:$minute");
    }
    
    // Schedule new event
    wp_schedule_event($timestamp, 'daily', SUMAI_CRON_HOOK);
    
    sumai_log_event("Daily event scheduled for $hour:$minute");
}

/**
 * Checks for external cron trigger via URL.
 */
function sumai_check_external_trigger() {
    // Only process on direct access
    if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
        return;
    }
    
    // Check if this is a cron trigger request
    if (isset($_GET['sumai_cron']) && isset($_GET['token'])) {
        $stored_token = get_option(SUMAI_CRON_TOKEN_OPTION);
        
        // Validate token
        if (!empty($stored_token) && hash_equals($stored_token, $_GET['token'])) {
            sumai_log_event('External cron trigger activated');
            sumai_generate_daily_summary_event();
            exit('Sumai cron executed.');
        } else {
            sumai_log_event('Invalid external cron token attempted', true);
            wp_die('Invalid token');
        }
    }
}

/**
 * Event handler for the daily summary generation cron job.
 * 
 * @param bool $force_fetch Whether to force fetching articles even if they've been processed before
 * @param bool $draft_mode Whether to save the post as a draft
 * @param string $status_id Optional status ID for tracking progress
 */
function sumai_generate_daily_summary_event(bool $force_fetch = false, bool $draft_mode = false, string $status_id = '') {
    // Add a timestamp to log
    sumai_log_event('Starting daily summary generation');
    
    // Use the decoupled function that separates feed fetching from API processing
    $result = sumai_fetch_and_schedule_processing($force_fetch, $draft_mode, $status_id);
    
    // Log the result
    if ($result) {
        sumai_log_event('Daily summary scheduled successfully');
    } else {
        sumai_log_event('Daily summary scheduling failed', true);
    }
}