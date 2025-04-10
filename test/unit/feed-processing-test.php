<?php
/**
 * Unit tests for feed processing functionality
 */

// Mock WordPress functions if not in WordPress environment
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

// Mock WordPress strip_tags function if not available
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

/**
 * Test feed URL validation
 */
function test_feed_url_validation() {
    // Test valid feed URL
    $valid_url = 'https://example.com/feed';
    $result = sumai_validate_feed_url($valid_url);
    
    if ($result === true) {
        echo "✓ PASS: Valid feed URL correctly validated\n";
    } else {
        echo "✗ FAIL: Valid feed URL incorrectly rejected\n";
    }
    
    // Test invalid feed URL
    $invalid_url = 'not-a-url';
    $result = sumai_validate_feed_url($invalid_url);
    
    if ($result !== true) {
        echo "✓ PASS: Invalid feed URL correctly rejected\n";
    } else {
        echo "✗ FAIL: Invalid feed URL incorrectly validated\n";
    }
    
    // Test empty feed URL
    $empty_url = '';
    $result = sumai_validate_feed_url($empty_url);
    
    if ($result !== true) {
        echo "✓ PASS: Empty feed URL correctly rejected\n";
    } else {
        echo "✗ FAIL: Empty feed URL incorrectly validated\n";
    }
}

/**
 * Test content hash generation
 */
function test_content_hash_generation() {
    // Include the function if it's not already available
    if (!function_exists('sumai_generate_content_hash')) {
        function sumai_generate_content_hash($content) {
            // Normalize content by removing whitespace and converting to lowercase
            $normalized = strtolower(preg_replace('/\s+/', '', wp_strip_all_tags($content)));
            
            // Generate MD5 hash
            return md5($normalized);
        }
    }
    
    // Test basic hash generation
    $content = "This is a test article content.";
    $hash = sumai_generate_content_hash($content);
    
    if (!empty($hash) && strlen($hash) === 32) {
        echo "✓ PASS: Content hash generated with correct format\n";
    } else {
        echo "✗ FAIL: Content hash not generated correctly\n";
    }
    
    // Test that same content produces same hash
    $content2 = "This is a test article content.";
    $hash2 = sumai_generate_content_hash($content2);
    
    if ($hash === $hash2) {
        echo "✓ PASS: Same content produces same hash\n";
    } else {
        echo "✗ FAIL: Same content produces different hashes\n";
    }
    
    // Test that different content produces different hash
    $content3 = "This is different content.";
    $hash3 = sumai_generate_content_hash($content3);
    
    if ($hash !== $hash3) {
        echo "✓ PASS: Different content produces different hash\n";
    } else {
        echo "✗ FAIL: Different content produces same hash\n";
    }
    
    // Test that whitespace and case are normalized
    $content4 = "THIS  is a TEST article    CONTENT.";
    $hash4 = sumai_generate_content_hash($content4);
    
    if ($hash === $hash4) {
        echo "✓ PASS: Content with different whitespace and case produces same hash\n";
    } else {
        echo "✗ FAIL: Content with different whitespace and case produces different hash\n";
    }
    
    // Test that HTML is stripped
    $content5 = "<p>This is a <strong>test</strong> article content.</p>";
    $hash5 = sumai_generate_content_hash($content5);
    
    if ($hash === $hash5) {
        echo "✓ PASS: Content with HTML produces same hash after stripping\n";
    } else {
        echo "✗ FAIL: Content with HTML produces different hash after stripping\n";
    }
}

/**
 * Test article GUID tracking
 */
function test_article_guid_tracking() {
    // Mock the processed GUIDs
    global $wp_options;
    $wp_options = array();
    
    // Test adding a new GUID
    $test_guid = 'https://example.com/article/123';
    $result = sumai_mark_article_as_processed($test_guid);
    
    if ($result === true) {
        echo "✓ PASS: New GUID correctly marked as processed\n";
    } else {
        echo "✗ FAIL: Failed to mark new GUID as processed\n";
    }
    
    // Test checking if GUID is processed
    $is_processed = sumai_is_article_processed($test_guid);
    
    if ($is_processed === true) {
        echo "✓ PASS: Processed GUID correctly identified\n";
    } else {
        echo "✗ FAIL: Failed to identify processed GUID\n";
    }
    
    // Test checking an unprocessed GUID
    $new_guid = 'https://example.com/article/456';
    $is_processed = sumai_is_article_processed($new_guid);
    
    if ($is_processed === false) {
        echo "✓ PASS: Unprocessed GUID correctly identified\n";
    } else {
        echo "✗ FAIL: Unprocessed GUID incorrectly marked as processed\n";
    }
}

