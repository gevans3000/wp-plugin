<?php
/**
 * Unit tests for logging functionality
 */

// Mock WordPress functions if not in WordPress environment
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        return mkdir($dir, 0755, true);
    }
}

/**
 * Test log file creation
 */
function test_log_file_creation() {
    // Set up test log directory
    $test_log_dir = __DIR__ . '/../fixtures/logs';
    if (!file_exists($test_log_dir)) {
        mkdir($test_log_dir, 0755, true);
    }
    
    // Test creating log file
    $log_file = $test_log_dir . '/test-log.log';
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    
    $result = sumai_initialize_log($log_file);
    
    if ($result === true && file_exists($log_file)) {
        echo "✓ PASS: Log file created successfully\n";
    } else {
        echo "✗ FAIL: Failed to create log file\n";
    }
}

/**
 * Test log entry writing
 */
function test_log_entry_writing() {
    // Set up test log file
    $test_log_dir = __DIR__ . '/../fixtures/logs';
    if (!file_exists($test_log_dir)) {
        mkdir($test_log_dir, 0755, true);
    }
    
    $log_file = $test_log_dir . '/test-log.log';
    sumai_initialize_log($log_file);
    
    // Test writing log entry
    $test_message = 'Test log message';
    $result = sumai_log_event($test_message, $log_file);
    
    if ($result === true) {
        echo "✓ PASS: Log entry written successfully\n";
    } else {
        echo "✗ FAIL: Failed to write log entry\n";
    }
    
    // Check if log entry was written correctly
    $log_content = file_get_contents($log_file);
    
    if (strpos($log_content, $test_message) !== false) {
        echo "✓ PASS: Log entry contains correct message\n";
    } else {
        echo "✗ FAIL: Log entry does not contain correct message\n";
    }
    
    // Check if timestamp is included
    if (preg_match('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $log_content)) {
        echo "✓ PASS: Log entry includes timestamp\n";
    } else {
        echo "✗ FAIL: Log entry missing timestamp\n";
    }
}

/**
 * Test error logging
 */
function test_error_logging() {
    // Set up test log file
    $test_log_dir = __DIR__ . '/../fixtures/logs';
    if (!file_exists($test_log_dir)) {
        mkdir($test_log_dir, 0755, true);
    }
    
    $log_file = $test_log_dir . '/test-error.log';
    sumai_initialize_log($log_file);
    
    // Test writing error log
    $test_error = 'Test error message';
    $result = sumai_log_error($test_error, $log_file);
    
    if ($result === true) {
        echo "✓ PASS: Error log written successfully\n";
    } else {
        echo "✗ FAIL: Failed to write error log\n";
    }
    
    // Check if error log was written correctly
    $log_content = file_get_contents($log_file);
    
    if (strpos($log_content, $test_error) !== false) {
        echo "✓ PASS: Error log contains correct message\n";
    } else {
        echo "✗ FAIL: Error log does not contain correct message\n";
    }
    
    // Check if error prefix is included
    if (strpos($log_content, 'ERROR:') !== false) {
        echo "✓ PASS: Error log includes ERROR prefix\n";
    } else {
        echo "✗ FAIL: Error log missing ERROR prefix\n";
    }
}

/**
 * Test log rotation
 */
function test_log_rotation() {
    // Set up test log file
    $test_log_dir = __DIR__ . '/../fixtures/logs';
    if (!file_exists($test_log_dir)) {
        mkdir($test_log_dir, 0755, true);
    }
    
    $log_file = $test_log_dir . '/test-rotation.log';
    sumai_initialize_log($log_file);
    
    // Create a large log file
    $handle = fopen($log_file, 'w');
    for ($i = 0; $i < 1000; $i++) {
        fwrite($handle, str_repeat('X', 100) . "\n");
    }
    fclose($handle);
    
    // Get initial file size
    $initial_size = filesize($log_file);
    
    // Trigger log rotation
    $result = sumai_rotate_logs($log_file, 50000);  // 50KB max size
    
    if ($result === true) {
        echo "✓ PASS: Log rotation triggered successfully\n";
    } else {
        echo "✗ FAIL: Failed to trigger log rotation\n";
    }
    
    // Check if log was rotated
    $rotated_log = $log_file . '.1';
    if (file_exists($rotated_log)) {
        echo "✓ PASS: Rotated log file created\n";
    } else {
        echo "✗ FAIL: Rotated log file not created\n";
    }
    
    // Check if original log was reset
    $new_size = filesize($log_file);
    if ($new_size < $initial_size) {
        echo "✓ PASS: Original log file size reduced after rotation\n";
    } else {
        echo "✗ FAIL: Original log file size not reduced after rotation\n";
    }
}

// Run the tests
echo "Running Logging System Tests\n";
echo "===========================\n\n";

test_log_file_creation();
test_log_entry_writing();
test_error_logging();
test_log_rotation();

echo "\nLogging System Tests Completed\n";
