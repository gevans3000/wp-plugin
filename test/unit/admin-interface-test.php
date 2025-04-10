<?php
/**
 * Unit tests for admin interface functionality
 */

// Mock WordPress functions if not in WordPress environment
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        global $wp_json_response;
        $wp_json_response = array(
            'success' => true,
            'data' => $data
        );
        return true;
    }
    
    function wp_send_json_error($data = null) {
        global $wp_json_response;
        $wp_json_response = array(
            'success' => false,
            'data' => $data
        );
        return true;
    }
    
    function check_ajax_referer($action, $query_arg = false, $die = true) {
        global $wp_nonce_valid;
        return $wp_nonce_valid;
    }
    
    function current_user_can($capability) {
        global $wp_user_capabilities;
        return isset($wp_user_capabilities[$capability]) ? $wp_user_capabilities[$capability] : false;
    }
    
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
    
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
    
    function set_time_limit($seconds) {
        return true;
    }
    
    function sanitize_text_field($text) {
        return trim($text);
    }
    
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_actions;
        $wp_actions[$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
        return true;
    }
    
    // Define constants if not defined
    if (!defined('SUMAI_SETTINGS_OPTION')) {
        define('SUMAI_SETTINGS_OPTION', 'sumai_settings');
    }
    
    if (!defined('SUMAI_MANUAL_GENERATE_ACTION')) {
        define('SUMAI_MANUAL_GENERATE_ACTION', 'sumai_manual_generate');
    }
    
    if (!defined('SUMAI_STATUS_OPTION')) {
        define('SUMAI_STATUS_OPTION', 'sumai_status_tracking');
    }
}

// Mock functions for testing
if (!function_exists('sumai_generate_status_id')) {
    function sumai_generate_status_id() {
        return 'test_status_' . time();
    }
}

if (!function_exists('sumai_update_status')) {
    function sumai_update_status($status_id, $message, $state = 'processing', $data = []) {
        global $wp_options;
        $statuses = isset($wp_options[SUMAI_STATUS_OPTION]) ? $wp_options[SUMAI_STATUS_OPTION] : [];
        
        $timestamp = time();
        
        $statuses[$status_id] = [
            'id' => $status_id,
            'message' => $message,
            'state' => $state,
            'timestamp' => $timestamp,
            'time_formatted' => date('Y-m-d H:i:s', $timestamp),
            'data' => $data
        ];
        
        $wp_options[SUMAI_STATUS_OPTION] = $statuses;
        
        return true;
    }
}

if (!function_exists('sumai_get_status')) {
    function sumai_get_status($status_id) {
        global $wp_options;
        $statuses = isset($wp_options[SUMAI_STATUS_OPTION]) ? $wp_options[SUMAI_STATUS_OPTION] : [];
        
        return isset($statuses[$status_id]) ? $statuses[$status_id] : null;
    }
}

if (!function_exists('sumai_check_action_scheduler')) {
    function sumai_check_action_scheduler() {
        global $wp_action_scheduler_available;
        return $wp_action_scheduler_available;
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '') {
        global $wp_scheduled_actions;
        $action_id = rand(1000, 9999);
        $wp_scheduled_actions[$action_id] = array(
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'group' => $group
        );
        return $action_id;
    }
}

if (!function_exists('sumai_log_event')) {
    function sumai_log_event($message, $is_error = false) {
        global $wp_log_events;
        $wp_log_events[] = array(
            'message' => $message,
            'is_error' => $is_error,
            'timestamp' => time()
        );
        return true;
    }
}

if (!function_exists('sumai_test_feeds')) {
    function sumai_test_feeds($feed_urls) {
        $results = array();
        foreach ($feed_urls as $url) {
            $results[$url] = array(
                'status' => 'success',
                'message' => 'Feed is valid',
                'items' => 5
            );
        }
        return $results;
    }
}

if (!function_exists('sumai_generate_daily_summary')) {
    function sumai_generate_daily_summary($force_fetch = false, $draft_mode = false, $status_id = '') {
        global $wp_generate_result;
        return $wp_generate_result;
    }
}

/**
 * Test AJAX handler registration
 */
function test_ajax_handler_registration() {
    global $wp_actions;
    $wp_actions = array();
    
    // Test that the function exists
    if (function_exists('sumai_register_ajax_handlers')) {
        echo "✓ PASS: sumai_register_ajax_handlers function exists\n";
        
        // Call the function to register handlers
        sumai_register_ajax_handlers();
        
        // Check if the handlers were registered
        if (isset($wp_actions['wp_ajax_sumai_test_feeds'])) {
            echo "✓ PASS: Test feeds AJAX handler registered\n";
        } else {
            echo "✗ FAIL: Test feeds AJAX handler not registered\n";
        }
        
        if (isset($wp_actions['wp_ajax_sumai_generate_now'])) {
            echo "✓ PASS: Generate now AJAX handler registered\n";
        } else {
            echo "✗ FAIL: Generate now AJAX handler not registered\n";
        }
        
        if (isset($wp_actions['wp_ajax_sumai_check_status'])) {
            echo "✓ PASS: Check status AJAX handler registered\n";
        } else {
            echo "✗ FAIL: Check status AJAX handler not registered\n";
        }
    } else {
        echo "✗ FAIL: sumai_register_ajax_handlers function does not exist\n";
    }
}

