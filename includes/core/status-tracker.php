<?php
/**
 * Status tracking functionality for the Sumai plugin.
 * Handles tracking and reporting of background processing status.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates a unique status ID for tracking operations.
 * 
 * @return string Unique status ID.
 */
function sumai_generate_status_id(): string {
    return 'sumai_' . uniqid() . '_' . wp_rand(1000, 9999);
}

// Cache for statuses to reduce database calls
$GLOBALS['sumai_status_cache'] = null;

/**
 * Gets all statuses from cache or database.
 * 
 * @param bool $force_refresh Whether to force a refresh from the database.
 * @return array All statuses.
 */
function sumai_get_all_status_data(bool $force_refresh = false): array {
    // Return cached data if available and not forcing refresh
    if (!$force_refresh && $GLOBALS['sumai_status_cache'] !== null) {
        return $GLOBALS['sumai_status_cache'];
    }
    
    // Get from database
    $statuses = get_option(SUMAI_STATUS_OPTION, []);
    
    // Cache the result
    $GLOBALS['sumai_status_cache'] = $statuses;
    
    return $statuses;
}

/**
 * Updates the status of an operation.
 * 
 * @param string $status_id The status ID to update.
 * @param string $message Status message.
 * @param string $state Status state: 'pending', 'processing', 'complete', or 'error'.
 * @param array $data Additional data to store with status.
 * @return bool Whether the update was successful.
 */
function sumai_update_status(string $status_id, string $message, string $state = 'processing', array $data = []): bool {
    // Get existing statuses
    $statuses = sumai_get_all_status_data();
    
    // Create timestamp
    $timestamp = time();
    
    // Update status
    $statuses[$status_id] = [
        'id' => $status_id,
        'message' => $message,
        'state' => $state,
        'timestamp' => $timestamp,
        'time_formatted' => date('Y-m-d H:i:s', $timestamp),
        'data' => $data
    ];
    
    // Prune old statuses (older than 24 hours)
    $pruned = false;
    foreach ($statuses as $id => $status) {
        if ($status['timestamp'] < (time() - 86400)) {
            unset($statuses[$id]);
            $pruned = true;
        }
    }
    
    // Update cache
    $GLOBALS['sumai_status_cache'] = $statuses;
    
    // Save statuses
    $result = update_option(SUMAI_STATUS_OPTION, $statuses);
    
    // If we pruned a lot of statuses, consider running a full cleanup
    if ($pruned && count($statuses) > 100) {
        sumai_cleanup_statuses();
    }
    
    return $result;
}

/**
 * Gets the current status of an operation.
 * 
 * @param string $status_id The status ID to retrieve.
 * @return array|null The status data or null if not found.
 */
function sumai_get_status(string $status_id): ?array {
    $statuses = sumai_get_all_status_data();
    
    return isset($statuses[$status_id]) ? $statuses[$status_id] : null;
}

/**
 * Gets all tracked statuses, optionally filtered by state.
 * 
 * @param string|null $state Optional state to filter by.
 * @param int $limit Maximum number of statuses to return.
 * @return array Array of statuses, sorted by timestamp (newest first).
 */
function sumai_get_all_statuses(?string $state = null, int $limit = 10): array {
    $statuses = sumai_get_all_status_data();
    
    // Filter by state if specified
    if ($state !== null) {
        $statuses = array_filter($statuses, function($status) use ($state) {
            return $status['state'] === $state;
        });
    }
    
    // Sort by timestamp (newest first)
    uasort($statuses, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Limit the number of results
    return array_slice($statuses, 0, $limit);
}

/**
 * Updates multiple statuses in a single database operation.
 * 
 * @param array $status_updates Array of status updates, keyed by status ID.
 * @return bool Whether the update was successful.
 */
function sumai_update_multiple_statuses(array $status_updates): bool {
    if (empty($status_updates)) {
        return true;
    }
    
    // Get existing statuses
    $statuses = sumai_get_all_status_data();
    $timestamp = time();
    
    // Update each status
    foreach ($status_updates as $status_id => $update) {
        $message = $update['message'] ?? '';
        $state = $update['state'] ?? 'processing';
        $data = $update['data'] ?? [];
        
        $statuses[$status_id] = [
            'id' => $status_id,
            'message' => $message,
            'state' => $state,
            'timestamp' => $timestamp,
            'time_formatted' => date('Y-m-d H:i:s', $timestamp),
            'data' => $data
        ];
    }
    
    // Update cache
    $GLOBALS['sumai_status_cache'] = $statuses;
    
    // Save statuses
    return update_option(SUMAI_STATUS_OPTION, $statuses);
}

/**
 * Cleans up old statuses.
 * 
 * @param int $max_age Maximum age in seconds (default: 1 day).
 * @return int Number of statuses removed.
 */
function sumai_cleanup_statuses(int $max_age = 86400): int {
    $statuses = sumai_get_all_status_data(true); // Force refresh from database
    $count = count($statuses);
    
    // Filter out old statuses
    $statuses = array_filter($statuses, function($status) use ($max_age) {
        return $status['timestamp'] >= (time() - $max_age);
    });
    
    // Calculate how many were removed
    $removed = $count - count($statuses);
    
    // Save if any were removed
    if ($removed > 0) {
        // Update cache
        $GLOBALS['sumai_status_cache'] = $statuses;
        
        // Save to database
        update_option(SUMAI_STATUS_OPTION, $statuses);
    }
    
    return $removed;
}

/**
 * Invalidates the status cache, forcing the next call to fetch from the database.
 */
function sumai_invalidate_status_cache(): void {
    $GLOBALS['sumai_status_cache'] = null;
}