/**
 * Test content hash tracking
 */
function test_content_hash_tracking() {
    // Mock the processed hashes
    global $wp_options;
    $wp_options = array();
    
    // Test marking content hash as processed
    $test_content = "This is test article content for hash tracking.";
    $test_hash = sumai_generate_content_hash($test_content);
    
    // Mock the mark_articles_as_processed function if not available
    if (!function_exists('sumai_mark_articles_as_processed')) {
        function sumai_mark_articles_as_processed($articles) {
            global $wp_options;
            $now = time();
            
            foreach ($articles as $article) {
                if (isset($article['guid'])) {
                    $wp_options['sumai_processed_guids'][$article['guid']] = $now;
                }
                
                if (isset($article['content_hash'])) {
                    $wp_options['sumai_processed_hashes'][$article['content_hash']] = $now;
                }
            }
            
            return true;
        }
    }
    
    // Create a test article with content hash
    $test_article = array(
        'guid' => 'https://example.com/article/hash-test',
        'content' => $test_content,
        'content_hash' => $test_hash
    );
    
    $result = sumai_mark_articles_as_processed(array($test_article));
    
    if ($result === true && isset($wp_options['sumai_processed_hashes'][$test_hash])) {
        echo "✓ PASS: Content hash correctly marked as processed\n";
    } else {
        echo "✗ FAIL: Failed to mark content hash as processed\n";
    }
    
    // Test duplicate content with different GUID
    $duplicate_content = "This is test article content for hash tracking.";
    $duplicate_hash = sumai_generate_content_hash($duplicate_content);
    
    // Mock the is_article_processed_by_hash function
    if (!function_exists('sumai_is_article_processed_by_hash')) {
        function sumai_is_article_processed_by_hash($content) {
            global $wp_options;
            $hash = sumai_generate_content_hash($content);
            
            return isset($wp_options['sumai_processed_hashes'][$hash]) && 
                   $wp_options['sumai_processed_hashes'][$hash] > (time() - 30 * 86400);
        }
    }
    
    $is_processed = sumai_is_article_processed_by_hash($duplicate_content);
    
    if ($is_processed === true) {
        echo "✓ PASS: Duplicate content correctly identified by hash\n";
    } else {
        echo "✗ FAIL: Failed to identify duplicate content by hash\n";
    }
    
    // Test unique content
    $unique_content = "This is completely different content that should not match.";
    $is_processed = sumai_is_article_processed_by_hash($unique_content);
    
    if ($is_processed === false) {
        echo "✓ PASS: Unique content correctly identified as not processed\n";
    } else {
        echo "✗ FAIL: Unique content incorrectly identified as processed\n";
    }
}

/**
 * Test article limit enforcement
 */
function test_article_limit() {
    // Mock articles
    $articles = array(
        array('guid' => 'https://example.com/1', 'title' => 'Article 1'),
        array('guid' => 'https://example.com/2', 'title' => 'Article 2'),
        array('guid' => 'https://example.com/3', 'title' => 'Article 3'),
        array('guid' => 'https://example.com/4', 'title' => 'Article 4'),
        array('guid' => 'https://example.com/5', 'title' => 'Article 5')
    );
    
    // Test with default limit (3)
    $limited_articles = sumai_limit_articles($articles);
    
    if (count($limited_articles) === 3) {
        echo "✓ PASS: Article limit correctly enforced (default limit)\n";
    } else {
        echo "✗ FAIL: Article limit not enforced correctly (default limit)\n";
    }
    
    // Test with custom limit
    $custom_limit = 2;
    $limited_articles = sumai_limit_articles($articles, $custom_limit);
    
    if (count($limited_articles) === $custom_limit) {
        echo "✓ PASS: Article limit correctly enforced (custom limit)\n";
    } else {
        echo "✗ FAIL: Article limit not enforced correctly (custom limit)\n";
    }
    
    // Test with limit higher than available articles
    $high_limit = 10;
    $limited_articles = sumai_limit_articles($articles, $high_limit);
    
    if (count($limited_articles) === count($articles)) {
        echo "✓ PASS: Article limit correctly handled when limit exceeds available articles\n";
    } else {
        echo "✗ FAIL: Article limit not handled correctly when limit exceeds available articles\n";
    }
}

