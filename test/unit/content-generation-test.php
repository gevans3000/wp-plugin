<?php
/**
 * Unit tests for content generation functionality
 */

// Mock WordPress functions if not in WordPress environment
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
}

/**
 * Test OpenAI request formatting
 */
function test_openai_request_formatting() {
    // Test article data
    $article = array(
        'title' => 'Test Article Title',
        'link' => 'https://example.com/article',
        'description' => 'This is a test article description',
        'content' => 'This is the full content of the test article'
    );
    
    // Format the request
    $request_data = sumai_format_openai_request($article);
    
    // Check if the request has the required fields
    if (isset($request_data['model']) && !empty($request_data['model'])) {
        echo "✓ PASS: Request contains model field\n";
    } else {
        echo "✗ FAIL: Request missing model field\n";
    }
    
    if (isset($request_data['messages']) && is_array($request_data['messages'])) {
        echo "✓ PASS: Request contains messages array\n";
    } else {
        echo "✗ FAIL: Request missing messages array\n";
    }
    
    // Check if article data is included in the prompt
    $prompt_content = '';
    foreach ($request_data['messages'] as $message) {
        if (isset($message['content'])) {
            $prompt_content .= $message['content'];
        }
    }
    
    if (strpos($prompt_content, $article['title']) !== false) {
        echo "✓ PASS: Article title included in prompt\n";
    } else {
        echo "✗ FAIL: Article title not included in prompt\n";
    }
    
    if (strpos($prompt_content, $article['link']) !== false) {
        echo "✓ PASS: Article link included in prompt\n";
    } else {
        echo "✗ FAIL: Article link not included in prompt\n";
    }
}

/**
 * Test response processing
 */
function test_response_processing() {
    // Mock API response
    $api_response = array(
        'choices' => array(
            array(
                'message' => array(
                    'content' => "# Test Summary\n\nThis is a test summary of the article.\n\n## Key Points\n\n- Point 1\n- Point 2\n- Point 3"
                )
            )
        )
    );
    
    // Process the response
    $processed_content = sumai_process_openai_response($api_response);
    
    // Check if content was extracted correctly
    if (!empty($processed_content)) {
        echo "✓ PASS: Content extracted from API response\n";
    } else {
        echo "✗ FAIL: Failed to extract content from API response\n";
    }
    
    // Check if markdown formatting is preserved
    if (strpos($processed_content, '# Test Summary') !== false) {
        echo "✓ PASS: Markdown formatting preserved\n";
    } else {
        echo "✗ FAIL: Markdown formatting not preserved\n";
    }
    
    // Test with empty response
    $empty_response = array('choices' => array());
    $processed_content = sumai_process_openai_response($empty_response);
    
    if (empty($processed_content)) {
        echo "✓ PASS: Empty response handled correctly\n";
    } else {
        echo "✗ FAIL: Empty response not handled correctly\n";
    }
}

/**
 * Test post creation
 */
function test_post_creation() {
    global $wp_posts;
    $wp_posts = array();
    
    // Test data
    $article = array(
        'title' => 'Test Article Title',
        'link' => 'https://example.com/article',
        'guid' => 'https://example.com/article/guid'
    );
    
    $content = "# Test Summary\n\nThis is a test summary of the article.";
    
    // Create post
    $post_id = sumai_create_post($article, $content);
    
    // Check if post was created
    if ($post_id > 0) {
        echo "✓ PASS: Post created successfully\n";
    } else {
        echo "✗ FAIL: Failed to create post\n";
    }
    
    // Check post content
    $post_found = false;
    foreach ($wp_posts as $post) {
        if ($post['ID'] === $post_id) {
            $post_found = true;
            
            if ($post['post_title'] === $article['title']) {
                echo "✓ PASS: Post title set correctly\n";
            } else {
                echo "✗ FAIL: Post title not set correctly\n";
            }
            
            if ($post['post_content'] === $content) {
                echo "✓ PASS: Post content set correctly\n";
            } else {
                echo "✗ FAIL: Post content not set correctly\n";
            }
            
            break;
        }
    }
    
    if (!$post_found) {
        echo "✗ FAIL: Created post not found in database\n";
    }
}

// Run the tests
echo "Running Content Generation Tests\n";
echo "===============================\n\n";

test_openai_request_formatting();
test_response_processing();
test_post_creation();

echo "\nContent Generation Tests Completed\n";
