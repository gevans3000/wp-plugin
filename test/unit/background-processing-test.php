<?php
/**
 * Unit tests for background processing functionality
 */

// Mock WordPress functions if not in WordPress environment
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_actions;
        $wp_actions[$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
        return true;
    }
    
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
    
    function as_has_scheduled_action($hook, $args = null, $group = '') {
        global $wp_scheduled_actions;
        foreach ($wp_scheduled_actions as $action) {
            if ($action['hook'] === $hook) {
                if ($args === null || $action['args'] === $args) {
                    if ($group === '' || $action['group'] === $group) {
                        return true;
                    }
                }
            }
        }
        return false;
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
    
    // Define constants if not defined
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', '/var/www/html/wp-content/plugins');
    }
    
    if (!defined('SUMAI_PROCESS_CONTENT_ACTION')) {
        define('SUMAI_PROCESS_CONTENT_ACTION', 'sumai_process_content');
    }
    
    if (!defined('SUMAI_RETRY_ACTION')) {
        define('SUMAI_RETRY_ACTION', 'sumai_retry_content');
    }
    
    if (!defined('SUMAI_MAX_RETRIES')) {
        define('SUMAI_MAX_RETRIES', 3);
    }
    
    if (!defined('SUMAI_RETRY_DELAY')) {
        define('SUMAI_RETRY_DELAY', 300); // 5 minutes in seconds
    }
    
    if (!defined('SUMAI_STATUS_OPTION')) {
        define('SUMAI_STATUS_OPTION', 'sumai_status_tracking');
    }
}

// Mock functions for testing
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

if (!function_exists('sumai_process_content_helper')) {
    function sumai_process_content_helper($content, $context_prompt, $title_prompt, $draft_mode, $post_signature, $guids_to_add, $status_id) {
        global $wp_process_content_result;
        return $wp_process_content_result;
    }
}

/**
 * Test Action Scheduler availability check
 */
function test_action_scheduler_availability() {
    // Test that the function exists
    if (function_exists('sumai_check_action_scheduler')) {
        echo "✓ PASS: sumai_check_action_scheduler function exists\n";
        
        // Test with Action Scheduler functions available
        global $wp_function_exists_map;
        $wp_function_exists_map = array(
            'as_schedule_single_action' => true,
            'as_has_scheduled_action' => true
        );
        
        function function_exists($function_name) {
            global $wp_function_exists_map;
            return isset($wp_function_exists_map[$function_name]) ? $wp_function_exists_map[$function_name] : false;
        }
        
        $result = sumai_check_action_scheduler();
        
        if ($result === true) {
            echo "✓ PASS: Correctly detects Action Scheduler when functions are available\n";
        } else {
            echo "✗ FAIL: Does not correctly detect Action Scheduler when functions are available\n";
        }
        
        // Test with Action Scheduler functions not available
        $wp_function_exists_map = array(
            'as_schedule_single_action' => false,
            'as_has_scheduled_action' => false
        );
        
        $result = sumai_check_action_scheduler();
        
        if ($result === false) {
            echo "✓ PASS: Correctly detects Action Scheduler not available when functions are missing\n";
        } else {
            echo "✗ FAIL: Does not correctly detect Action Scheduler not available when functions are missing\n";
        }
        
        // Restore original function_exists
        if (function_exists('restore_function_exists')) {
            restore_function_exists();
        }
    } else {
        echo "✗ FAIL: sumai_check_action_scheduler function does not exist\n";
    }
}

/**
 * Test scheduled action registration
 */
function test_scheduled_action_registration() {
    global $wp_actions;
    $wp_actions = array();
    
    // Test that the function exists
    if (function_exists('sumai_register_scheduled_actions')) {
        echo "✓ PASS: sumai_register_scheduled_actions function exists\n";
        
        // Call the function to register actions
        sumai_register_scheduled_actions();
        
        // Check if the actions were registered
        if (isset($wp_actions[SUMAI_PROCESS_CONTENT_ACTION])) {
            echo "✓ PASS: Process content action registered\n";
        } else {
            echo "✗ FAIL: Process content action not registered\n";
        }
        
        if (isset($wp_actions[SUMAI_RETRY_ACTION])) {
            echo "✓ PASS: Retry action registered\n";
        } else {
            echo "✗ FAIL: Retry action not registered\n";
        }
    } else {
        echo "✗ FAIL: sumai_register_scheduled_actions function does not exist\n";
    }
}

/**
 * Test content processing action
 */