/**
 * Test test feeds AJAX handler
 */
function test_ajax_test_feeds() {
    global $wp_options, $wp_json_response, $wp_nonce_valid;
    $wp_options = array();
    $wp_json_response = null;
    
    // Test that the function exists
    if (function_exists('sumai_ajax_test_feeds')) {
        echo "✓ PASS: sumai_ajax_test_feeds function exists\n";
        
        // Test with invalid nonce
        $wp_nonce_valid = false;
        sumai_ajax_test_feeds();
        
        if ($wp_json_response['success'] === false && isset($wp_json_response['data']['message']) && $wp_json_response['data']['message'] === 'Security check failed') {
            echo "✓ PASS: Security check works for invalid nonce\n";
        } else {
            echo "✗ FAIL: Security check failed for invalid nonce\n";
        }
        
        // Test with valid nonce but no feed URLs
        $wp_nonce_valid = true;
        $wp_options[SUMAI_SETTINGS_OPTION] = array();
        $wp_json_response = null;
        
        sumai_ajax_test_feeds();
        
        if ($wp_json_response['success'] === false && isset($wp_json_response['data']['message']) && $wp_json_response['data']['message'] === 'No feed URLs configured') {
            echo "✓ PASS: Correctly handles no feed URLs configured\n";
        } else {
            echo "✗ FAIL: Does not handle no feed URLs configured correctly\n";
        }
        
        // Test with valid nonce and feed URLs
        $wp_options[SUMAI_SETTINGS_OPTION] = array(
            'feed_urls' => "https://example.com/feed1\nhttps://example.com/feed2"
        );
        $wp_json_response = null;
        
        sumai_ajax_test_feeds();
        
        if ($wp_json_response['success'] === true && isset($wp_json_response['data']['results']) && isset($wp_json_response['data']['count']) && $wp_json_response['data']['count'] === 2) {
            echo "✓ PASS: Correctly handles valid feed URLs\n";
        } else {
            echo "✗ FAIL: Does not handle valid feed URLs correctly\n";
        }
    } else {
        echo "✗ FAIL: sumai_ajax_test_feeds function does not exist\n";
    }
}

/**
 * Test generate now AJAX handler with Action Scheduler
 */
function test_ajax_generate_now_with_action_scheduler() {
    global $wp_json_response, $wp_nonce_valid, $wp_user_capabilities, $wp_action_scheduler_available, $wp_scheduled_actions;
    $wp_json_response = null;
    $wp_nonce_valid = true;
    $wp_user_capabilities = array('manage_options' => true);
    $wp_action_scheduler_available = true;
    $wp_scheduled_actions = array();
    
    // Test that the function exists
    if (function_exists('sumai_ajax_generate_now')) {
        echo "✓ PASS: sumai_ajax_generate_now function exists\n";
        
        // Set up POST data
        $_POST = array(
            'draft_mode' => '1'
        );
        
        // Call the function
        sumai_ajax_generate_now();
        
        // Check the response
        if ($wp_json_response['success'] === true && isset($wp_json_response['data']['status_id']) && isset($wp_json_response['data']['message'])) {
            echo "✓ PASS: Generate now with Action Scheduler returns success response\n";
        } else {
            echo "✗ FAIL: Generate now with Action Scheduler does not return success response\n";
        }
        
        // Check if an action was scheduled
        if (count($wp_scheduled_actions) === 1) {
            echo "✓ PASS: Action was scheduled correctly\n";
            
            // Check action details
            $action = reset($wp_scheduled_actions);
            
            if ($action['hook'] === SUMAI_MANUAL_GENERATE_ACTION) {
                echo "✓ PASS: Action has correct hook\n";
            } else {
                echo "✗ FAIL: Action has incorrect hook\n";
            }
            
            if (isset($action['args'][0]['force_fetch']) && $action['args'][0]['force_fetch'] === true) {
                echo "✓ PASS: Action has correct force_fetch parameter\n";
            } else {
                echo "✗ FAIL: Action has incorrect force_fetch parameter\n";
            }
            
            if (isset($action['args'][0]['draft_mode']) && $action['args'][0]['draft_mode'] === true) {
                echo "✓ PASS: Action has correct draft_mode parameter\n";
            } else {
                echo "✗ FAIL: Action has incorrect draft_mode parameter\n";
            }
            
            if (isset($action['args'][0]['status_id']) && !empty($action['args'][0]['status_id'])) {
                echo "✓ PASS: Action has valid status_id parameter\n";
            } else {
                echo "✗ FAIL: Action has invalid status_id parameter\n";
            }
        } else {
            echo "✗ FAIL: Action was not scheduled correctly\n";
        }
    } else {
        echo "✗ FAIL: sumai_ajax_generate_now function does not exist\n";
    }
}

