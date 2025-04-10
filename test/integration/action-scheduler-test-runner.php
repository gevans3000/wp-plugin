<?php
/**
 * Action Scheduler Integration Test Runner
 * 
 * This script runs the diagnostic tests on the Action Scheduler integration and 
 * generates an HTML report.
 */

// Simulate WordPress environment for testing
define('ABSPATH', dirname(dirname(__FILE__)) . '/');
define('WP_DEBUG', true);

// Mock WordPress functions if not in WordPress environment
if (!function_exists('add_action')) {
    function add_action() { return true; }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content) { return $content; }
}

if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) { return true; }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { 
        return is_object($thing) && method_exists($thing, 'get_error_message'); 
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return $url; }
}

if (!function_exists('current_time')) {
    function current_time($type) { 
        return ($type === 'timestamp') ? time() : date('Y-m-d H:i:s'); 
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
        $string = strip_tags($string);

        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }

        return trim($string);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = array();
        private $error_data = array();

        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) return;

            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code]) && !empty($this->errors[$code])) {
                return $this->errors[$code][0];
            }
            return '';
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            if (empty($codes)) return '';
            return $codes[0];
        }
    }
}

// Create output directory if it doesn't exist
$output_dir = __DIR__ . '/test-results';
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Start output buffering to capture the diagnostic output
ob_start();

// Run the diagnostic
include_once dirname(dirname(__FILE__)) . '/dev-files/as_diagnostic.php';

// Get the output
$html_output = ob_get_clean();

// Write to file
$output_file = $output_dir . '/action-scheduler-test-results-' . date('Y-m-d-His') . '.html';
file_put_contents($output_file, $html_output);

echo "Test completed. Results saved to: " . $output_file . PHP_EOL;

// Also print out a simple text summary
$passed = (strpos($html_output, 'All tests passed!') !== false);
echo "Test result: " . ($passed ? "PASSED" : "FAILED") . PHP_EOL;

if (!$passed) {
    // Extract fail count
    preg_match('/Failed Test Categories:<\/strong> (\d+)/', $html_output, $matches);
    $fail_count = isset($matches[1]) ? $matches[1] : 'unknown number of';
    echo "Failed $fail_count test categories." . PHP_EOL;
}

// Exit with success code if tests passed, or error code if they failed
exit($passed ? 0 : 1);
