# Sumai Plugin - Code Style Guide

## Overview
This document outlines the coding standards and style guidelines for the Sumai WordPress plugin. Following these guidelines ensures consistency across the codebase and makes maintenance easier.

## PHP Coding Standards

### 1. General Guidelines
- Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Use tabs for indentation, not spaces
- Line length should be kept under 100 characters when possible
- Files should be named descriptively using lowercase letters and hyphens (e.g., `feed-processing.php`)
- Class files should use the class name with capitalization (e.g., `class-Feed-Processor.php`)

### 2. Naming Conventions
- **Functions**: Use lowercase with underscores, prefixed with `sumai_` (e.g., `sumai_process_content()`)
- **Classes**: Use capitalized words (e.g., `Sumai_Feed_Processor`)
- **Constants**: Use uppercase with underscores (e.g., `SUMAI_API_VERSION`)
- **Variables**: Use lowercase with underscores (e.g., `$feed_items`)
- **Global Variables**: Avoid when possible, but if necessary, prefix with `$sumai_` (e.g., `$sumai_settings`)

### 3. Function Declarations
- Always check if a function already exists before declaring it
- Keep functions focused on a single responsibility
- Aim for functions under 50 lines of code
- Use type hints when possible (PHP 7.4+)

Example:
```php
if ( ! function_exists( 'sumai_process_content' ) ) {
    /**
     * Process content from a feed item.
     *
     * @since 1.0.0
     * @param object $item The feed item to process.
     * @param array  $options Processing options.
     * @return array|WP_Error Processed content or error.
     */
    function sumai_process_content( $item, array $options = [] ) {
        // Function implementation
        return $result;
    }
}
```

### 4. Control Structures
- Use space inside parentheses for control structures
- Place opening braces on the same line as the statement
- Use Yoda conditions (`if ( true === $condition )`)
- Always use braces for control structures, even for single-line statements

Example:
```php
if ( null === $variable ) {
    // Do something
} elseif ( true === $condition ) {
    // Do something else
} else {
    // Default action
}

foreach ( $items as $item ) {
    // Process item
}

while ( $condition ) {
    // Loop body
}
```

### 5. Arrays
- Use trailing commas in multi-line arrays
- Use the long array syntax for better compatibility

Example:
```php
$settings = array(
    'api_key'    => $api_key,
    'timeout'    => 30,
    'max_items'  => 5,
    'debug_mode' => false,
);
```

### 6. String Formatting
- Use single quotes for strings without variables
- Use double quotes only when including variables or escape sequences
- For complex strings, use sprintf() or concatenation

Example:
```php
$simple_string = 'This is a simple string';
$variable_string = "Hello, {$username}!";
$complex_string = sprintf( 'The %s contains %d items', $container_name, $count );
```

### 7. Error Handling
- Use try/catch blocks for operations that might throw exceptions
- Return WP_Error objects for functions that might fail
- Log errors using the plugin's logging system

Example:
```php
try {
    $result = sumai_call_api( $params );
    if ( is_wp_error( $result ) ) {
        sumai_log_event( 'error', $result->get_error_message() );
        return $result;
    }
} catch ( Exception $e ) {
    $error = new WP_Error( 'api_error', $e->getMessage() );
    sumai_log_event( 'error', $e->getMessage() );
    return $error;
}
```

### 8. Database Queries
- Always use prepared statements for queries
- Use the WordPress database API (`$wpdb`)
- Cache results when appropriate

Example:
```php
global $wpdb;
$table_name = $wpdb->prefix . 'sumai_processed_items';
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE feed_id = %d LIMIT %d",
        $feed_id,
        $limit
    )
);
```

### 9. Security
- Sanitize all input data
- Validate data before using it
- Escape output data
- Use nonces for form submissions
- Check user capabilities before performing actions

Example:
```php
// Sanitize input
$feed_url = sanitize_text_field( wp_unslash( $_POST['feed_url'] ) );

// Validate data
if ( ! filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
    wp_send_json_error( array( 'message' => 'Invalid URL format' ) );
}

// Escape output
echo esc_html( $feed_title );

// Check nonce
check_admin_referer( 'sumai_action', 'nonce' );

// Check capabilities
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sumai' ) );
}
```

## JavaScript Coding Standards

### 1. General Guidelines
- Follow the [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/)
- Use semicolons at the end of statements
- Use 4 spaces for indentation (to match WordPress JS standards)
- Keep line length under 100 characters when possible

### 2. Naming Conventions
- **Functions/Methods**: Use camelCase (e.g., `updateStatus()`)
- **Variables**: Use camelCase (e.g., `feedItems`)
- **Constants**: Use UPPER_CASE (e.g., `MAX_ITEMS`)
- **Classes**: Use PascalCase (e.g., `FeedProcessor`)
- **jQuery Variables**: Prefix with `$` (e.g., `$feedContainer`)

### 3. Function Declarations
- Use function declarations for named functions
- Use arrow functions for anonymous functions
- Document functions with JSDoc comments

