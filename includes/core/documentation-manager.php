<?php
/**
 * Documentation management functionality for the Sumai plugin.
 * Handles automatic updates, version tracking, and validation of documentation files.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Checks if a function exists before declaring it.
 * Prevents function redeclaration errors.
 */
if (!function_exists('sumai_update_documentation_timestamp')) {
    /**
     * Updates the timestamp in a documentation file.
     * 
     * @param string $file_path Path to the documentation file.
     * @param string $pattern Regex pattern to match the timestamp line.
     * @param string $replacement Replacement pattern with %s for the timestamp.
     * @return bool Whether the update was successful.
     */
    function sumai_update_documentation_timestamp($file_path, $pattern, $replacement) {
        if (!file_exists($file_path) || !is_writable($file_path)) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Documentation file not found or not writable: {$file_path}", true);
            }
            return false;
        }

        $content = file_get_contents($file_path);
        if ($content === false) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to read documentation file: {$file_path}", true);
            }
            return false;
        }

        $timestamp = current_time('Y-m-d\TH:i:s-04:00');
        $new_content = preg_replace($pattern, sprintf($replacement, $timestamp), $content);
        
        if ($new_content === $content) {
            // No changes needed
            return true;
        }

        $result = file_put_contents($file_path, $new_content);
        if ($result === false) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to update timestamp in documentation file: {$file_path}", true);
            }
            return false;
        }

        if (function_exists('sumai_log_event')) {
            sumai_log_event("Updated timestamp in documentation file: {$file_path}");
        }
        return true;
    }
}

if (!function_exists('sumai_update_task_status')) {
    /**
     * Updates the status of a task in TASKS.md.
     * 
     * @param string $task_id The ID of the task (e.g., "TASK-009").
     * @param string $sub_task The sub-task text to mark as completed.
     * @param bool $completed Whether to mark as completed or pending.
     * @return bool Whether the update was successful.
     */
    function sumai_update_task_status($task_id, $sub_task, $completed = true) {
        $file_path = SUMAI_PLUGIN_DIR . 'TASKS.md';
        
        if (!file_exists($file_path) || !is_writable($file_path)) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("TASKS.md file not found or not writable", true);
            }
            return false;
        }

        $content = file_get_contents($file_path);
        if ($content === false) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to read TASKS.md file", true);
            }
            return false;
        }

        // Escape special regex characters in the sub-task text
        $escaped_sub_task = preg_quote($sub_task, '/');
        
        // Create pattern to match the task line
        $pattern = '/(\s*- \[)([x ]?)(\] ' . $escaped_sub_task . ')/';
        
        // Create replacement with the appropriate status
        $mark = $completed ? 'x' : ' ';
        $replacement = '$1' . $mark . '$3';
        
        // Replace the task status
        $new_content = preg_replace($pattern, $replacement, $content);
        
        if ($new_content === $content) {
            // No changes made, task might not exist
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Task not found in TASKS.md: {$sub_task}", true);
            }
            return false;
        }

        // Update the "Last Updated" timestamp
        $timestamp_pattern = '/(- Last Update: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/';
        $timestamp = current_time('Y-m-d\TH:i:s-04:00');
        $new_content = preg_replace($timestamp_pattern, '$1' . $timestamp, $new_content);

        $result = file_put_contents($file_path, $new_content);
        if ($result === false) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to update task status in TASKS.md", true);
            }
            return false;
        }

        if (function_exists('sumai_log_event')) {
            $status = $completed ? "completed" : "pending";
            sumai_log_event("Updated task status to {$status} for {$task_id}: {$sub_task}");
        }
        return true;
    }
}

if (!function_exists('sumai_verify_all_files_committed')) {
    /**
     * Verifies that all files in the project are committed.
     * 
     * @return array Result with 'success' boolean and 'uncommitted_files' array.
     */
    function sumai_verify_all_files_committed() {
        $result = [
            'success' => true,
            'uncommitted_files' => []
        ];
        
        // Get the git status
        $output = [];
        $return_var = 0;
        exec('cd ' . SUMAI_PLUGIN_DIR . ' && git status --porcelain', $output, $return_var);
        
        if ($return_var !== 0) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to execute git status command", true);
            }
            $result['success'] = false;
            return $result;
        }
        
        // Process the output to find uncommitted files
        foreach ($output as $line) {
            // Skip .git directory files
            if (strpos($line, '.git/') !== false) {
                continue;
            }
            
            // Extract the file path
            $file_path = trim(substr($line, 3));
            if (!empty($file_path)) {
                $result['success'] = false;
                $result['uncommitted_files'][] = $file_path;
            }
        }
        
        if (!$result['success'] && function_exists('sumai_log_event')) {
            sumai_log_event("Uncommitted files found: " . implode(', ', $result['uncommitted_files']), true);
        }
        
        return $result;
    }
}

