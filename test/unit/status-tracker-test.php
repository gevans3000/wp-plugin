<?php
/**
 * Unit tests for status tracking functionality
 */

// Mock WordPress functions if not in WordPress environment
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
    
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
    
    // Define the constant if not defined
    if (!defined('SUMAI_STATUS_OPTION')) {
        define('SUMAI_STATUS_OPTION', 'sumai_status_tracking');
    }
}

/**
 * Test status ID generation
 */
function test_status_id_generation() {
    // Test that the function exists
    if (function_exists('sumai_generate_status_id')) {
        echo "✓ PASS: sumai_generate_status_id function exists\n";
        
        // Test generating a status ID
        $status_id = sumai_generate_status_id();
        
        if (!empty($status_id) && is_string($status_id)) {
            echo "✓ PASS: Status ID generated successfully\n";
        } else {
            echo "✗ FAIL: Failed to generate status ID\n";
        }
        
        // Test uniqueness of status IDs
        $status_id2 = sumai_generate_status_id();
        
        if ($status_id !== $status_id2) {
            echo "✓ PASS: Generated status IDs are unique\n";
        } else {
            echo "✗ FAIL: Generated status IDs are not unique\n";
        }
    } else {
        echo "✗ FAIL: sumai_generate_status_id function does not exist\n";
    }
}

/**
 * Test status update functionality
 */
function test_status_update() {
    global $wp_options;
    $wp_options = array();
    
    // Test that the function exists
    if (function_exists('sumai_update_status')) {
        echo "✓ PASS: sumai_update_status function exists\n";
        
        // Test updating a status
        $status_id = 'test_status_' . time();
        $message = 'Test status message';
        $state = 'processing';
        $data = ['test_key' => 'test_value'];
        
        $result = sumai_update_status($status_id, $message, $state, $data);
        
        if ($result === true) {
            echo "✓ PASS: Status updated successfully\n";
        } else {
            echo "✗ FAIL: Failed to update status\n";
        }
        
        // Test retrieving the updated status
        $statuses = get_option(SUMAI_STATUS_OPTION, []);
        
        if (isset($statuses[$status_id])) {
            echo "✓ PASS: Status stored in database\n";
            
            $stored_status = $statuses[$status_id];
            
            if ($stored_status['message'] === $message) {
                echo "✓ PASS: Status message stored correctly\n";
            } else {
                echo "✗ FAIL: Status message not stored correctly\n";
            }
            
            if ($stored_status['state'] === $state) {
                echo "✓ PASS: Status state stored correctly\n";
            } else {
                echo "✗ FAIL: Status state not stored correctly\n";
            }
            
            if (isset($stored_status['data']) && $stored_status['data']['test_key'] === 'test_value') {
                echo "✓ PASS: Status data stored correctly\n";
            } else {
                echo "✗ FAIL: Status data not stored correctly\n";
            }
        } else {
            echo "✗ FAIL: Status not stored in database\n";
        }
    } else {
        echo "✗ FAIL: sumai_update_status function does not exist\n";
    }
}

/**
 * Test status retrieval functionality
 */
function test_status_retrieval() {
    global $wp_options;
    $wp_options = array();
    
    // Test that the function exists
    if (function_exists('sumai_get_status')) {
        echo "✓ PASS: sumai_get_status function exists\n";
        
        // Create a test status
        $status_id = 'test_status_' . time();
        $message = 'Test status message';
        $state = 'processing';
        $data = ['test_key' => 'test_value'];
        
        sumai_update_status($status_id, $message, $state, $data);
        
        // Test retrieving the status
        $status = sumai_get_status($status_id);
        
        if (!empty($status) && is_array($status)) {
            echo "✓ PASS: Status retrieved successfully\n";
            
            if ($status['message'] === $message) {
                echo "✓ PASS: Retrieved status has correct message\n";
            } else {
                echo "✗ FAIL: Retrieved status has incorrect message\n";
            }
            
            if ($status['state'] === $state) {
                echo "✓ PASS: Retrieved status has correct state\n";
            } else {
                echo "✗ FAIL: Retrieved status has incorrect state\n";
            }
        } else {
            echo "✗ FAIL: Failed to retrieve status\n";
        }
        
        // Test retrieving a non-existent status
        $nonexistent_status = sumai_get_status('nonexistent_status_id');
        
        if ($nonexistent_status === null) {
            echo "✓ PASS: Correctly returned null for non-existent status\n";
        } else {
            echo "✗ FAIL: Did not return null for non-existent status\n";
        }
    } else {
        echo "✗ FAIL: sumai_get_status function does not exist\n";
    }
}

/**
 * Test retrieving all statuses
 */