/**
 * Test duplicate detection with both GUID and content hash
 */
function test_duplicate_detection() {
    global $wp_options;
    $wp_options = array();
    
    // Mock the fetch_new_articles_content function
    if (!function_exists('sumai_fetch_new_articles_content')) {
        function sumai_fetch_new_articles_content($feed_urls, $force_fetch = false) {
            global $wp_options;
            
            // Create some test articles
            $articles = array(
                array(
                    'title' => 'Article 1',
                    'link' => 'https://example.com/1',
                    'guid' => 'guid-1',
                    'content' => 'Content for article 1',
                    'content_hash' => sumai_generate_content_hash('Content for article 1'),
                    'date' => time()
                ),
                array(
                    'title' => 'Article 2',
                    'link' => 'https://example.com/2',
                    'guid' => 'guid-2',
                    'content' => 'Content for article 2',
                    'content_hash' => sumai_generate_content_hash('Content for article 2'),
                    'date' => time()
                ),
                array(
                    'title' => 'Article 3 (Duplicate content of 1, different GUID)',
                    'link' => 'https://example.com/3',
                    'guid' => 'guid-3',
                    'content' => 'Content for article 1', // Same content as article 1
                    'content_hash' => sumai_generate_content_hash('Content for article 1'),
                    'date' => time()
                )
            );
            
            // Mark the first article as processed by GUID
            if (!isset($wp_options['sumai_processed_guids'])) {
                $wp_options['sumai_processed_guids'] = array();
            }
            $wp_options['sumai_processed_guids']['guid-1'] = time();
            
            // Mark content of article 2 as processed by hash
            if (!isset($wp_options['sumai_processed_hashes'])) {
                $wp_options['sumai_processed_hashes'] = array();
            }
            $wp_options['sumai_processed_hashes'][sumai_generate_content_hash('Content for article 2')] = time();
            
            // Simulate the fetch_new_articles_content function
            $result = array();
            foreach ($feed_urls as $url) {
                $result[$url] = array(
                    'url' => $url,
                    'articles' => array(),
                    'error' => false,
                    'error_message' => ''
                );
                
                // Filter articles based on processed GUIDs and hashes
                foreach ($articles as $article) {
                    $guid = $article['guid'];
                    $content_hash = $article['content_hash'];
                    
                    // Skip if already processed by GUID or content hash and not forcing
                    if ($force_fetch || 
                        ((!isset($wp_options['sumai_processed_guids'][$guid]) || 
                          $wp_options['sumai_processed_guids'][$guid] <= (time() - 30 * 86400)) && 
                         (!isset($wp_options['sumai_processed_hashes'][$content_hash]) || 
                          $wp_options['sumai_processed_hashes'][$content_hash] <= (time() - 30 * 86400)))) {
                        $result[$url]['articles'][] = $article;
                    }
                }
            }
            
            return $result;
        }
    }
    
    // Test normal fetch (should filter out processed articles)
    $feed_urls = array('https://example.com/feed');
    $result = sumai_fetch_new_articles_content($feed_urls, false);
    
    if (count($result[$feed_urls[0]]['articles']) === 0) {
        echo "✓ PASS: All duplicate articles correctly filtered out\n";
    } else {
        echo "✗ FAIL: Duplicate articles not correctly filtered\n";
        echo "  Found " . count($result[$feed_urls[0]]['articles']) . " articles, expected 0\n";
    }
    
    // Test force fetch (should include all articles)
    $result = sumai_fetch_new_articles_content($feed_urls, true);
    
    if (count($result[$feed_urls[0]]['articles']) === 3) {
        echo "✓ PASS: Force fetch correctly includes all articles\n";
    } else {
        echo "✗ FAIL: Force fetch does not include all articles\n";
        echo "  Found " . count($result[$feed_urls[0]]['articles']) . " articles, expected 3\n";
    }
}

// Run the tests
echo "Running Feed Processing Tests\n";
echo "============================\n\n";

test_feed_url_validation();
test_content_hash_generation();
test_article_guid_tracking();
test_content_hash_tracking();
test_article_limit();
test_duplicate_detection();

echo "\nFeed Processing Tests Completed\n";
