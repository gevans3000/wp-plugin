<?php
/**
 * Mock WordPress environment for testing
 * 
 * This file provides mock implementations of WordPress functions
 * used by the Sumai plugin to enable testing outside of WordPress.
 */

// Global variables for mocking WordPress state
global $wp_options, $wp_posts, $wp_filters, $wp_actions, $wp_current_user;

$wp_options = array();
$wp_posts = array();
$wp_filters = array();
$wp_actions = array();
$wp_current_user = array(
    'ID' => 1,
    'user_login' => 'admin',
    'user_email' => 'admin@example.com',
    'user_roles' => array('administrator')
);

// Options API
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_options;
        return isset($wp_options[$option]) ? $wp_options[$option] : $default;
    }
    
    function update_option($option, $value, $autoload = null) {
        global $wp_options;
        $wp_options[$option] = $value;
        return true;
    }
    
    function delete_option($option) {
        global $wp_options;
        if (isset($wp_options[$option])) {
            unset($wp_options[$option]);
            return true;
        }
        return false;
    }
}

// Post API
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($post_data, $wp_error = false) {
        global $wp_posts;
        static $post_id = 1;
        
        if (!is_array($wp_posts)) {
            $wp_posts = array();
        }
        
        $post_data['ID'] = $post_id++;
        $wp_posts[] = $post_data;
        
        return $post_data['ID'];
    }
    
    function get_post($post_id) {
        global $wp_posts;
        
        foreach ($wp_posts as $post) {
            if ($post['ID'] == $post_id) {
                return (object) $post;
            }
        }
        
        return null;
    }
    
    function get_posts($args = array()) {
        global $wp_posts;
        
        // Very simple implementation that ignores most args
        $limit = isset($args['numberposts']) ? $args['numberposts'] : 5;
        $result = array();
        $count = 0;
        
        foreach ($wp_posts as $post) {
            $result[] = (object) $post;
            $count++;
            
            if ($count >= $limit) {
                break;
            }
        }
        
        return $result;
    }
}

// Hooks API
if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        global $wp_filters;
        
        if (!isset($wp_filters[$tag])) {
            $wp_filters[$tag] = array();
        }
        
        if (!isset($wp_filters[$tag][$priority])) {
            $wp_filters[$tag][$priority] = array();
        }
        
        $wp_filters[$tag][$priority][] = array(
            'function' => $function_to_add,
            'accepted_args' => $accepted_args
        );
        
        return true;
    }
    
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        return add_action($tag, $function_to_add, $priority, $accepted_args);
    }
    
    function do_action($tag, ...$args) {
        global $wp_filters, $wp_actions;
        
        if (!isset($wp_actions[$tag])) {
            $wp_actions[$tag] = 0;
        }
        
        $wp_actions[$tag]++;
        
        if (!isset($wp_filters[$tag])) {
            return;
        }
        
        $priorities = array_keys($wp_filters[$tag]);
        sort($priorities);
        
        foreach ($priorities as $priority) {
            foreach ($wp_filters[$tag][$priority] as $callback) {
                $func = $callback['function'];
                $accepted_args = $callback['accepted_args'];
                
                if (is_string($func) && function_exists($func)) {
                    call_user_func_array($func, array_slice($args, 0, $accepted_args));
                } elseif (is_array($func) && method_exists($func[0], $func[1])) {
                    call_user_func_array($func, array_slice($args, 0, $accepted_args));
                }
            }
        }
    }
    
    function apply_filters($tag, $value, ...$args) {
        global $wp_filters;
        
        array_unshift($args, $value);
        
        if (!isset($wp_filters[$tag])) {
            return $value;
        }
        
        $priorities = array_keys($wp_filters[$tag]);
        sort($priorities);
        
        foreach ($priorities as $priority) {
            foreach ($wp_filters[$tag][$priority] as $callback) {
                $func = $callback['function'];
                $accepted_args = $callback['accepted_args'];
                
                if (is_string($func) && function_exists($func)) {
                    $value = call_user_func_array($func, array_slice($args, 0, $accepted_args));
                } elseif (is_array($func) && method_exists($func[0], $func[1])) {
                    $value = call_user_func_array($func, array_slice($args, 0, $accepted_args));
                }
                
                $args[0] = $value;
            }
        }
        
        return $value;
    }
}

// Utility functions
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        if (is_object($args)) {
            $parsed_args = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed_args = $args;
        } else {
            parse_str($args, $parsed_args);
        }
        
        return array_merge($defaults, $parsed_args);
    }
}

// File system functions
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        return mkdir($dir, 0755, true);
    }
}

// User functions
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        global $wp_current_user;
        
        // Simple implementation - admin can do everything
        if (in_array('administrator', $wp_current_user['user_roles'])) {
            return true;
        }
        
        return false;
    }
}

// Action Scheduler mock
if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '') {
        static $action_id = 1;
        
        // Just call the action immediately for testing
        do_action($hook, ...$args);
        
        return $action_id++;
    }
    
    function as_has_scheduled_action($hook, $args = null, $group = '') {
        return false;
    }
}

// SimplePie mock for feed fetching
class SimplePie {
    private $feed_url;
    private $items = array();
    private $success = true;
    
    public function set_feed_url($url) {
        $this->feed_url = $url;
    }
    
    public function init() {
        // Load the sample feed if it exists
        $sample_feed = __DIR__ . '/sample-feed.xml';
        if (file_exists($sample_feed)) {
            $this->success = true;
            $xml = simplexml_load_file($sample_feed);
            
            if ($xml) {
                foreach ($xml->channel->item as $item) {
                    $this->items[] = new SimplePie_Item($item);
                }
            }
        } else {
            $this->success = false;
        }
        
        return $this->success;
    }
    
    public function get_items() {
        return $this->items;
    }
    
    public function error() {
        return $this->success ? false : 'Failed to load feed';
    }
}

class SimplePie_Item {
    private $data;
    
    public function __construct($item) {
        $this->data = $item;
    }
    
    public function get_title() {
        return (string) $this->data->title;
    }
    
    public function get_permalink() {
        return (string) $this->data->link;
    }
    
    public function get_id() {
        return (string) $this->data->guid;
    }
    
    public function get_description() {
        return (string) $this->data->description;
    }
    
    public function get_content() {
        $namespaces = $this->data->getNamespaces(true);
        
        if (isset($namespaces['content'])) {
            $content = $this->data->children($namespaces['content']);
            return (string) $content->encoded;
        }
        
        return (string) $this->data->description;
    }
    
    public function get_date($format = 'U') {
        $date = (string) $this->data->pubDate;
        $timestamp = strtotime($date);
        
        if ($format === 'U') {
            return $timestamp;
        }
        
        return date($format, $timestamp);
    }
}
