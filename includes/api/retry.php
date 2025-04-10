<?php
/**
 * Retry mechanism for API calls in the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Executes a callback function with retry logic.
 * 
 * @param callable $callback The function to execute.
 * @param array    $args Arguments to pass to the callback.
 * @param int      $max_attempts Maximum number of retry attempts.
 * @param int      $initial_delay Initial delay in seconds before first retry.
 * @param float    $backoff_factor Factor to increase delay between retries.
 * @param array    $retry_on_errors Array of error codes or messages to retry on.
 * @return mixed|null The callback result or null on failure.
 */
function sumai_with_retry(
    callable $callback,
    array $args = [],
    int $max_attempts = 3,
    int $initial_delay = 2,
    float $backoff_factor = 1.5,
    array $retry_on_errors = []
) {
    $attempt = 1;
    $delay = $initial_delay;
    $last_error = null;
    
    while ($attempt <= $max_attempts) {
        try {
            // Execute the callback
            $result = call_user_func_array($callback, $args);
            
            // If we got a WP_Error, handle it
            if (is_wp_error($result)) {
                $error_code = $result->get_error_code();
                $error_message = $result->get_error_message();
                
                // Log the error
                sumai_handle_error(
                    "API call failed (attempt {$attempt}/{$max_attempts}): {$error_message}",
                    SUMAI_ERROR_API,
                    ['code' => $error_code, 'attempt' => $attempt]
                );
                
                // Check if we should retry based on error
                $should_retry = empty($retry_on_errors) || 
                               in_array($error_code, $retry_on_errors) || 
                               $attempt < $max_attempts;
                
                if (!$should_retry || $attempt >= $max_attempts) {
                    return $result; // Return the error if we shouldn't retry
                }
                
                $last_error = $result;
            } 
            // If the result is null or false, consider it an error
            elseif ($result === null || $result === false) {
                // Log the error
                sumai_handle_error(
                    "API call returned null/false (attempt {$attempt}/{$max_attempts})",
                    SUMAI_ERROR_API,
                    ['attempt' => $attempt]
                );
                
                if ($attempt >= $max_attempts) {
                    return $result;
                }
                
                $last_error = new WP_Error('api_null_response', 'API call returned null or false');
            }
            // Success
            else {
                // If this was a retry, log the recovery
                if ($attempt > 1) {
                    sumai_log_event("API call succeeded after {$attempt} attempts");
                }
                return $result;
            }
        } catch (Exception $e) {
            // Log the exception
            sumai_handle_error(
                "Exception in API call (attempt {$attempt}/{$max_attempts}): " . $e->getMessage(),
                SUMAI_ERROR_API,
                ['exception' => get_class($e), 'attempt' => $attempt]
            );
            
            if ($attempt >= $max_attempts) {
                return null;
            }
            
            $last_error = $e;
        }
        
        // Increase attempt counter
        $attempt++;
        
        // Only sleep if we're going to retry
        if ($attempt <= $max_attempts) {
            // Log retry attempt
            sumai_log_event("Retrying API call in {$delay} seconds (attempt {$attempt}/{$max_attempts})");
            
            // Sleep with exponential backoff
            sleep($delay);
            $delay = (int)($delay * $backoff_factor);
        }
    }
    
    // If we get here, all attempts failed
    sumai_handle_error(
        "All retry attempts failed for API call",
        SUMAI_ERROR_API,
        ['max_attempts' => $max_attempts, 'last_error' => $last_error instanceof Exception ? $last_error->getMessage() : 'Unknown error'],
        true // Notify admin about repeated failures
    );
    
    return null;
}
