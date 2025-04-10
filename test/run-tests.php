<?php
/**
 * Sumai Plugin Test Runner
 * 
 * This script runs all available tests for the Sumai plugin and generates
 * a unified HTML report with the results.
 */

// Set error reporting for test environment
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start time for performance metrics
$start_time = microtime(true);

// Configuration
$plugin_dir = dirname(__FILE__, 2);
$results_dir = __DIR__ . '/results';

// Ensure results directory exists
if (!file_exists($results_dir)) {
    mkdir($results_dir, 0755, true);
}

// Start capturing all output
ob_start();

// Test results collection
$test_results = [
    'unit_tests' => [
        'name' => 'Unit Tests',
        'status' => 'pending',
        'details' => '',
        'start_time' => 0,
        'end_time' => 0,
        'tests' => [
            'api_key' => [
                'name' => 'API Key Management',
                'status' => 'pending',
                'details' => ''
            ],
            'feed_processing' => [
                'name' => 'Feed Processing',
                'status' => 'pending',
                'details' => ''
            ],
            'content_generation' => [
                'name' => 'Content Generation',
                'status' => 'pending',
                'details' => ''
            ],
            'logging' => [
                'name' => 'Logging System',
                'status' => 'pending',
                'details' => ''
            ]
        ]
    ],
    'integration_tests' => [
        'name' => 'Integration Tests',
        'status' => 'pending',
        'details' => '',
        'start_time' => 0,
        'end_time' => 0,
        'tests' => [
            'action_scheduler' => [
                'name' => 'Action Scheduler Integration',
                'status' => 'pending',
                'details' => ''
            ],
            'core_functionality' => [
                'name' => 'Core Plugin Functionality',
                'status' => 'pending',
                'details' => ''
            ]
        ]
    ]
];

// Banner
echo "=============================================\n";
echo "SUMAI PLUGIN TEST SUITE\n";
echo "Running tests: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================\n\n";

// Function to run a specific test and capture its output
function run_test($command, &$result) {
    $result['start_time'] = microtime(true);
    echo "Running test: {$result['name']}...\n";
    
    // Capture the output of the command
    ob_start();
    passthru($command, $exit_code);
    $output = ob_get_clean();
    
    $result['end_time'] = microtime(true);
    $result['details'] = $output;
    $result['status'] = ($exit_code === 0) ? 'pass' : 'fail';
    
    // Count passes and fails
    $passes = substr_count($output, "PASS");
    $fails = substr_count($output, "FAIL");
    $result['passes'] = $passes;
    $result['fails'] = $fails;
    
    echo "Test completed with status: " . strtoupper($result['status']) . "\n";
    echo "Results: {$passes} passed, {$fails} failed\n";
    echo "Duration: " . round($result['end_time'] - $result['start_time'], 2) . " seconds\n\n";
    
    return $exit_code;
}

// 1. Run Unit Tests
$test_results['unit_tests']['start_time'] = microtime(true);
echo "Running Unit Tests...\n";

// Run API Key Management Tests
$api_key_test_cmd = 'php "' . __DIR__ . '/unit/api-key-test.php"';
run_test($api_key_test_cmd, $test_results['unit_tests']['tests']['api_key']);

// Run Feed Processing Tests
$feed_processing_test_cmd = 'php "' . __DIR__ . '/unit/feed-processing-test.php"';
run_test($feed_processing_test_cmd, $test_results['unit_tests']['tests']['feed_processing']);

// Run Content Generation Tests
$content_generation_test_cmd = 'php "' . __DIR__ . '/unit/content-generation-test.php"';
run_test($content_generation_test_cmd, $test_results['unit_tests']['tests']['content_generation']);

// Run Logging System Tests
$logging_test_cmd = 'php "' . __DIR__ . '/unit/logging-test.php"';
run_test($logging_test_cmd, $test_results['unit_tests']['tests']['logging']);

// Calculate unit tests overall status
$unit_tests_passed = true;
foreach ($test_results['unit_tests']['tests'] as $test) {
    if ($test['status'] !== 'pass') {
        $unit_tests_passed = false;
        break;
    }
}
$test_results['unit_tests']['status'] = $unit_tests_passed ? 'pass' : 'fail';
$test_results['unit_tests']['end_time'] = microtime(true);

