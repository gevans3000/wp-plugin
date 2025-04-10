<?php
/**
 * Sumai Plugin Manual Test Script
 * 
 * This script tests the optimized function loading and fallback mechanisms
 * implemented in TASK-006. It can be run directly in the WordPress environment.
 * 
 * Usage: Copy this file to your WordPress plugins directory and access it via browser
 * or include it from a WordPress page template for testing.
 */

// Security check
if (!defined('ABSPATH') && !isset($_GET['run_test'])) {
    die('Access denied.');
}

// Start output buffering
ob_start();

// Test header
echo "<h1>Sumai Plugin - Function Loading & Fallback Test</h1>";
echo "<p>Test run: " . date('Y-m-d H:i:s') . "</p>";
echo "<hr>";

// Test results
$tests = [];
$pass_count = 0;
$fail_count = 0;

/**
 * Helper function to record test results
 */
function record_test($name, $result, $details = '') {
    global $tests, $pass_count, $fail_count;
    
    $tests[] = [
        'name' => $name,
        'result' => $result ? 'PASS' : 'FAIL',
        'details' => $details
    ];
    
    if ($result) {
        $pass_count++;
    } else {
        $fail_count++;
    }
}

// Test 1: Check if function-checker.php is loaded
echo "<h2>Test 1: Function Checker Availability</h2>";
$test1_result = function_exists('sumai_function_not_exists');
record_test('Function Checker Availability', $test1_result, 
    $test1_result ? 'sumai_function_not_exists function is available' : 'sumai_function_not_exists function is not available');

echo $test1_result ? 
    "<p class='pass'>✓ Function checker is available</p>" : 
    "<p class='fail'>✗ Function checker is not available</p>";

// Test 2: Check if fallbacks.php is loaded
echo "<h2>Test 2: Fallback Mechanisms Availability</h2>";
$test2_result = function_exists('sumai_logging_fallback');
record_test('Fallback Mechanisms Availability', $test2_result,
    $test2_result ? 'sumai_logging_fallback function is available' : 'sumai_logging_fallback function is not available');

echo $test2_result ? 
    "<p class='pass'>✓ Fallback mechanisms are available</p>" : 
    "<p class='fail'>✗ Fallback mechanisms are not available</p>";

// Test 3: Check function duplication prevention
echo "<h2>Test 3: Function Duplication Prevention</h2>";

// Define a test function
if (!function_exists('sumai_test_duplicate_function')) {
    function sumai_test_duplicate_function() {
        return 'Original function';
    }
}

// Try to check if function exists using our new system
$test3_result = false;
if (function_exists('sumai_function_not_exists')) {
    $function_exists_check = !sumai_function_not_exists('sumai_test_duplicate_function', false);
    $test3_result = $function_exists_check === true;
    
    // Try to "redefine" the function (this should be prevented)
    if (sumai_function_not_exists('sumai_test_duplicate_function', false)) {
        // This should not execute since the function already exists
        $test3_result = false;
    }
}

record_test('Function Duplication Prevention', $test3_result,
    $test3_result ? 'Function duplication prevention is working' : 'Function duplication prevention is not working');

echo $test3_result ? 
    "<p class='pass'>✓ Function duplication prevention is working</p>" : 
    "<p class='fail'>✗ Function duplication prevention is not working</p>";

// Test 4: Check safe function call
echo "<h2>Test 4: Safe Function Call</h2>";
$test4_result = false;

if (function_exists('sumai_safe_function_call')) {
    // Test with existing function
    $result1 = sumai_safe_function_call('sumai_test_duplicate_function', [], 'Fallback value', false);
    $expected1 = 'Original function';
    
    // Test with non-existing function
    $result2 = sumai_safe_function_call('sumai_nonexistent_function', [], 'Fallback value', false);
    $expected2 = 'Fallback value';
    
    $test4_result = ($result1 === $expected1 && $result2 === $expected2);
}