function test_process_content_action() {
    global $wp_log_events, $wp_process_content_result;
    $wp_log_events = array();
    
    // Test that the function exists
    if (function_exists('sumai_process_content_action')) {
        echo "✓ PASS: sumai_process_content_action function exists\n";
        
        // Test successful processing
        $wp_process_content_result = true;
        
        $args = array(
            'content' => 'Test content',
            'context_prompt' => 'Test context',
            'title_prompt' => 'Test title',
            'draft_mode' => false,
            'post_signature' => 'Test signature',
            'guids_to_add' => array('guid1', 'guid2'),
            'status_id' => 'test_status_id',
            'feed_url' => 'https://example.com/feed'
        );
        
        $result = sumai_process_content_action($args);
        
        if ($result === true) {
            echo "✓ PASS: Process content action returns true on success\n";
        } else {
            echo "✗ FAIL: Process content action does not return true on success\n";
        }
        
        // Check if log events were created
        if (count($wp_log_events) > 0) {
            echo "✓ PASS: Process content action creates log events\n";
        } else {
            echo "✗ FAIL: Process content action does not create log events\n";
        }
        
        // Test failed processing
        $wp_process_content_result = false;
        $wp_log_events = array();
        
        // Override sumai_handle_processing_failure for testing
        function sumai_handle_processing_failure($args, $error_message) {
            global $wp_processing_failure_handled;
            $wp_processing_failure_handled = true;
            return false;
        }
        
        global $wp_processing_failure_handled;
        $wp_processing_failure_handled = false;
        
        $result = sumai_process_content_action($args);
        
        if ($result === false) {
            echo "✓ PASS: Process content action returns false on failure\n";
        } else {
            echo "✗ FAIL: Process content action does not return false on failure\n";
        }
        
        if ($wp_processing_failure_handled === true) {
            echo "✓ PASS: Process content action calls failure handler on failure\n";
        } else {
            echo "✗ FAIL: Process content action does not call failure handler on failure\n";
        }
    } else {
        echo "✗ FAIL: sumai_process_content_action function does not exist\n";
    }
}

/**
 * Test retry content action
 */
function test_retry_content_action() {
    // Test that the function exists
    if (function_exists('sumai_retry_content_action')) {
        echo "✓ PASS: sumai_retry_content_action function exists\n";
        
        // Override sumai_process_content_action for testing
        function sumai_process_content_action($args) {
            global $wp_process_content_called;
            $wp_process_content_called = true;
            return true;
        }
        
        global $wp_process_content_called;
        $wp_process_content_called = false;
        
        $args = array(
            'content' => 'Test content',
            'retry_count' => 1
        );
        
        $result = sumai_retry_content_action($args);
        
        if ($result === true) {
            echo "✓ PASS: Retry content action returns result from process content action\n";
        } else {
            echo "✗ FAIL: Retry content action does not return result from process content action\n";
        }
        
        if ($wp_process_content_called === true) {
            echo "✓ PASS: Retry content action calls process content action\n";
        } else {
            echo "✗ FAIL: Retry content action does not call process content action\n";
        }
    } else {
        echo "✗ FAIL: sumai_retry_content_action function does not exist\n";
    }
}

/**
 * Test processing failure handler
 */
function test_processing_failure_handler() {
    global $wp_log_events, $wp_options, $wp_scheduled_actions;
    $wp_log_events = array();
    $wp_options = array();
    $wp_scheduled_actions = array();
    
    // Test that the function exists
    if (function_exists('sumai_handle_processing_failure')) {
        echo "✓ PASS: sumai_handle_processing_failure function exists\n";
        
        // Test with retry count below max
        $args = array(
            'retry_count' => 1, // Below max of 3
            'status_id' => 'test_status_id',
            'feed_url' => 'https://example.com/feed'
        );
        
        $result = sumai_handle_processing_failure($args, 'Test error message');
        
        if ($result === false) {
            echo "✓ PASS: Processing failure handler returns false\n";
        } else {
            echo "✗ FAIL: Processing failure handler does not return false\n";
        }
        
        // Check if log events were created
        if (count($wp_log_events) > 0) {
            echo "✓ PASS: Processing failure handler creates log events\n";
        } else {
            echo "✗ FAIL: Processing failure handler does not create log events\n";
        }
        
        // Check if retry was scheduled
        if (count($wp_scheduled_actions) > 0) {
            echo "✓ PASS: Processing failure handler schedules retry\n";
            
            // Check retry details
            $action = reset($wp_scheduled_actions);
            
            if ($action['hook'] === SUMAI_RETRY_ACTION) {
                echo "✓ PASS: Retry has correct hook\n";
            } else {
                echo "✗ FAIL: Retry has incorrect hook\n";
            }
            
            if (isset($action['args']['args']['retry_count']) && $action['args']['args']['retry_count'] === 2) {
                echo "✓ PASS: Retry count incremented correctly\n";
            } else {
                echo "✗ FAIL: Retry count not incremented correctly\n";
            }
        } else {
            echo "✗ FAIL: Processing failure handler does not schedule retry\n";
        }
        
        // Test with retry count at max
        $wp_log_events = array();
        $wp_scheduled_actions = array();
        
        $args = array(
            'retry_count' => SUMAI_MAX_RETRIES, // At max
            'status_id' => 'test_status_id',
            'feed_url' => 'https://example.com/feed'
        );
        
        $result = sumai_handle_processing_failure($args, 'Test error message');
        
        if ($result === false) {
            echo "✓ PASS: Processing failure handler returns false at max retries\n";
        } else {
            echo "✗ FAIL: Processing failure handler does not return false at max retries\n";
        }
        
        // Check if retry was not scheduled
        if (count($wp_scheduled_actions) === 0) {
            echo "✓ PASS: Processing failure handler does not schedule retry at max retries\n";
        } else {
            echo "✗ FAIL: Processing failure handler schedules retry at max retries\n";
        }
        
        // Check if status was updated
        $statuses = get_option(SUMAI_STATUS_OPTION, []);
        
        if (isset($statuses['test_status_id']) && $statuses['test_status_id']['state'] === 'error') {
            echo "✓ PASS: Status updated to error at max retries\n";
        } else {
            echo "✗ FAIL: Status not updated to error at max retries\n";
        }
    } else {
        echo "✗ FAIL: sumai_handle_processing_failure function does not exist\n";
    }
}

