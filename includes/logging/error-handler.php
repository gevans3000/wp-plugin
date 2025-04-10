<?php
/**
 * Error handling functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Error types for categorizing different errors.
 */
define( 'SUMAI_ERROR_API', 'api' );
define( 'SUMAI_ERROR_SYSTEM', 'system' );
define( 'SUMAI_ERROR_DATA', 'data' );
define( 'SUMAI_ERROR_SECURITY', 'security' );

/**
 * Handles an error with proper logging and optional admin notification.
 *
 * @param string $message Error message.
 * @param string $type Error type (api, system, data, security).
 * @param array  $context Additional context data for the error.
 * @param bool   $notify_admin Whether to notify admin about this error.
 * @return bool Whether the error was handled successfully.
 */
function sumai_handle_error( string $message, string $type = SUMAI_ERROR_SYSTEM, array $context = [], bool $notify_admin = false ) {
    // Log the error with context
    $context_json = !empty($context) ? ' Context: ' . json_encode($context) : '';
    $log_message = "[{$type}] {$message}{$context_json}";
    
    // Log to our custom log
    $logged = sumai_log_event($log_message, true);
    
    // Store in error history for admin reporting
    sumai_store_error_history($message, $type, $context);
    
    // Notify admin if requested and the error is critical
    if ($notify_admin) {
        sumai_notify_admin_error($message, $type, $context);
    }
    
    return $logged;
}

/**
 * Stores error in history for admin reporting.
 *
 * @param string $message Error message.
 * @param string $type Error type.
 * @param array  $context Additional context data.
 * @return bool Whether the error was stored successfully.
 */
function sumai_store_error_history( string $message, string $type, array $context = [] ) {
    $errors = get_option('sumai_error_history', []);
    
    // Limit array size to prevent database bloat
    if (count($errors) >= 100) {
        array_shift($errors);
    }
    
    // Add new error
    $errors[] = [
        'time' => current_time('mysql', true),
        'message' => $message,
        'type' => $type,
        'context' => $context,
    ];
    
    return update_option('sumai_error_history', $errors);
}

/**
 * Notifies admin about critical errors.
 *
 * @param string $message Error message.
 * @param string $type Error type.
 * @param array  $context Additional context data.
 * @return bool Whether the notification was sent successfully.
 */
function sumai_notify_admin_error( string $message, string $type, array $context = [] ) {
    // Check if notifications are enabled in settings
    $settings = function_exists('sumai_get_cached_settings') 
        ? sumai_get_cached_settings() 
        : get_option(SUMAI_SETTINGS_OPTION, []);
    
    if (empty($settings['error_notifications']) || $settings['error_notifications'] !== 'on') {
        return false;
    }
    
    // Check if we've already sent too many notifications recently
    $notification_count = get_transient('sumai_error_notification_count');
    if ($notification_count && $notification_count > 5) {
        // Too many notifications in the last hour, skip this one
        return false;
    }
    
    // Increment notification count
    set_transient('sumai_error_notification_count', ($notification_count ? $notification_count + 1 : 1), HOUR_IN_SECONDS);
    
    // Prepare email content
    $subject = sprintf('[%s] Sumai Plugin Error: %s', get_bloginfo('name'), $type);
    
    $body = "A critical error occurred in the Sumai plugin:\n\n";
    $body .= "Error Type: {$type}\n";
    $body .= "Message: {$message}\n";
    
    if (!empty($context)) {
        $body .= "\nAdditional Context:\n";
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $body .= "{$key}: {$value}\n";
        }
    }
    
    $body .= "\nTime: " . current_time('mysql', true) . "\n";
    $body .= "\nYou can view all recent errors in the Sumai plugin admin dashboard.\n";
    
    // Send email to admin
    $admin_email = get_option('admin_email');
    return wp_mail($admin_email, $subject, $body);
}

/**
 * Clears error history.
 *
 * @return bool Whether the error history was cleared successfully.
 */
function sumai_clear_error_history() {
    return update_option('sumai_error_history', []);
}

/**
 * Gets error history for admin reporting.
 *
 * @param int $limit Maximum number of errors to retrieve.
 * @return array Array of errors.
 */
function sumai_get_error_history( int $limit = 50 ) {
    $errors = get_option('sumai_error_history', []);
    
    // Sort by time, newest first
    usort($errors, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
    
    // Limit results
    return array_slice($errors, 0, $limit);
}
