<?php
/**
 * Error testing functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the error testing admin page.
 */
function sumai_register_error_test_page() {
    // Only register in debug mode
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    add_submenu_page(
        'sumai-settings',
        'Error Testing',
        'Error Testing',
        'manage_options',
        'sumai-error-test',
        'sumai_render_error_test_page'
    );
}
add_action('admin_menu', 'sumai_register_error_test_page', 30);

/**
 * Renders the error testing admin page.
 */
function sumai_render_error_test_page() {
    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    // Handle test actions
    if (isset($_POST['action']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'sumai_error_test')) {
        $action = sanitize_text_field($_POST['action']);
        
        switch ($action) {
            case 'test_api_error':
                sumai_test_api_error();
                echo '<div class="notice notice-success"><p>API error test triggered. Check error reports.</p></div>';
                break;
                
            case 'test_system_error':
                sumai_test_system_error();
                echo '<div class="notice notice-success"><p>System error test triggered. Check error reports.</p></div>';
                break;
                
            case 'test_retry':
                sumai_test_retry_mechanism();
                echo '<div class="notice notice-success"><p>Retry mechanism test triggered. Check error reports.</p></div>';
                break;
                
            case 'test_notification':
                sumai_test_error_notification();
                echo '<div class="notice notice-success"><p>Error notification test triggered. Check your admin email.</p></div>';
                break;
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Sumai Error Testing</h1>
        
        <div class="notice notice-warning">
            <p><strong>Warning:</strong> This page is for testing purposes only and should only be used in development environments.</p>
        </div>
        
        <div class="card">
            <h2>Test Error Handling</h2>
            <p>Use these tests to verify that the error handling system is working correctly.</p>
            
            <form method="post">
                <?php wp_nonce_field('sumai_error_test'); ?>
                
                <h3>API Error Test</h3>
                <p>Simulates an API error and tests the error handling system.</p>
                <input type="hidden" name="action" value="test_api_error">
                <p><input type="submit" class="button button-secondary" value="Test API Error"></p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('sumai_error_test'); ?>
                
                <h3>System Error Test</h3>
                <p>Simulates a system error and tests the error handling system.</p>
                <input type="hidden" name="action" value="test_system_error">
                <p><input type="submit" class="button button-secondary" value="Test System Error"></p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('sumai_error_test'); ?>
                
                <h3>Retry Mechanism Test</h3>
                <p>Tests the retry mechanism with simulated failures.</p>
                <input type="hidden" name="action" value="test_retry">
                <p><input type="submit" class="button button-secondary" value="Test Retry Mechanism"></p>
            </form>
            
            <form method="post">
                <?php wp_nonce_field('sumai_error_test'); ?>
                
                <h3>Error Notification Test</h3>
                <p>Tests the admin notification system for critical errors.</p>
                <input type="hidden" name="action" value="test_notification">
                <p><input type="submit" class="button button-secondary" value="Test Error Notification"></p>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Tests API error handling.
 */
function sumai_test_api_error() {
    sumai_handle_error(
        'Test API error message',
        SUMAI_ERROR_API,
        ['test' => true, 'timestamp' => current_time('mysql', true)],
        false
    );
}

/**
 * Tests system error handling.
 */
function sumai_test_system_error() {
    sumai_handle_error(
        'Test system error message',
        SUMAI_ERROR_SYSTEM,
        ['test' => true, 'timestamp' => current_time('mysql', true)],
        false
    );
}

/**
 * Tests the retry mechanism.
 */
function sumai_test_retry_mechanism() {
    // Define a test function that fails the first two times
    $test_function = function($attempt) {
        static $call_count = 0;
        $call_count++;
        
        if ($call_count < 3) {
            sumai_log_event("Test retry function failed (attempt {$call_count})");
            return new WP_Error('test_error', "Test error on attempt {$call_count}");
        }
        
        sumai_log_event("Test retry function succeeded on attempt {$call_count}");
        return "Success on attempt {$call_count}";
    };
    
    // Execute with retry
    $result = sumai_retry_operation(
        $test_function,
        [1],
        'test',
        'test_retry'
    );
    
    // Log result
    if (is_wp_error($result)) {
        sumai_handle_error(
            'Retry test failed: ' . $result->get_error_message(),
            SUMAI_ERROR_SYSTEM,
            ['result' => $result],
            false
        );
    } else {
        sumai_log_event('Retry test succeeded: ' . $result);
    }
}

/**
 * Tests error notification system.
 */
function sumai_test_error_notification() {
    sumai_handle_error(
        'Test critical error notification',
        SUMAI_ERROR_SYSTEM,
        ['test' => true, 'timestamp' => current_time('mysql', true)],
        true // Notify admin
    );
}