function test_get_all_statuses() {
    global $wp_options;
    $wp_options = array();
    
    // Test that the function exists
    if (function_exists('sumai_get_all_statuses')) {
        echo "✓ PASS: sumai_get_all_statuses function exists\n";
        
        // Create multiple test statuses
        $statuses = array(
            'test_status_1' => array(
                'id' => 'test_status_1',
                'message' => 'Test status 1',
                'state' => 'pending',
                'timestamp' => time() - 100,
                'time_formatted' => date('Y-m-d H:i:s', time() - 100),
                'data' => array()
            ),
            'test_status_2' => array(
                'id' => 'test_status_2',
                'message' => 'Test status 2',
                'state' => 'processing',
                'timestamp' => time() - 50,
                'time_formatted' => date('Y-m-d H:i:s', time() - 50),
                'data' => array()
            ),
            'test_status_3' => array(
                'id' => 'test_status_3',
                'message' => 'Test status 3',
                'state' => 'complete',
                'timestamp' => time(),
                'time_formatted' => date('Y-m-d H:i:s', time()),
                'data' => array()
            )
        );
        
        update_option(SUMAI_STATUS_OPTION, $statuses);
        
        // Test retrieving all statuses
        $all_statuses = sumai_get_all_statuses();
        
        if (is_array($all_statuses) && count($all_statuses) === 3) {
            echo "✓ PASS: All statuses retrieved successfully\n";
        } else {
            echo "✗ FAIL: Failed to retrieve all statuses\n";
        }
        
        // Test retrieving statuses filtered by state
        $processing_statuses = sumai_get_all_statuses('processing');
        
        if (is_array($processing_statuses) && count($processing_statuses) === 1 && isset($processing_statuses['test_status_2'])) {
            echo "✓ PASS: Statuses filtered by state correctly\n";
        } else {
            echo "✗ FAIL: Failed to filter statuses by state\n";
        }
        
        // Test limiting the number of results
        $limited_statuses = sumai_get_all_statuses(null, 2);
        
        if (is_array($limited_statuses) && count($limited_statuses) === 2) {
            echo "✓ PASS: Status results limited correctly\n";
        } else {
            echo "✗ FAIL: Failed to limit status results\n";
        }
        
        // Test sorting (newest first)
        $first_status = reset($all_statuses);
        
        if ($first_status['id'] === 'test_status_3') {
            echo "✓ PASS: Statuses sorted correctly (newest first)\n";
        } else {
            echo "✗ FAIL: Statuses not sorted correctly\n";
        }
    } else {
        echo "✗ FAIL: sumai_get_all_statuses function does not exist\n";
    }
}

/**
 * Test status cleanup functionality
 */
function test_status_cleanup() {
    global $wp_options;
    $wp_options = array();
    
    // Test that the function exists
    if (function_exists('sumai_cleanup_statuses')) {
        echo "✓ PASS: sumai_cleanup_statuses function exists\n";
        
        // Create test statuses with different ages
        $statuses = array(
            'old_status' => array(
                'id' => 'old_status',
                'message' => 'Old status',
                'state' => 'complete',
                'timestamp' => time() - 100000, // Very old
                'time_formatted' => date('Y-m-d H:i:s', time() - 100000),
                'data' => array()
            ),
            'recent_status' => array(
                'id' => 'recent_status',
                'message' => 'Recent status',
                'state' => 'complete',
                'timestamp' => time() - 100, // Recent
                'time_formatted' => date('Y-m-d H:i:s', time() - 100),
                'data' => array()
            )
        );
        
        update_option(SUMAI_STATUS_OPTION, $statuses);
        
        // Test cleaning up old statuses
        $removed = sumai_cleanup_statuses(1000); // 1000 seconds max age
        
        if ($removed === 1) {
            echo "✓ PASS: Correct number of old statuses removed\n";
        } else {
            echo "✗ FAIL: Incorrect number of old statuses removed\n";
        }
        
        // Check that only old statuses were removed
        $remaining_statuses = get_option(SUMAI_STATUS_OPTION, []);
        
        if (count($remaining_statuses) === 1 && isset($remaining_statuses['recent_status'])) {
            echo "✓ PASS: Only old statuses were removed\n";
        } else {
            echo "✗ FAIL: Recent statuses were incorrectly removed\n";
        }
        
        // Test with no old statuses
        $removed = sumai_cleanup_statuses(1000);
        
        if ($removed === 0) {
            echo "✓ PASS: Correctly reported no statuses to remove\n";
        } else {
            echo "✗ FAIL: Incorrectly reported statuses removed\n";
        }
    } else {
        echo "✗ FAIL: sumai_cleanup_statuses function does not exist\n";
    }
}

// Run the tests
echo "Running Status Tracker Tests\n";
echo "===========================\n\n";

test_status_id_generation();
echo "\n";
test_status_update();
echo "\n";
test_status_retrieval();
echo "\n";
test_get_all_statuses();
echo "\n";
test_status_cleanup();

echo "\nStatus Tracker Tests Completed\n";
