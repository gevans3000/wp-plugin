<?php
/**
 * Unit tests for API key management functionality
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
}

/**
 * Test API key validation
 */
function test_api_key_validation() {
    // Test valid API key format
    $valid_key = 'sk-1234567890abcdefghijklmnopqrstuvwxyz';
    $result = sumai_validate_api_key($valid_key);
    
    if ($result === true) {
        echo "✓ PASS: Valid API key format correctly validated\n";
    } else {
        echo "✗ FAIL: Valid API key format incorrectly rejected\n";
    }
    
    // Test invalid API key format
    $invalid_key = 'invalid-key-format';
    $result = sumai_validate_api_key($invalid_key);
    
    if ($result !== true) {
        echo "✓ PASS: Invalid API key format correctly rejected\n";
    } else {
        echo "✗ FAIL: Invalid API key format incorrectly validated\n";
    }
    
    // Test empty API key
    $empty_key = '';
    $result = sumai_validate_api_key($empty_key);
    
    if ($result !== true) {
        echo "✓ PASS: Empty API key correctly rejected\n";
    } else {
        echo "✗ FAIL: Empty API key incorrectly validated\n";
    }
}

/**
 * Test API key storage and retrieval
 */
function test_api_key_storage() {
    global $wp_options;
    $wp_options = array();
    
    // Test storing API key
    $test_key = 'sk-testapikey12345';
    sumai_store_api_key($test_key);
    
    // Check if key was stored (encrypted)
    $stored_key = get_option('sumai_api_key', '');
    
    if (!empty($stored_key) && $stored_key !== $test_key) {
        echo "✓ PASS: API key stored with encryption\n";
    } else {
        echo "✗ FAIL: API key not stored correctly or not encrypted\n";
    }
    
    // Test retrieving API key
    $retrieved_key = sumai_get_api_key();
    
    if ($retrieved_key === $test_key) {
        echo "✓ PASS: API key retrieved correctly\n";
    } else {
        echo "✗ FAIL: API key retrieval failed\n";
    }
}

// Run the tests
echo "Running API Key Management Tests\n";
echo "===============================\n\n";

test_api_key_validation();
test_api_key_storage();

echo "\nAPI Key Management Tests Completed\n";