Example:
```javascript
/**
 * Updates the status display with the current generation status.
 *
 * @since 1.0.0
 * @param {Object} status The status object from the server.
 * @return {void}
 */
function updateStatus(status) {
    // Function implementation
}

// Arrow function example
const processItems = (items) => {
    return items.map(item => {
        return {
            id: item.id,
            title: item.title
        };
    });
};
```

### 4. Control Structures
- Use space inside parentheses for control structures
- Place opening braces on the same line as the statement
- Always use braces for control structures, even for single-line statements
- Use strict equality (`===` and `!==`)

Example:
```javascript
if (status === 'success') {
    displaySuccessMessage();
} else if (status === 'error') {
    displayErrorMessage();
} else {
    displayDefaultMessage();
}

for (let i = 0; i < items.length; i++) {
    processItem(items[i]);
}
```

### 5. AJAX Requests
- Use the WordPress `wp.ajax` methods when possible
- Handle errors appropriately
- Show loading indicators during requests
- Use promises or async/await for cleaner code

Example:
```javascript
jQuery(document).ready(function($) {
    $('#generate-button').on('click', function() {
        const data = {
            action: 'sumai_generate_summary',
            nonce: sumaiParams.nonce,
            feedId: $('#feed-id').val()
        };
        
        // Show loading indicator
        $('#status').html('<span class="spinner is-active"></span> Processing...');
        
        // Make AJAX request
        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                $('#status').html('<div class="notice notice-success">' + response.data.message + '</div>');
            } else {
                $('#status').html('<div class="notice notice-error">' + response.data.message + '</div>');
            }
        }).fail(function(xhr, status, error) {
            $('#status').html('<div class="notice notice-error">Request failed: ' + error + '</div>');
        });
    });
});
```

### 6. Event Handling
- Use event delegation when appropriate
- Remove event listeners when they're no longer needed
- Prevent default behavior when necessary

Example:
```javascript
// Event delegation
$('#feed-list').on('click', '.feed-item', function(e) {
    e.preventDefault();
    const feedId = $(this).data('feed-id');
    loadFeedDetails(feedId);
});
```

## CSS Coding Standards

### 1. General Guidelines
- Follow the [WordPress CSS Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/css/)
- Use tabs for indentation, not spaces
- Use lowercase for selectors
- Use hyphens for class names, not underscores or camelCase
- Prefix classes with `sumai-` to avoid conflicts

### 2. Formatting
- Place the opening brace on the same line as the selector
- Use one property per line
- End each property with a semicolon
- Place the closing brace on a new line
- Separate rule sets with a blank line

Example:
```css
.sumai-container {
    display: flex;
    flex-direction: column;
    margin: 20px 0;
}

.sumai-header {
    font-size: 18px;
    font-weight: bold;
    color: #23282d;
}
```

### 3. Selectors
- Keep selectors as short as possible
- Avoid overly specific selectors
- Use classes instead of IDs when possible
- Group related styles together

Example:
```css
/* Good */
.sumai-feed-list .sumai-feed-item {
    border-bottom: 1px solid #eee;
}

/* Avoid */
#sumai-admin-page .sumai-content .sumai-feed-list .sumai-feed-item {
    border-bottom: 1px solid #eee;
}
```

### 4. Media Queries
- Place media queries near the relevant rule sets
- Use standard breakpoints consistently

Example:
```css
.sumai-dashboard {
    display: block;
}

@media screen and (min-width: 782px) {
    .sumai-dashboard {
        display: flex;
    }
}
```

## Documentation Standards

### 1. PHPDoc Comments
- Include a description, `@since` version, parameters, and return value
- Use full sentences with periods
- Describe parameters and return values clearly
- Document exceptions or potential issues

Example:
```php
/**
 * Processes content from a feed item and generates a summary.
 *
 * This function takes a feed item, extracts its content, and uses the OpenAI API
 * to generate a summary. The summary is then formatted and returned.
 *
 * @since 1.0.0
 * @param object $item The feed item object from SimplePie.
 * @param array  $options Optional. Processing options.
 * @return array|WP_Error The processed content or an error object.
 */
```

### 2. File Headers
- Include a file header at the top of each file
- Describe the purpose of the file
- Include the package name and since version

Example:
```php
<?php
/**
 * Feed Processing Functions
 *
 * This file contains functions related to fetching and processing RSS feeds.
 *
 * @package Sumai
 * @since 1.0.0
 */
```

### 3. Inline Comments
- Use inline comments to explain complex logic
- Keep inline comments concise
- Use // for inline comments

Example:
```php
// Check if the item has already been processed
$processed = sumai_is_item_processed( $item->get_id() );

if ( ! $processed ) {
    // Only process new items
    $result = sumai_process_item( $item );
}
```

## Conclusion
Following these code style guidelines ensures consistency across the Sumai plugin codebase and makes it easier for developers to understand and maintain the code. Always refer to this document when writing or modifying code for the plugin.