// 2. Run Integration Tests
$test_results['integration_tests']['start_time'] = microtime(true);
echo "Running Integration Tests...\n";

// Run Action Scheduler Integration Test
$as_test_cmd = 'php "' . __DIR__ . '/integration/action-scheduler-test.php"';
run_test($as_test_cmd, $test_results['integration_tests']['tests']['action_scheduler']);

// Run Core Functionality Test
$core_test_cmd = 'php "' . __DIR__ . '/integration/core-functionality-test.php"';
run_test($core_test_cmd, $test_results['integration_tests']['tests']['core_functionality']);

// Calculate integration tests overall status
$integration_tests_passed = true;
foreach ($test_results['integration_tests']['tests'] as $test) {
    if ($test['status'] !== 'pass') {
        $integration_tests_passed = false;
        break;
    }
}
$test_results['integration_tests']['status'] = $integration_tests_passed ? 'pass' : 'fail';
$test_results['integration_tests']['end_time'] = microtime(true);

// Compile results
$total_test_suites = count($test_results);
$passed_test_suites = array_reduce(array_keys($test_results), function($carry, $key) use ($test_results) {
    return $carry + ($test_results[$key]['status'] === 'pass' ? 1 : 0);
}, 0);

$total_tests = 0;
$passed_tests = 0;
foreach ($test_results as $suite) {
    foreach ($suite['tests'] as $test) {
        $total_tests++;
        if ($test['status'] === 'pass') {
            $passed_tests++;
        }
    }
}

$pass_rate = ($total_tests > 0) ? round(($passed_tests / $total_tests) * 100, 1) : 0;
$end_time = microtime(true);
$total_duration = round($end_time - $start_time, 2);

echo "=============================================\n";
echo "TEST SUMMARY\n";
echo "=============================================\n";
echo "Total test suites: {$total_test_suites}\n";
echo "Passed test suites: {$passed_test_suites}\n";
echo "Failed test suites: " . ($total_test_suites - $passed_test_suites) . "\n";
echo "Total individual tests: {$total_tests}\n";
echo "Passed individual tests: {$passed_tests}\n";
echo "Failed individual tests: " . ($total_tests - $passed_tests) . "\n";
echo "Pass rate: {$pass_rate}%\n";
echo "Total duration: {$total_duration} seconds\n";
echo "=============================================\n";

// Get the full console output
$console_output = ob_get_clean();

