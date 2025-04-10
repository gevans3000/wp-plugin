<?php
/**
 * Retry Manager for handling failed operations.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages retry attempts for various operations.
 * This class provides a more advanced retry mechanism with circuit breaker pattern.
 */
class Sumai_Retry_Manager {
    /**
     * Retry configuration by operation type.
     *
     * @var array
     */
    private static $retry_config = [
        'api' => [
            'max_attempts' => 3,
            'initial_delay' => 2,
            'backoff_factor' => 1.5,
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout' => 300, // 5 minutes
        ],
        'feed' => [
            'max_attempts' => 2,
            'initial_delay' => 5,
            'backoff_factor' => 2,
            'circuit_breaker_threshold' => 3,
            'circuit_breaker_timeout' => 600, // 10 minutes
        ],
        'default' => [
            'max_attempts' => 2,
            'initial_delay' => 1,
            'backoff_factor' => 2,
            'circuit_breaker_threshold' => 3,
            'circuit_breaker_timeout' => 300, // 5 minutes
        ],
    ];

    /**
     * Circuit breaker state.
     *
     * @var array
     */
    private static $circuit_breakers = [];

    /**
     * Executes a function with retry logic.
     *
     * @param callable $callback Function to execute.
     * @param array    $args Arguments to pass to the callback.
     * @param string   $operation_type Type of operation (api, feed, default).
     * @param string   $operation_id Unique identifier for the operation.
     * @return mixed|null Result of the callback or null on failure.
     */
    public static function execute_with_retry($callback, $args = [], $operation_type = 'default', $operation_id = '') {
        // Generate operation ID if not provided
        if (empty($operation_id)) {
            $operation_id = md5(serialize($callback) . serialize($args));
        }

        // Check if circuit breaker is open
        if (self::is_circuit_open($operation_type, $operation_id)) {
            sumai_handle_error(
                "Circuit breaker open for {$operation_type} operation: {$operation_id}",
                SUMAI_ERROR_SYSTEM,
                ['operation_type' => $operation_type, 'operation_id' => $operation_id]
            );
            return null;
        }

        // Get retry configuration
        $config = self::get_retry_config($operation_type);
        
        // Execute with retry
        $result = self::do_retry($callback, $args, $config, $operation_type, $operation_id);
        
        return $result;
    }

    /**
     * Performs the actual retry logic.
     *
     * @param callable $callback Function to execute.
     * @param array    $args Arguments to pass to the callback.
     * @param array    $config Retry configuration.
     * @param string   $operation_type Type of operation.
     * @param string   $operation_id Unique identifier for the operation.
     * @return mixed|null Result of the callback or null on failure.
     */
    private static function do_retry($callback, $args, $config, $operation_type, $operation_id) {
        $attempt = 1;
        $delay = $config['initial_delay'];
        $last_error = null;
        
        while ($attempt <= $config['max_attempts']) {
            try {
                // Execute the callback
                $result = call_user_func_array($callback, $args);
                
                // Handle WP_Error results
                if (is_wp_error($result)) {
                    $error_code = $result->get_error_code();
                    $error_message = $result->get_error_message();
                    
                    sumai_handle_error(
                        "Operation failed (attempt {$attempt}/{$config['max_attempts']}): {$error_message}",
                        SUMAI_ERROR_API,
                        ['code' => $error_code, 'attempt' => $attempt, 'operation_type' => $operation_type]
                    );
                    
                    if ($attempt >= $config['max_attempts']) {
                        self::increment_failure_count($operation_type, $operation_id);
                        return $result;
                    }
                    
                    $last_error = $result;
                } 
                // Handle null/false results
                elseif ($result === null || $result === false) {
                    sumai_handle_error(
                        "Operation returned null/false (attempt {$attempt}/{$config['max_attempts']})",
                        SUMAI_ERROR_SYSTEM,
                        ['attempt' => $attempt, 'operation_type' => $operation_type]
                    );
                    
                    if ($attempt >= $config['max_attempts']) {
                        self::increment_failure_count($operation_type, $operation_id);
                        return $result;
                    }
                    
                    $last_error = new WP_Error('operation_null_response', 'Operation returned null or false');
                }
                // Success
                else {
                    // Reset failure count on success
                    self::reset_failure_count($operation_type, $operation_id);
                    
                    // Log recovery if this was a retry
                    if ($attempt > 1) {
                        sumai_log_event("Operation succeeded after {$attempt} attempts");
                    }
                    
                    return $result;
                }
            } catch (Exception $e) {
                sumai_handle_error(
                    "Exception in operation (attempt {$attempt}/{$config['max_attempts']}): " . $e->getMessage(),
                    SUMAI_ERROR_SYSTEM,
                    ['exception' => get_class($e), 'attempt' => $attempt, 'operation_type' => $operation_type]
                );
                
                if ($attempt >= $config['max_attempts']) {
                    self::increment_failure_count($operation_type, $operation_id);
                    return null;
                }
                
                $last_error = $e;
            }
            
            // Increment attempt counter
            $attempt++;
            
            // Only sleep if we're going to retry
            if ($attempt <= $config['max_attempts']) {
                sumai_log_event("Retrying operation in {$delay} seconds (attempt {$attempt}/{$config['max_attempts']})");
                
                // Sleep with exponential backoff
                sleep($delay);
                $delay = (int)($delay * $config['backoff_factor']);
            }
        }
        
        // All attempts failed
        sumai_handle_error(
            "All retry attempts failed for {$operation_type} operation",
            SUMAI_ERROR_SYSTEM,
            [
                'max_attempts' => $config['max_attempts'], 
                'operation_type' => $operation_type,
                'last_error' => $last_error instanceof Exception ? $last_error->getMessage() : 'Unknown error'
            ],
            true // Notify admin about repeated failures
        );
        
        return null;
    }

