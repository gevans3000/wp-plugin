<?php
/**
 * Action Scheduler integration for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Loads Action Scheduler if needed.
 */
function sumai_maybe_load_action_scheduler() {
    // Only define if the function doesn't already exist
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_maybe_load_action_scheduler', false)) {
        return sumai_check_action_scheduler();
    }
    
    $action_scheduler_available = sumai_check_action_scheduler();
    
    // Log the result of the check
    if (!$action_scheduler_available && function_exists('sumai_log_event')) {
        sumai_log_event('Action Scheduler is not available. Background processing will be limited.', true);
    }
    
    return $action_scheduler_available;
}

/**
 * Checks if Action Scheduler is available and loads it if needed.
 * 
 * @return bool True if Action Scheduler is available, false otherwise.
 */
function sumai_check_action_scheduler(): bool {
    // Only define if the function doesn't already exist
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_check_action_scheduler', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Return a safe fallback result
        return function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action');
    }
    
    // First check if Action Scheduler functions are already available
    if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
        return true;
    }
    
    // Don't try to load Action Scheduler during plugin activation
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return false;
    }
    
    // Check for WooCommerce's Action Scheduler
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php')) {
        include_once WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php';
        return function_exists('as_schedule_single_action');
    }
    
    // Check for standalone Action Scheduler plugin
    if (file_exists(WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php')) {
        include_once WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php';
        return function_exists('as_schedule_single_action');
    }
    
    return false;
}

/**
 * Get detailed information about Action Scheduler availability.
 * 
 * @return array Information about Action Scheduler availability.
 */
function sumai_get_action_scheduler_info(): array {
    // Only define if the function doesn't already exist
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_get_action_scheduler_info', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Return a safe fallback result
        return [
            'available' => function_exists('as_schedule_single_action'),
            'source' => 'unknown',
            'version' => 'unknown',
            'functions_available' => function_exists('as_schedule_single_action')
        ];
    }
    
    $info = [
        'available' => false,
        'source' => 'none',
        'version' => 'unknown',
        'functions_available' => false,
    ];
    
    // Check if Action Scheduler functions are already available
    if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
        $info['available'] = true;
        $info['functions_available'] = true;
        
        // Try to determine the source and version
        if (class_exists('ActionScheduler_Versions')) {
            $info['version'] = ActionScheduler_Versions::instance()->latest_version();
            $info['source'] = 'unknown_integrated';
        }
    }
    
    // Check for WooCommerce's Action Scheduler
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php')) {
        $info['source'] = 'woocommerce';
        if (!$info['available']) {
            // Only include if not already available
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/libraries/action-scheduler/action-scheduler.php';
            $info['available'] = function_exists('as_schedule_single_action');
            $info['functions_available'] = $info['available'];
            
            if (class_exists('ActionScheduler_Versions')) {
                $info['version'] = ActionScheduler_Versions::instance()->latest_version();
            }
        }
    }
    
    // Check for standalone Action Scheduler plugin
    if (file_exists(WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php') && !$info['available']) {
        $info['source'] = 'standalone';
        if (!$info['available']) {
            // Only include if not already available
            include_once WP_PLUGIN_DIR . '/action-scheduler/action-scheduler.php';
            $info['available'] = function_exists('as_schedule_single_action');
            $info['functions_available'] = $info['available'];
            
            if (class_exists('ActionScheduler_Versions')) {
                $info['version'] = ActionScheduler_Versions::instance()->latest_version();
            }
        }
    }
    
    return $info;
}

/**
 * Register Action Scheduler hooks for async processing
 */
function sumai_register_scheduled_actions() {
    // Only define if the function doesn't already exist
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_register_scheduled_actions', false)) {
        return;
    }
    
    // Register the action handlers
    add_action(SUMAI_PROCESS_CONTENT_ACTION, 'sumai_process_content_action', 10, 1);
    add_action(SUMAI_RETRY_ACTION, 'sumai_retry_content_action', 10, 1);
}

/**
 * Process content in the background using Action Scheduler.
 * 
 * @param array $args Arguments for the background process
 * @return bool Success or failure
 */
function sumai_process_content_action($args) {
    // Check if the function is already defined elsewhere to prevent duplicate declaration
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_process_content_action', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Call the existing function if it exists, otherwise return false
        if (function_exists('sumai_safe_function_call')) {
            return sumai_safe_function_call('sumai_process_content_action', [$args], false);
        }
        return false;
    }
    
    // Extract arguments
    $content = $args['content'] ?? '';
    $context_prompt = $args['context_prompt'] ?? '';
    $title_prompt = $args['title_prompt'] ?? '';
    $draft_mode = $args['draft_mode'] ?? false;
    $post_signature = $args['post_signature'] ?? '';
    $articles = $args['articles'] ?? [];
    $status_id = $args['status_id'] ?? '';
    $retry_count = $args['retry_count'] ?? 0;
    $feed_url = $args['feed_url'] ?? 'Unknown feed';
    
    if (function_exists('sumai_log_event')) {
        sumai_log_event("Processing content from feed: $feed_url" . ($retry_count > 0 ? " (Retry attempt: $retry_count)" : ""));
    }
    
    try {
        // Use the helper function to process the content
        if (function_exists('sumai_process_content_helper')) {
            $result = sumai_process_content_helper(
                $content,
                $context_prompt,
                $title_prompt,
                $draft_mode,
                $post_signature,
                $articles,
                $status_id
            );
            
            if ($result) {
                if (function_exists('sumai_log_event')) {
                    sumai_log_event("Successfully processed content from feed: $feed_url");
                }
                return true;
            } else {
                // Handle failure with retry
                return sumai_handle_processing_failure($args, "Content processing failed for feed: $feed_url");
            }
        } else {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Error: sumai_process_content_helper function not found", true);
            }
            return false;
        }
    } catch (Exception $e) {
        // Handle exceptions with retry
        return sumai_handle_processing_failure($args, "Exception during content processing for feed: $feed_url - " . $e->getMessage());
    }
}