if (!function_exists('sumai_validate_documentation')) {
    /**
     * Validates documentation files for consistency and required elements.
     * 
     * @return array Validation results with 'valid' boolean and 'issues' array.
     */
    function sumai_validate_documentation() {
        $result = [
            'valid' => true,
            'issues' => []
        ];
        
        $files_to_check = [
            'README.md' => [
                'required_sections' => ['Features', 'Project Structure', 'Development Guidelines'],
                'version_pattern' => '/Version: ([0-9]+\.[0-9]+\.[0-9]+)/',
                'timestamp_pattern' => '/Last Updated: ([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/'
            ],
            'PLANNING.md' => [
                'required_sections' => ['Project Overview', 'Architecture', 'Components'],
                'version_pattern' => null,
                'timestamp_pattern' => '/Last Updated: ([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/'
            ],
            'TASKS.md' => [
                'required_sections' => ['Current Task', 'Next Tasks', 'Completed Tasks'],
                'version_pattern' => null,
                'timestamp_pattern' => '/- Last Update: ([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/'
            ],
            '.windsurfrules' => [
                'required_sections' => ['Core Behavior & Startup', 'Automated 3-Commit Cycle Workflow'],
                'version_pattern' => null,
                'timestamp_pattern' => '/Last Updated: ([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/'
            ]
        ];
        
        foreach ($files_to_check as $file => $checks) {
            $file_path = SUMAI_PLUGIN_DIR . $file;
            
            if (!file_exists($file_path)) {
                $result['valid'] = false;
                $result['issues'][] = "File not found: {$file}";
                continue;
            }
            
            $content = file_get_contents($file_path);
            if ($content === false) {
                $result['valid'] = false;
                $result['issues'][] = "Failed to read file: {$file}";
                continue;
            }
            
            // Check for required sections
            foreach ($checks['required_sections'] as $section) {
                if (strpos($content, $section) === false) {
                    $result['valid'] = false;
                    $result['issues'][] = "Missing required section '{$section}' in {$file}";
                }
            }
            
            // Check for version consistency if applicable
            if ($checks['version_pattern'] !== null) {
                preg_match($checks['version_pattern'], $content, $matches);
                if (empty($matches)) {
                    $result['valid'] = false;
                    $result['issues'][] = "Version information not found in {$file}";
                } else {
                    $file_version = $matches[1];
                    if ($file === 'README.md' && $file_version !== SUMAI_VERSION) {
                        $result['valid'] = false;
                        $result['issues'][] = "Version mismatch in {$file}: {$file_version} vs. " . SUMAI_VERSION;
                    }
                }
            }
            
            // Check for timestamp format if applicable
            if ($checks['timestamp_pattern'] !== null) {
                preg_match($checks['timestamp_pattern'], $content, $matches);
                if (empty($matches)) {
                    $result['valid'] = false;
                    $result['issues'][] = "Timestamp information not found in {$file}";
                } else {
                    $timestamp = $matches[1];
                    // Validate timestamp format
                    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$/', $timestamp)) {
                        $result['valid'] = false;
                        $result['issues'][] = "Invalid timestamp format in {$file}: {$timestamp}";
                    }
                }
            }
        }
        
        // Verify all files are committed
        $git_status = sumai_verify_all_files_committed();
        if (!$git_status['success']) {
            $result['valid'] = false;
            $result['issues'][] = "Uncommitted files found: " . implode(', ', $git_status['uncommitted_files']);
        }
        
        return $result;
    }
}

if (!function_exists('sumai_update_version_in_documentation')) {
    /**
     * Updates version numbers in documentation files to match the plugin version.
     * 
     * @return bool Whether all updates were successful.
     */
    function sumai_update_version_in_documentation() {
        $success = true;
        
        // Update README.md version
        $readme_path = SUMAI_PLUGIN_DIR . 'README.md';
        if (file_exists($readme_path) && is_writable($readme_path)) {
            $content = file_get_contents($readme_path);
            if ($content !== false) {
                $pattern = '/(Version: )([0-9]+\.[0-9]+\.[0-9]+)/';
                $replacement = '$1' . SUMAI_VERSION;
                $new_content = preg_replace($pattern, $replacement, $content);
                
                if ($new_content !== $content) {
                    $result = file_put_contents($readme_path, $new_content);
                    if ($result === false) {
                        $success = false;
                        if (function_exists('sumai_log_event')) {
                            sumai_log_event("Failed to update version in README.md", true);
                        }
                    } else if (function_exists('sumai_log_event')) {
                        sumai_log_event("Updated version in README.md to " . SUMAI_VERSION);
                    }
                }
            }
        }
        
        return $success;
    }
}