    /**
     * Gets retry configuration for an operation type.
     *
     * @param string $operation_type Type of operation.
     * @return array Retry configuration.
     */
    private static function get_retry_config($operation_type) {
        if (isset(self::$retry_config[$operation_type])) {
            return self::$retry_config[$operation_type];
        }
        
        return self::$retry_config['default'];
    }

    /**
     * Checks if circuit breaker is open for an operation.
     *
     * @param string $operation_type Type of operation.
     * @param string $operation_id Unique identifier for the operation.
     * @return bool Whether the circuit is open.
     */
    private static function is_circuit_open($operation_type, $operation_id) {
        $key = $operation_type . '_' . $operation_id;
        
        // Check if circuit breaker exists
        if (!isset(self::$circuit_breakers[$key])) {
            return false;
        }
        
        $circuit = self::$circuit_breakers[$key];
        
        // Check if circuit is open and timeout has expired
        if ($circuit['status'] === 'open' && time() > $circuit['timeout']) {
            // Reset to half-open state
            self::$circuit_breakers[$key]['status'] = 'half-open';
            return false;
        }
        
        // Return true if circuit is open
        return $circuit['status'] === 'open';
    }

    /**
     * Increments failure count for an operation.
     *
     * @param string $operation_type Type of operation.
     * @param string $operation_id Unique identifier for the operation.
     */
    private static function increment_failure_count($operation_type, $operation_id) {
        $key = $operation_type . '_' . $operation_id;
        $config = self::get_retry_config($operation_type);
        
        // Initialize circuit breaker if it doesn't exist
        if (!isset(self::$circuit_breakers[$key])) {
            self::$circuit_breakers[$key] = [
                'failures' => 0,
                'status' => 'closed',
                'timeout' => 0,
            ];
        }
        
        // Increment failure count
        self::$circuit_breakers[$key]['failures']++;
        
        // Check if threshold is reached
        if (self::$circuit_breakers[$key]['failures'] >= $config['circuit_breaker_threshold']) {
            // Open circuit
            self::$circuit_breakers[$key]['status'] = 'open';
            self::$circuit_breakers[$key]['timeout'] = time() + $config['circuit_breaker_timeout'];
            
            // Log circuit breaker open
            sumai_handle_error(
                "Circuit breaker opened for {$operation_type} operation: {$operation_id}",
                SUMAI_ERROR_SYSTEM,
                [
                    'operation_type' => $operation_type,
                    'operation_id' => $operation_id,
                    'failures' => self::$circuit_breakers[$key]['failures'],
                    'timeout' => $config['circuit_breaker_timeout']
                ],
                true // Notify admin
            );
        }
    }

    /**
     * Resets failure count for an operation.
     *
     * @param string $operation_type Type of operation.
     * @param string $operation_id Unique identifier for the operation.
     */
    private static function reset_failure_count($operation_type, $operation_id) {
        $key = $operation_type . '_' . $operation_id;
        
        // Reset circuit breaker
        self::$circuit_breakers[$key] = [
            'failures' => 0,
            'status' => 'closed',
            'timeout' => 0,
        ];
        
        // Log circuit breaker reset if it was previously open or half-open
        if (isset(self::$circuit_breakers[$key]) && 
            (self::$circuit_breakers[$key]['status'] === 'open' || 
             self::$circuit_breakers[$key]['status'] === 'half-open')) {
            
            sumai_log_event("Circuit breaker reset for {$operation_type} operation: {$operation_id}");
        }
    }
}

/**
 * Wrapper function for Sumai_Retry_Manager::execute_with_retry.
 *
 * @param callable $callback Function to execute.
 * @param array    $args Arguments to pass to the callback.
 * @param string   $operation_type Type of operation (api, feed, default).
 * @param string   $operation_id Unique identifier for the operation.
 * @return mixed|null Result of the callback or null on failure.
 */
function sumai_retry_operation($callback, $args = [], $operation_type = 'default', $operation_id = '') {
    return Sumai_Retry_Manager::execute_with_retry($callback, $args, $operation_type, $operation_id);
}