/**
 * Test generate now AJAX handler without Action Scheduler
 */
function test_ajax_generate_now_without_action_scheduler() {
    global $wp_json_response, $wp_nonce_valid, $wp_user_capabilities, $wp_action_scheduler_available, $wp_generate_result;
    $wp_json_response = null;
    $wp_nonce_valid = true;
    $wp_user_capabilities = array('manage_options' => true);
    $wp_action_scheduler_available = false;
    
    // Test that the function exists
    if (function_exists('sumai_ajax_generate_now')) {
        echo "✓ PASS: sumai_ajax_generate_now function exists\n";
        
        // Test successful generation
        $wp_generate_result = true;
        $_POST = array(
            'draft_mode' => '1'
        );
        
        // Call the function
        sumai_ajax_generate_now();
        
        // Check the response
        if ($wp_json_response['success'] === true && isset($wp_json_response['data']['status_id']) && isset($wp_json_response['data']['message'])) {
            echo "✓ PASS: Generate now without Action Scheduler returns success response on success\n";
        } else {
            echo "✗ FAIL: Generate now without Action Scheduler does not return success response on success\n";
        }
        
        // Test failed generation
        $wp_generate_result = false;
        $wp_json_response = null;
        
        // Call the function
        sumai_ajax_generate_now();
        
        // Check the response
        if ($wp_json_response['success'] === false && isset($wp_json_response['data']['status_id']) && isset($wp_json_response['data']['message'])) {
            echo "✓ PASS: Generate now without Action Scheduler returns error response on failure\n";
        } else {
            echo "✗ FAIL: Generate now without Action Scheduler does not return error response on failure\n";
        }
    } else {
        echo "✗ FAIL: sumai_ajax_generate_now function does not exist\n";
    }
}

/**
 * Test check status AJAX handler
 */
function test_ajax_check_status() {
    global $wp_json_response, $wp_nonce_valid, $wp_options;
    $wp_json_response = null;
    $wp_nonce_valid = true;
    $wp_options = array();
    
    // Test that the function exists
    if (function_exists('sumai_ajax_check_status')) {
        echo "✓ PASS: sumai_ajax_check_status function exists\n";
        
        // Test with no status ID
        $_POST = array();
        
        sumai_ajax_check_status();
        
        if ($wp_json_response['success'] === false && isset($wp_json_response['data']['message']) && $wp_json_response['data']['message'] === 'No status ID provided') {
            echo "✓ PASS: Correctly handles missing status ID\n";
        } else {
            echo "✗ FAIL: Does not handle missing status ID correctly\n";
        }
        
        // Test with non-existent status ID
        $_POST = array(
            'status_id' => 'nonexistent_status'
        );
        $wp_json_response = null;
        
        sumai_ajax_check_status();
        
        if ($wp_json_response['success'] === false && isset($wp_json_response['data']['message']) && $wp_json_response['data']['message'] === 'Status not found') {
            echo "✓ PASS: Correctly handles non-existent status ID\n";
        } else {
            echo "✗ FAIL: Does not handle non-existent status ID correctly\n";
        }
        
        // Test with valid status ID
        $status_id = 'test_status_' . time();
        $status_data = array(
            'id' => $status_id,
            'message' => 'Test status message',
            'state' => 'processing',
            'timestamp' => time(),
            'time_formatted' => date('Y-m-d H:i:s'),
            'data' => array('test_key' => 'test_value')
        );
        
        $wp_options[SUMAI_STATUS_OPTION] = array(
            $status_id => $status_data
        );
        
        $_POST = array(
            'status_id' => $status_id
        );
        $wp_json_response = null;
        
        sumai_ajax_check_status();
        
        if ($wp_json_response['success'] === true && isset($wp_json_response['data']) && $wp_json_response['data']['id'] === $status_id) {
            echo "✓ PASS: Correctly returns status for valid status ID\n";
        } else {
            echo "✗ FAIL: Does not return status for valid status ID correctly\n";
        }
    } else {
        echo "✗ FAIL: sumai_ajax_check_status function does not exist\n";
    }
}

// Run the tests
echo "Running Admin Interface Tests\n";
echo "===========================\n\n";

test_ajax_handler_registration();
echo "\n";
test_ajax_test_feeds();
echo "\n";
test_ajax_generate_now_with_action_scheduler();
echo "\n";
test_ajax_generate_now_without_action_scheduler();
echo "\n";
test_ajax_check_status();

echo "\nAdmin Interface Tests Completed\n";
