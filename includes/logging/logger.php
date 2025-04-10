<?php
/**
 * Logging functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Ensures the log directory exists and is writable.
 * 
 * @return bool Whether the log directory is ready.
 */
function sumai_ensure_log_dir() {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . SUMAI_LOG_DIR_NAME;
    
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    
    // Create .htaccess file to prevent direct access
    $htaccess_file = trailingslashit($log_dir) . '.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "# Disable directory browsing\nOptions -Indexes\n\n# Deny access to all files\n<FilesMatch \".*\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>";
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    return is_dir($log_dir) && is_writable($log_dir);
}

/**
 * Logs an event to the log file.
 * 
 * @param string $msg The message to log.
 * @param bool $is_error Whether this is an error message.
 * @return bool Whether the message was logged successfully.
 */
function sumai_log_event(string $msg, bool $is_error = false) {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . SUMAI_LOG_DIR_NAME;
    $log_file = trailingslashit($log_dir) . SUMAI_LOG_FILE_NAME;
    
    if (!is_dir($log_dir)) {
        sumai_ensure_log_dir();
    }
    
    $time = current_time('mysql', true);
    $level = $is_error ? 'ERROR' : 'INFO';
    $log_line = sprintf("[%s] [%s]  %s\n", $time, $level, $msg);
    
    return file_put_contents($log_file, $log_line, FILE_APPEND);
}

/**
 * Prunes log files older than the retention period.
 * 
 * @return int Number of files deleted.
 */
function sumai_prune_logs() {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . SUMAI_LOG_DIR_NAME;
    
    if (!is_dir($log_dir)) {
        return 0;
    }
    
    $files = glob(trailingslashit($log_dir) . '*.log');
    $now = time();
    $pruned = 0;
    
    foreach ($files as $file) {
        // Skip the current log file
        if (basename($file) === SUMAI_LOG_FILE_NAME) {
            continue;
        }
        
        // Delete files older than retention period
        if ($now - filemtime($file) > SUMAI_LOG_TTL) {
            if (unlink($file)) {
                $pruned++;
            }
        }
    }
    
    // If the main log file is too large, archive it
    $main_log = trailingslashit($log_dir) . SUMAI_LOG_FILE_NAME;
    if (file_exists($main_log) && filesize($main_log) > 5 * 1024 * 1024) { // 5MB
        $archive_name = trailingslashit($log_dir) . 'sumai_' . date('Y-m-d_H-i-s') . '.log';
        rename($main_log, $archive_name);
    }
    
    return $pruned;
}

/**
 * Reads the last N lines from a file.
 * 
 * @param string $filepath Path to the file.
 * @param int    $lines    Number of lines to read from the tail.
 * @param int    $buffer   Buffer size for reading chunks.
 * @return array Array of lines or an array with a single error message.
 */
function sumai_read_log_tail(string $filepath, int $lines = 50, int $buffer = 4096) {
    // Check if file exists and is readable
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return ["Error: Log file not found or not readable."];
    }
    
    // Open file for reading
    $f = fopen($filepath, "rb");
    if (!$f) {
        return ["Error: Could not open log file."];
    }
    
    // Jump to end of file
    fseek($f, 0, SEEK_END);
    
    // If file is empty, return empty array
    if (ftell($f) <= 0) {
        fclose($f);
        return [];
    }
    
    // Read it backwards and count newlines
    $output = [];
    $chunk = "";
    $linecounter = 0;
    
    while (ftell($f) > 0 && $linecounter < $lines) {
        $seek = min(ftell($f), $buffer);
        fseek($f, -$seek, SEEK_CUR);
        $chunk = fread($f, $seek);
        fseek($f, -$seek, SEEK_CUR);
        
        // Count newlines in chunk
        $newlines = substr_count($chunk, "\n");
        
        // If we have enough lines, break
        if ($newlines >= $lines - $linecounter) {
            $linecounter = $lines;
            break;
        }
        
        $linecounter += $newlines;
    }
    
    // Get last chunk of file
    if (ftell($f) <= 0) {
        $chunk = fread($f, filesize($filepath));
    } else {
        $seek = ftell($f);
        fseek($f, 0, SEEK_SET);
        $chunk = fread($f, $seek);
    }
    
    // Close file
    fclose($f);
    
    // Split by newline and take the last $lines lines
    $output = explode("\n", $chunk);
    $output = array_filter($output); // Remove empty lines
    $output = array_slice($output, -$lines);
    
    // Reverse the array so the most recent logs appear first
    $output = array_reverse($output);
    
    return $output;
}