if (!function_exists('sumai_update_all_documentation_timestamps')) {
    /**
     * Updates timestamps in all documentation files.
     * 
     * @return bool Whether all updates were successful.
     */
    function sumai_update_all_documentation_timestamps() {
        $success = true;
        
        // Update README.md
        $readme_path = SUMAI_PLUGIN_DIR . 'README.md';
        $readme_pattern = '/(Last Updated: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/';
        $readme_replacement = '$1%s';
        $success = sumai_update_documentation_timestamp($readme_path, $readme_pattern, $readme_replacement) && $success;
        
        // Update PLANNING.md
        $planning_path = SUMAI_PLUGIN_DIR . 'PLANNING.md';
        $planning_pattern = '/(Last Updated: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/';
        $planning_replacement = '$1%s';
        $success = sumai_update_documentation_timestamp($planning_path, $planning_pattern, $planning_replacement) && $success;
        
        // Update TASKS.md - this already has timestamp updates in sumai_update_task_status
        // But we'll add a general update for when no specific task is updated
        $tasks_path = SUMAI_PLUGIN_DIR . 'TASKS.md';
        $tasks_pattern = '/(- Last Update: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/';
        $tasks_replacement = '$1%s';
        $success = sumai_update_documentation_timestamp($tasks_path, $tasks_pattern, $tasks_replacement) && $success;
        
        // Update .windsurfrules if it exists and has a timestamp field
        $rules_path = SUMAI_PLUGIN_DIR . '.windsurfrules';
        if (file_exists($rules_path)) {
            $rules_pattern = '/(Last Updated: )([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2})/';
            $rules_replacement = '$1%s';
            $success = sumai_update_documentation_timestamp($rules_path, $rules_pattern, $rules_replacement) && $success;
        }
        
        if (function_exists('sumai_log_event')) {
            if ($success) {
                sumai_log_event("Updated timestamps in all documentation files");
            } else {
                sumai_log_event("Failed to update timestamps in some documentation files", true);
            }
        }
        
        return $success;
    }
}

if (!function_exists('sumai_register_documentation_hooks')) {
    /**
     * Registers hooks for automatic documentation updates.
     */
    function sumai_register_documentation_hooks() {
        // Update documentation when plugin settings are changed
        add_action('update_option_sumai_settings', 'sumai_update_documentation_on_settings_change', 10, 3);
        
        // Update documentation version when plugin is activated
        add_action('sumai_after_activation', 'sumai_update_version_in_documentation');
        
        // Run documentation validation periodically
        add_action('admin_init', 'sumai_run_documentation_validation');
        
        // Update all documentation timestamps daily using WordPress cron
        if (!wp_next_scheduled('sumai_update_documentation_timestamps')) {
            wp_schedule_event(time(), 'daily', 'sumai_update_documentation_timestamps');
        }
        add_action('sumai_update_documentation_timestamps', 'sumai_update_all_documentation_timestamps');
        
        // Also update timestamps when a post is published or updated
        add_action('publish_post', 'sumai_update_all_documentation_timestamps');
        add_action('edit_post', 'sumai_update_all_documentation_timestamps');
    }
}

if (!function_exists('sumai_update_documentation_on_settings_change')) {
    /**
     * Updates documentation timestamps when plugin settings are changed.
     * 
     * @param mixed $old_value The old option value.
     * @param mixed $new_value The new option value.
     * @param string $option The option name.
     */
    function sumai_update_documentation_on_settings_change($old_value, $new_value, $option) {
        if ($option !== 'sumai_settings') {
            return;
        }
        
        // Update all documentation timestamps
        sumai_update_all_documentation_timestamps();
        
        // If version changed, also update version references
        if (isset($old_value['version']) && isset($new_value['version']) && $old_value['version'] !== $new_value['version']) {
            sumai_update_version_in_documentation();
        }
    }
}

if (!function_exists('sumai_run_documentation_validation')) {
    /**
     * Runs documentation validation and logs any issues.
     */
    function sumai_run_documentation_validation() {
        $validation = sumai_validate_documentation();
        
        if (!$validation['valid']) {
            if (function_exists('sumai_log_event')) {
                foreach ($validation['issues'] as $issue) {
                    sumai_log_event("Documentation validation issue: {$issue}", true);
                }
            }
            
            // Add admin notice for documentation issues
            add_action('admin_notices', function() use ($validation) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><strong>Sumai Documentation Issues:</strong></p>
                    <ul>
                        <?php foreach ($validation['issues'] as $issue): ?>
                            <li><?php echo esc_html($issue); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php
            });
        }
        
        // Also verify all files are committed
        $git_status = sumai_verify_all_files_committed();
        if (!$git_status['success']) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("WARNING: Uncommitted files found: " . implode(', ', $git_status['uncommitted_files']), true);
            }
            
            // Add admin notice for uncommitted files
            add_action('admin_notices', function() use ($git_status) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Sumai Uncommitted Files Detected:</strong></p>
                    <p>The following files have changes that are not committed:</p>
                    <ul>
                        <?php foreach ($git_status['uncommitted_files'] as $file): ?>
                            <li><?php echo esc_html($file); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>Please commit these files before proceeding with further tasks.</p>
                </div>
                <?php
            });
        }
    }
}

// Register documentation hooks if function checker is available
if (function_exists('sumai_function_not_exists') && sumai_function_not_exists('sumai_register_documentation_hooks')) {
    sumai_register_documentation_hooks();
}
