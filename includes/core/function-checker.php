<?php
/**
 * Function existence checker for the Sumai plugin.
 * Provides utilities to prevent duplicate function declarations.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Checks if a function exists and logs a warning if it does.
 * Use this before declaring critical functions to prevent fatal errors.
 *
 * @param string $function_name The name of the function to check.
 * @param bool $log_warning Whether to log a warning if the function exists.
 * @return bool True if the function doesn't exist, false if it already exists.
 */
function sumai_function_not_exists($function_name, $log_warning = true) {
    $function_exists = function_exists($function_name);
    
    if ($function_exists && $log_warning && function_exists('sumai_log_event')) {
        sumai_log_event("Warning: Function {$function_name} already exists. Skipping redeclaration to prevent fatal errors.", true);
    }
    
    return !$function_exists;
}

/**
 * Safely executes a function if it exists.
 * Provides a fallback value if the function doesn't exist.
 *
 * @param string $function_name The name of the function to execute.
 * @param array $args Arguments to pass to the function.
 * @param mixed $fallback_value Value to return if the function doesn't exist.
 * @param bool $log_missing Whether to log a warning if the function doesn't exist.
 * @return mixed The function result or fallback value.
 */
function sumai_safe_function_call($function_name, $args = [], $fallback_value = null, $log_missing = true) {
    if (function_exists($function_name)) {
        return call_user_func_array($function_name, $args);
    }
    
    if ($log_missing && function_exists('sumai_log_event')) {
        sumai_log_event("Warning: Function {$function_name} does not exist. Using fallback value.", true);
    }
    
    return $fallback_value;
}

/**
 * Registers a function with the Sumai function registry.
 * This helps track which functions are available and when they were registered.
 *
 * @param string $function_name The name of the function being registered.
 * @param string $file The file where the function is defined.
 * @param array $dependencies Array of function names this function depends on.
 * @return bool True if registration was successful, false otherwise.
 */
function sumai_register_function($function_name, $file, $dependencies = []) {
    static $registered_functions = [];
    
    // Check if function already registered
    if (isset($registered_functions[$function_name])) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event("Warning: Function {$function_name} already registered from file {$registered_functions[$function_name]['file']}", true);
        }
        return false;
    }
    
    // Register the function
    $registered_functions[$function_name] = [
        'file' => $file,
        'time' => microtime(true),
        'dependencies' => $dependencies
    ];
    
    return true;
}

/**
 * Gets the list of registered functions.
 *
 * @return array Associative array of registered functions.
 */
function sumai_get_registered_functions() {
    static $registered_functions = [];
    return $registered_functions;
}

/**
 * Checks if all dependencies for a function are available.
 *
 * @param array $dependencies Array of function names to check.
 * @return bool True if all dependencies exist, false otherwise.
 */
function sumai_check_function_dependencies($dependencies) {
    if (empty($dependencies)) {
        return true;
    }
    
    $missing = [];
    foreach ($dependencies as $function) {
        if (!function_exists($function)) {
            $missing[] = $function;
        }
    }
    
    if (!empty($missing) && function_exists('sumai_log_event')) {
        sumai_log_event("Missing function dependencies: " . implode(', ', $missing), true);
    }
    
    return empty($missing);
}