// Generate HTML report
$timestamp = date('Y-m-d-His');
$html_report_path = $results_dir . "/test-results-{$timestamp}.html";

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sumai Plugin Test Results</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #2c3e50;
            margin: 0;
        }
        
        .timestamp {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .summary {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        
        .summary-card {
            flex: 1;
            min-width: 200px;
            margin: 10px;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .summary-card h3 {
            margin-top: 0;
            color: #2c3e50;
        }
        
        .summary-card .value {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .summary-card .label {
            color: #7f8c8d;
        }
        
        .pass-rate {
            color: #fff;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .pass-rate.high {
            background-color: #27ae60;
        }
        
        .pass-rate.medium {
            background-color: #f39c12;
        }
        
        .pass-rate.low {
            background-color: #e74c3c;
        }
        
        .test-suite {
            margin-bottom: 30px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .test-suite-header {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .test-suite-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        
        .test-suite-header .duration {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .test-suite-body {
            padding: 20px;
        }
        
        .test-case {
            margin-bottom: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            border-left: 5px solid #ddd;
        }
        
        .test-case.pass {
            border-left-color: #27ae60;
        }
        
        .test-case.fail {
            border-left-color: #e74c3c;
        }
        
        .test-case-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .test-case-header h3 {
            margin: 0;
            color: #2c3e50;
        }
        
        .test-status {
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
            color: #fff;
        }
        
        .test-status.pass {
            background-color: #27ae60;
        }
        
        .test-status.fail {
            background-color: #e74c3c;
        }
        
        .test-details {
            background-color: #fff;
            padding: 15px;
            border-radius: 3px;
            border: 1px solid #ddd;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .console-output {
            margin-top: 30px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .console-output-header {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .console-output-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        
        .console-output-body {
            padding: 20px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9rem;
            max-height: 500px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-radius: 3px;
        }
        
        .toggle-button {
            background-color: #3498db;
            color: #fff;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .toggle-button:hover {
            background-color: #2980b9;
        }
        
        .hidden {
            display: none;
        }
    </style>
    <script>
        function toggleDetails(id) {
            const details = document.getElementById(id);
            const button = document.querySelector(`[data-target="${id}"]`);
            
            if (details.classList.contains("hidden")) {
                details.classList.remove("hidden");
                button.textContent = "Hide Details";
            } else {
                details.classList.add("hidden");
                button.textContent = "Show Details";
            }
        }
    </script>
</head>
<body>
    <header>
        <h1>Sumai Plugin Test Results</h1>
        <p class="timestamp">Test run completed on ' . date('Y-m-d H:i:s') . '</p>
    </header>
    
    <div class="summary">
        <div class="summary-card">
            <h3>Test Suites</h3>
            <div class="value">' . $passed_test_suites . '/' . $total_test_suites . '</div>
            <div class="label">Passed</div>
        </div>
        
        <div class="summary-card">
            <h3>Individual Tests</h3>
            <div class="value">' . $passed_tests . '/' . $total_tests . '</div>
            <div class="label">Passed</div>
        </div>
        
        <div class="summary-card">
            <h3>Pass Rate</h3>
            <div class="value pass-rate ' . ($pass_rate >= 80 ? 'high' : ($pass_rate >= 50 ? 'medium' : 'low')) . '">' . $pass_rate . '%</div>
            <div class="label">Overall</div>
        </div>
        
        <div class="summary-card">
            <h3>Duration</h3>
            <div class="value">' . $total_duration . 's</div>
            <div class="label">Total Time</div>
        </div>
    </div>';

// Generate test suite sections
foreach ($test_results as $suite_key => $suite) {
    $suite_duration = round($suite['end_time'] - $suite['start_time'], 2);
    
    $html .= '
    <div class="test-suite">
        <div class="test-suite-header">
            <h2>' . $suite['name'] . ' <span class="test-status ' . $suite['status'] . '">' . strtoupper($suite['status']) . '</span></h2>
            <span class="duration">' . $suite_duration . ' seconds</span>
        </div>
        <div class="test-suite-body">';
    
    foreach ($suite['tests'] as $test_key => $test) {
        $html .= '
            <div class="test-case ' . $test['status'] . '">
                <div class="test-case-header">
                    <h3>' . $test['name'] . '</h3>
                    <span class="test-status ' . $test['status'] . '">' . strtoupper($test['status']) . '</span>
                </div>
                <button class="toggle-button" data-target="details-' . $suite_key . '-' . $test_key . '" onclick="toggleDetails(\'details-' . $suite_key . '-' . $test_key . '\')">Show Details</button>
                <div id="details-' . $suite_key . '-' . $test_key . '" class="test-details hidden">' . htmlspecialchars($test['details']) . '</div>
            </div>';
    }
    
    $html .= '
        </div>
    </div>';
}

// Add console output section
$html .= '
    <div class="console-output">
        <div class="console-output-header">
            <h2>Console Output</h2>
        </div>
        <button class="toggle-button" data-target="console-output-body" onclick="toggleDetails(\'console-output-body\')" style="margin: 10px 20px;">Show Console Output</button>
        <div id="console-output-body" class="console-output-body hidden">' . htmlspecialchars($console_output) . '</div>
    </div>
</body>
</html>';

// Write HTML report to file
file_put_contents($html_report_path, $html);

// Output final message
echo "\nTest report generated: {$html_report_path}\n";

// Open the HTML report in the default browser if on Windows
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    echo "Opening test report in browser...\n";
    exec("start {$html_report_path}");
}

// Return exit code based on test results
exit($pass_rate < 100 ? 1 : 0);
