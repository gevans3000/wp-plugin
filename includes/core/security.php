<?php
/**
 * Security functions for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Retrieves the API key from the configuration or database.
 * 
 * @return string The API key or empty string if not found.
 */
function sumai_get_api_key(): string { 
    static $k = null; 
    
    if ($k !== null) {
        sumai_log_event('Using cached API key (previously retrieved)');
        return $k;
    }
    
    if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) {
        sumai_log_event('Using API key from SUMAI_OPENAI_API_KEY constant');
        return $k = SUMAI_OPENAI_API_KEY;
    } else {
        sumai_log_event('SUMAI_OPENAI_API_KEY constant not found or empty, trying database');
    }
    
    $o = get_option(SUMAI_SETTINGS_OPTION); 
    $e = $o['api_key'] ?? ''; 
    
    if (empty($e)) {
        sumai_log_event('No API key found in database', true);
        return $k = '';
    }
    
    if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
        sumai_log_event('Encryption requirements not met', true);
        return $k = '';
    }
    
    $d = base64_decode($e, true); 
    $c = 'aes-256-cbc'; 
    $il = openssl_cipher_iv_length($c); 
    
    if ($d === false || $il === false || strlen($d) <= $il) {
        sumai_log_event('Invalid encrypted API key format', true);
        return $k = '';
    }
    
    $iv = substr($d, 0, $il); 
    $cr = substr($d, $il); 
    $dec = openssl_decrypt($cr, $c, AUTH_KEY, OPENSSL_RAW_DATA, $iv); 
    
    if ($dec === false) {
        sumai_log_event('Failed to decrypt API key', true);
        return $k = '';
    }
    
    sumai_log_event('Successfully decrypted API key from database');
    return $k = $dec;
}

/**
 * Validates an API key by testing it against the OpenAI API.
 * 
 * @param string $api_key The API key to validate.
 * @return bool Whether the API key is valid.
 */
function sumai_validate_api_key(string $api_key): bool { 
    if (empty($api_key) || strpos($api_key, 'sk-') !== 0) {
        sumai_log_event('Invalid API key format', true);
        return false;
    }
    
    $r = wp_remote_get(
        'https://api.openai.com/v1/models',
        [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 15
        ]
    );
    
    $v = (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200);
    
    if (!$v) {
        sumai_log_event(
            'API Key validation FAILED.' . 
            (is_wp_error($r) ? ' WP Err: ' . $r->get_error_message() : ' Status: ' . wp_remote_retrieve_response_code($r)),
            true
        );
    } else {
        sumai_log_event('API Key validation successful');
    }
    
    return $v;
}

/**
 * Encrypts an API key for storage in the database.
 * 
 * @param string $api_key The API key to encrypt.
 * @return string|bool The encrypted API key or false on failure.
 */
function sumai_encrypt_api_key(string $api_key): string {
    if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || !AUTH_KEY) {
        sumai_log_event('Encryption requirements not met for API key encryption', true);
        return false;
    }
    
    $c = 'aes-256-cbc';
    $iv_length = openssl_cipher_iv_length($c);
    
    if ($iv_length === false) {
        sumai_log_event('Failed to get cipher IV length', true);
        return false;
    }
    
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($api_key, $c, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        sumai_log_event('Failed to encrypt API key', true);
        return false;
    }
    
    $result = base64_encode($iv . $encrypted);
    return $result;
}

/**
 * Rotates the cron security token.
 */
function sumai_rotate_cron_token() {
    $token = wp_generate_password(32, false, false);
    update_option(SUMAI_CRON_TOKEN_OPTION, $token);
    sumai_log_event('Cron security token rotated');
}

/**
 * Appends the signature to content when displaying post.
 * 
 * @param string $content The post content.
 * @return string The content with signature appended if needed.
 */
function sumai_append_signature_to_content($content) {
    // Only apply to single posts on the frontend
    if (is_singular('post') && !is_admin() && in_the_loop() && is_main_query()) {
        $s = trim(get_option(SUMAI_SETTINGS_OPTION)['post_signature'] ?? '');
        
        if (!empty($s)) {
            $h = wp_kses_post($s);
            
            // Only append if not already present
            if (strpos($content, $h) === false) {
                $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n" . $h;
            }
        }
    }
    
    return $content;
}