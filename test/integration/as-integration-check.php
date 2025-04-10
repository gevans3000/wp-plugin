<?php
/**
 * Action Scheduler Integration Verification
 * 
 * This file performs comprehensive verification of the Action Scheduler integration
 * in the Sumai plugin. It checks all aspects of the integration including:
 * - Function existence
 * - Constant definitions
 * - Background processing
 * - Parameter handling
 * - Status updates
 * 
 * DO NOT DEPLOY THIS FILE TO PRODUCTION
 */

// Define output helper functions
function output_header($title) {
    echo "<h2>{$title}</h2>";
}

function output_test_result($name, $result, $notes = '') {
    $status = $result ? 'PASS' : 'FAIL';
    $color = $result ? 'green' : 'red';
    echo "<div class='test-case'>";
    echo "<span class='test-name'>{$name}</span>";
    echo "<span class='test-status' style='color:{$color};'>{$status}</span>";
    if (!empty($notes)) {
        echo "<div class='test-notes'>{$notes}</div>";
    }
    echo "</div>";
}

// Start the test output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sumai Action Scheduler Integration Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f7f7f7;
        }
        
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        
        h2 {
            color: #2c3e50;
            margin-top: 30px;
            background-color: #ecf0f1;
            padding: 10px;
            border-radius: 4px;
        }
        
        .test-case {
            margin: 15px 0;
            padding: 15px;
            background-color: white;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .test-name {
            flex: 1;
            font-weight: 500;
        }
        
        .test-status {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 4px;
            margin-left: 15px;
        }
        
        .test-notes {
            width: 100%;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            font-size: 0.9em;
            color: #555;
        }
        
        .summary {
            margin-top: 30px;
            padding: 20px;
            background-color: #e8f4fd;
            border-radius: 4px;
            border-left: 5px solid #3498db;
        }
        
        .pass-rate {
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .timestamp {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-top: 20px;
        }
        
        pre {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            overflow: auto;
            font-family: monospace;
            font-size: 14px;
            line-height: 1.45;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .test-case {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .test-status {
                margin-left: 0;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <h1>Sumai Action Scheduler Integration Test</h1>
    <p>This diagnostic tool checks the Action Scheduler integration in the Sumai plugin to ensure all components work correctly.</p>
    
    <?php
    // Test Set 1: File Existence Check
    output_header("1. Core File Existence Check");
    
    $core_files = [
        'sumai.php',
        'admin.js',
        'debug.php',
        'disable-wp-cron.php',
        'wp-cron-trigger.php'
    ];
    
    $all_files_exist = true;
    foreach ($core_files as $file) {
        $file_path = dirname(__DIR__) . '/' . $file;
        $exists = file_exists($file_path);
        output_test_result(
            "File exists: {$file}",
            $exists,
            $exists ? "Found at: {$file_path}" : "Missing: {$file_path}"
        );
        if (!$exists) {
            $all_files_exist = false;
        }
    }
    
    // Test Set 2: Function Existence Check
    output_header("2. Action Scheduler Integration Functions");
    
    $sumaiContent = file_get_contents(dirname(__DIR__) . '/sumai.php');
    if ($sumaiContent === false) {
        output_test_result("Reading sumai.php", false, "Could not read sumai.php content");
        $sumaiContent = "";
    } else {
        output_test_result("Reading sumai.php", true, "Successfully read " . strlen($sumaiContent) . " bytes");
    }
    
    $requiredFunctions = [
        "sumai_check_action_scheduler",
        "sumai_process_content_action"
    ];
    
    $allFunctionsFound = true;
    foreach ($requiredFunctions as $func) {
        $found = preg_match("/function\s+{$func}/", $sumaiContent);
        output_test_result(
            "Function exists: {$func}",
            $found,
            $found ? "Function defined in sumai.php" : "Function not found in sumai.php"
        );
        if (!$found) {
            $allFunctionsFound = false;
        }
    }
    
    // Test Set 3: Constant Definitions
    output_header("3. Action Scheduler Constants");
    
    $requiredConstants = [
        "SUMAI_PROCESS_CONTENT_ACTION"
    ];
    
    $allConstantsFound = true;
    foreach ($requiredConstants as $const) {
        $found = preg_match("/define\s*\(\s*['\"]" . preg_quote($const, '/') . "/", $sumaiContent);
        output_test_result(
            "Constant defined: {$const}",
            $found,
            $found ? "Constant defined in sumai.php" : "Constant not found in sumai.php"
        );
        if (!$found) {
            $allConstantsFound = false;
        }
    }
    
    // Test Set 4: Background Action Scheduling
    output_header("4. Background Action Scheduling");
    
    $correctScheduling = preg_match("/as_schedule_single_action\s*\(\s*time\(\s*\)\s*,\s*SUMAI_PROCESS_CONTENT_ACTION/", $sumaiContent);
    output_test_result(
        "Background action scheduling implementation",
        $correctScheduling,
        $correctScheduling ? "Proper as_schedule_single_action usage found" : "Incorrect or missing background action scheduling"
    );
    
    // Test Set 5: Action Scheduler Availability Detection
    output_header("5. Action Scheduler Availability Detection");
    
    $correctAvailabilityCheck = preg_match("/function_exists\s*\(\s*['\"]as_schedule_single_action['\"]\s*\)/", $sumaiContent);
    output_test_result(
        "Action Scheduler availability check",
        $correctAvailabilityCheck,
        $correctAvailabilityCheck ? "Proper function_exists check implemented" : "Missing or incorrect availability check"
    );
    
    // Test Set 6: Status Updates During Background Processing
    output_header("6. Status Updates During Background Processing");
    
    $hasStatusUpdates = preg_match("/sumai_update_status\s*\(\s*.*status_id/", $sumaiContent);
    output_test_result(
        "Status updates during background processing",
        $hasStatusUpdates,
        $hasStatusUpdates ? "Status update calls found in background processing" : "Missing status updates in background processing"
    );
    
    // Test Set 7: Parameter Handling and GUID Tracking
    output_header("7. Parameter Handling and GUID Tracking");
    
    // Check parameter handling
    $correctParameterHandling = (preg_match("/\\\$args\[/", $sumaiContent) || 
                               preg_match("/\\\$args\s*\[\s*['\"]/", $sumaiContent) || 
                               preg_match("/extract\s*\(\s*\\\$args/", $sumaiContent));
    
    output_test_result(
        "Parameter handling in background action",
        $correctParameterHandling,
        $correctParameterHandling ? "Proper parameter handling found" : "Incorrect or missing parameter handling"
    );
    
    // Check GUID tracking
    $addsProcessedGuids = (preg_match("/guids_to_add/", $sumaiContent) || 
                          preg_match("/processed_guids/", $sumaiContent));
    
    output_test_result(
        "Processed GUIDs tracking in background action",
        $addsProcessedGuids,
        $addsProcessedGuids ? "GUID tracking implemented correctly" : "Missing GUID tracking functionality"
    );
    
    // Test Set 8: Tab Navigation in Admin
    output_header("8. Tab Navigation in Admin");
    
    $hasTabNavigation = preg_match("/\\\$\('\\.nav-tab'\).on\('click'/", $sumaiContent);
    output_test_result(
        "Tab navigation functionality",
        $hasTabNavigation,
        $hasTabNavigation ? "Tab navigation JavaScript implemented" : "Missing tab navigation JavaScript"
    );
    
    // Test Set 9: Version Number Check
    output_header("9. Version Number");
    
    $hasCorrectVersion = preg_match("/Version:\s*1\.3\.1/", $sumaiContent);
    output_test_result(
        "Version number is 1.3.1",
        $hasCorrectVersion,
        $hasCorrectVersion ? "Correct version number found: 1.3.1" : "Incorrect or missing version number"
    );
    
    // Calculate summary
    $totalTests = 9;
    $passedTests = 0;
    
    if ($all_files_exist) $passedTests++;
    if ($allFunctionsFound) $passedTests++;
    if ($allConstantsFound) $passedTests++;
    if ($correctScheduling) $passedTests++;
    if ($correctAvailabilityCheck) $passedTests++;
    if ($hasStatusUpdates) $passedTests++;
    if ($correctParameterHandling && $addsProcessedGuids) $passedTests++;
    if ($hasTabNavigation) $passedTests++;
    if ($hasCorrectVersion) $passedTests++;
    
    $passRate = round(($passedTests / $totalTests) * 100, 1);
    $passRateColor = $passRate == 100 ? 'green' : ($passRate >= 80 ? 'orange' : 'red');
    ?>
    
    <div class="summary">
        <h2>Summary</h2>
        <p><strong>Total Test Categories:</strong> <?php echo $totalTests; ?></p>
        <p><strong>Passed Test Categories:</strong> <?php echo $passedTests; ?></p>
        <p><strong>Failed Test Categories:</strong> <?php echo $totalTests - $passedTests; ?></p>
        <p class="pass-rate" style="color: <?php echo $passRateColor; ?>">
            Pass Rate: <?php echo $passRate; ?>%
        </p>
        
        <?php if ($passRate == 100): ?>
            <p><strong>Status:</strong> ✅ All tests passed! The Action Scheduler integration is working correctly.</p>
        <?php elseif ($passRate >= 80): ?>
            <p><strong>Status:</strong> ⚠️ Most tests passed, but some issues were detected. Review the failed tests above.</p>
        <?php else: ?>
            <p><strong>Status:</strong> ❌ Several critical issues were detected. The Action Scheduler integration may not work properly.</p>
        <?php endif; ?>
    </div>
    
    <p class="timestamp">Test executed on: <?php echo date('Y-m-d H:i:s T'); ?></p>
    
    <div class="recommendations">
        <h2>Recommendations</h2>
        <?php if ($passRate == 100): ?>
            <p>✅ The plugin is ready for deployment. Upload the following files to your WordPress plugins directory:</p>
            <ul>
                <?php foreach ($core_files as $file): ?>
                    <li><?php echo $file; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Please fix the issues highlighted above before deploying the plugin.</p>
        <?php endif; ?>
    </div>
</body>
</html>