/**
 * Test content processing scheduling
 */
function test_schedule_content_processing() {
    global $wp_scheduled_actions, $wp_log_events;
    $wp_scheduled_actions = array();
    $wp_log_events = array();
    
    // Test that the function exists
    if (function_exists('sumai_schedule_content_processing')) {
        echo "✓ PASS: sumai_schedule_content_processing function exists\n";
        
        // Override sumai_check_action_scheduler for testing
        function sumai_check_action_scheduler() {
            return true;
        }
        
        $args = array(
            'content' => 'Test content',
            'feed_url' => 'https://example.com/feed'
        );
        
        $result = sumai_schedule_content_processing($args);
        
        if ($result !== false && is_numeric($result)) {
            echo "✓ PASS: Schedule content processing returns action ID\n";
        } else {
            echo "✗ FAIL: Schedule content processing does not return action ID\n";
        }
        
        // Check if action was scheduled
        if (count($wp_scheduled_actions) > 0) {
            echo "✓ PASS: Content processing action scheduled\n";
            
            // Check action details
            $action = reset($wp_scheduled_actions);
            
            if ($action['hook'] === SUMAI_PROCESS_CONTENT_ACTION) {
                echo "✓ PASS: Action has correct hook\n";
            } else {
                echo "✗ FAIL: Action has incorrect hook\n";
            }
            
            if (isset($action['args']['args']['content']) && $action['args']['args']['content'] === 'Test content') {
                echo "✓ PASS: Action has correct content parameter\n";
            } else {
                echo "✗ FAIL: Action has incorrect content parameter\n";
            }
            
            if (isset($action['args']['args']['retry_count']) && $action['args']['args']['retry_count'] === 0) {
                echo "✓ PASS: Action has initialized retry count\n";
            } else {
                echo "✗ FAIL: Action does not have initialized retry count\n";
            }
        } else {
            echo "✗ FAIL: Content processing action not scheduled\n";
        }
        
        // Test with Action Scheduler not available
        $wp_scheduled_actions = array();
        $wp_log_events = array();
        
        // Override sumai_check_action_scheduler for testing
        function sumai_check_action_scheduler_override() {
            return false;
        }
        
        // Replace the function
        $original_function = 'sumai_check_action_scheduler';
        $override_function = 'sumai_check_action_scheduler_override';
        
        if (function_exists($override_function)) {
            runkit_function_rename($original_function, $original_function . '_original');
            runkit_function_rename($override_function, $original_function);
        }
        
        $result = sumai_schedule_content_processing($args);
        
        // Restore the original function
        if (function_exists($original_function . '_original')) {
            runkit_function_rename($original_function, $override_function);
            runkit_function_rename($original_function . '_original', $original_function);
        }
        
        if ($result === false) {
            echo "✓ PASS: Schedule content processing returns false when Action Scheduler not available\n";
        } else {
            echo "✗ FAIL: Schedule content processing does not return false when Action Scheduler not available\n";
        }
        
        // Check if error was logged
        $error_logged = false;
        foreach ($wp_log_events as $event) {
            if ($event['is_error'] === true && strpos($event['message'], 'Action Scheduler not available') !== false) {
                $error_logged = true;
                break;
            }
        }
        
        if ($error_logged) {
            echo "✓ PASS: Error logged when Action Scheduler not available\n";
        } else {
            echo "✗ FAIL: Error not logged when Action Scheduler not available\n";
        }
    } else {
        echo "✗ FAIL: sumai_schedule_content_processing function does not exist\n";
    }
}

// Run the tests
echo "Running Background Processing Tests\n";
echo "================================\n\n";

// Skip tests that require runkit if it's not available
if (!function_exists('runkit_function_rename')) {
    echo "Note: Some tests will be skipped because runkit extension is not available.\n\n";
}

test_action_scheduler_availability();
echo "\n";
test_scheduled_action_registration();
echo "\n";
test_process_content_action();
echo "\n";
test_retry_content_action();
echo "\n";
test_processing_failure_handler();
echo "\n";
test_schedule_content_processing();

echo "\nBackground Processing Tests Completed\n";