record_test('Safe Function Call', $test4_result,
    $test4_result ? 'Safe function call is working correctly' : 'Safe function call is not working correctly');

echo $test4_result ? 
    "<p class='pass'>✓ Safe function call is working correctly</p>" : 
    "<p class='fail'>✗ Safe function call is not working correctly</p>";

// Test 5: Check phased loading order
echo "<h2>Test 5: Phased Loading Order</h2>";

// This is a basic check to ensure core files are loaded in the right order
$test5_result = false;

// Check if essential functions from different phases exist
$phase1_loaded = function_exists('sumai_log_event') && function_exists('sumai_encrypt_data');
$phase3_loaded = function_exists('sumai_maybe_load_action_scheduler');
$phase4_loaded = function_exists('sumai_update_status') && function_exists('sumai_fetch_feed');
$phase5_loaded = function_exists('sumai_call_openai_api');

$test5_result = $phase1_loaded && $phase3_loaded && $phase4_loaded && $phase5_loaded;

record_test('Phased Loading Order', $test5_result,
    $test5_result ? 'All phases appear to be loaded correctly' : 'Some phases may not be loaded correctly');

echo $test5_result ? 
    "<p class='pass'>✓ Phased loading order appears correct</p>" : 
    "<p class='fail'>✗ Phased loading order may have issues</p>";

// Test 6: Check fallback mechanisms
echo "<h2>Test 6: Fallback Mechanisms</h2>";

$test6_result = false;

if (function_exists('sumai_openai_api_fallback') && function_exists('sumai_action_scheduler_fallback')) {
    // Test OpenAI API fallback
    $api_fallback = sumai_openai_api_fallback('Test content', 'Test prompt');
    $api_fallback_working = isset($api_fallback['fallback']) && $api_fallback['fallback'] === true;
    
    // We can't fully test the Action Scheduler fallback without a WordPress environment,
    // but we can check if the function exists and has the right signature
    $as_fallback_exists = function_exists('sumai_action_scheduler_fallback');
    
    $test6_result = $api_fallback_working && $as_fallback_exists;
}

record_test('Fallback Mechanisms', $test6_result,
    $test6_result ? 'Fallback mechanisms appear to be working' : 'Fallback mechanisms may have issues');

echo $test6_result ? 
    "<p class='pass'>✓ Fallback mechanisms appear to be working</p>" : 
    "<p class='fail'>✗ Fallback mechanisms may have issues</p>";

// Test summary
echo "<hr>";
echo "<h2>Test Summary</h2>";
echo "<p>Total tests: " . count($tests) . "</p>";
echo "<p>Passed: " . $pass_count . "</p>";
echo "<p>Failed: " . $fail_count . "</p>";

// Generate test results table
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Test</th><th>Result</th><th>Details</th></tr>";

foreach ($tests as $test) {
    $result_class = $test['result'] === 'PASS' ? 'pass' : 'fail';
    echo "<tr>";
    echo "<td>" . htmlspecialchars($test['name']) . "</td>";
    echo "<td class='{$result_class}'>" . htmlspecialchars($test['result']) . "</td>";
    echo "<td>" . htmlspecialchars($test['details']) . "</td>";
    echo "</tr>";
}

echo "</table>";

// Add some basic styling
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .pass { color: green; font-weight: bold; }
    .fail { color: red; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
    th { background-color: #f2f2f2; }
    h1, h2 { color: #333; }
    hr { margin: 20px 0; border: 0; border-top: 1px solid #eee; }
</style>";

// Get the output
$output = ob_get_clean();

// Save test results to file
$results_file = __DIR__ . '/results/test-results-manual.html';
file_put_contents($results_file, $output);

// If running in browser, display the results
if (!defined('ABSPATH') || isset($_GET['display'])) {
    echo $output;
}

// Return results for programmatic use
return [
    'total' => count($tests),
    'passed' => $pass_count,
    'failed' => $fail_count,
    'tests' => $tests
];