/**
 * Retry handler for failed content processing.
 * 
 * @param array $args Arguments for the retry process
 * @return bool Success or failure
 */
function sumai_retry_content_action($args) {
    // Check if the function is already defined elsewhere
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_retry_content_action', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Call the existing function if it exists, otherwise return false
        if (function_exists('sumai_safe_function_call')) {
            return sumai_safe_function_call('sumai_retry_content_action', [$args], false);
        }
        return false;
    }
    
    // Just pass to the main handler, it will handle retry count internally
    return sumai_process_content_action($args);
}

/**
 * Handles failure of content processing with retry logic.
 * 
 * @param array $args Arguments from the original process
 * @param string $error_message Error message to log
 * @return bool Always returns false to indicate the current attempt failed
 */
function sumai_handle_processing_failure($args, $error_message) {
    // Check if the function is already defined elsewhere
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_handle_processing_failure', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Call the existing function if it exists, otherwise return false
        if (function_exists('sumai_safe_function_call')) {
            return sumai_safe_function_call('sumai_handle_processing_failure', [$args, $error_message], false);
        }
        return false;
    }
    
    $retry_count = isset($args['retry_count']) ? intval($args['retry_count']) : 0;
    $status_id = $args['status_id'] ?? '';
    $feed_url = $args['feed_url'] ?? 'Unknown feed';
    
    // Log the error
    if (function_exists('sumai_log_event')) {
        sumai_log_event($error_message, true);
    }
    
    // Check if we should retry
    if ($retry_count < SUMAI_MAX_RETRIES) {
        // Increment retry count
        $args['retry_count'] = $retry_count + 1;
        
        // Calculate delay with exponential backoff (5min, 10min, 15min)
        $delay = SUMAI_RETRY_DELAY * $args['retry_count'];
        
        // Schedule retry
        if (function_exists('as_schedule_single_action')) {
            $scheduled = as_schedule_single_action(
                time() + $delay,
                SUMAI_RETRY_ACTION,
                [$args],
                'sumai'
            );
            
            if ($scheduled) {
                if (function_exists('sumai_log_event')) {
                    sumai_log_event("Scheduled retry #{$args['retry_count']} for feed: $feed_url in $delay seconds");
                }
                
                // Update status if we have a status ID
                if (!empty($status_id) && function_exists('sumai_update_status')) {
                    sumai_update_status(
                        $status_id,
                        "Processing failed, retry #{$args['retry_count']} scheduled in " . ($delay / 60) . " minutes",
                        'retry',
                        [
                            'retry_count' => $args['retry_count'],
                            'retry_delay' => $delay,
                            'feed_url' => $feed_url
                        ]
                    );
                }
                
                return false; // Current attempt failed, but retry scheduled
            }
        }
    }
    
    // If we get here, we've either exceeded max retries or failed to schedule a retry
    if (!empty($status_id) && function_exists('sumai_update_status')) {
        if ($retry_count >= SUMAI_MAX_RETRIES) {
            sumai_update_status(
                $status_id,
                "Processing failed after $retry_count retries: $error_message",
                'error',
                ['feed_url' => $feed_url]
            );
        } else {
            sumai_update_status(
                $status_id,
                "Processing failed and unable to schedule retry: $error_message",
                'error',
                ['feed_url' => $feed_url]
            );
        }
    }
    
    return false;
}

/**
 * Schedule content processing for background execution
 * 
 * @param array $args Arguments for the background process
 * @return int|bool The action ID or false on failure
 */
function sumai_schedule_content_processing($args) {
    // Check if the function is already defined elsewhere
    if (function_exists('sumai_function_not_exists') && !sumai_function_not_exists('sumai_schedule_content_processing', false)) {
        // If we're here, the function exists but we're trying to redeclare it
        // Call the existing function if it exists, otherwise return false
        if (function_exists('sumai_safe_function_call')) {
            return sumai_safe_function_call('sumai_schedule_content_processing', [$args], false);
        }
        return false;
    }
    
    // Ensure Action Scheduler is available
    if (!function_exists('as_schedule_single_action')) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event('Cannot schedule content processing: Action Scheduler not available', true);
        }
        return false;
    }
    
    // Required fields
    if (empty($args['content']) || empty($args['context_prompt']) || empty($args['title_prompt'])) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event('Cannot schedule content processing: Missing required arguments', true);
        }
        return false;
    }
    
    // Set default values for optional arguments
    $args = wp_parse_args($args, [
        'draft_mode' => false,
        'post_signature' => '',
        'articles' => [],
        'status_id' => '',
        'retry_count' => 0,
        'feed_url' => 'Unknown feed'
    ]);
    
    // Generate a unique group ID for this batch
    $group = 'sumai_content_' . md5(json_encode($args) . time());
    
    try {
        // Schedule the action
        $action_id = as_schedule_single_action(
            time(), // Run immediately
            SUMAI_PROCESS_CONTENT_ACTION,
            [$args],
            'sumai'
        );
        
        if ($action_id) {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Scheduled content processing for feed: {$args['feed_url']} (Action ID: $action_id)");
            }
            return $action_id;
        } else {
            if (function_exists('sumai_log_event')) {
                sumai_log_event("Failed to schedule content processing for feed: {$args['feed_url']}", true);
            }
            return false;
        }
    } catch (Exception $e) {
        if (function_exists('sumai_log_event')) {
            sumai_log_event("Exception scheduling content processing: " . $e->getMessage(), true);
        }
        return false;
    }
}