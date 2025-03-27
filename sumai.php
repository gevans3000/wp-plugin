<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Fetches RSS articles, summarizes with OpenAI, and posts a daily summary.
 * Version:     1.1.9
 * Author:      biglife360.com
 * Author URI:  https://biglife360.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sumai
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

defined( 'ABSPATH' ) || exit;

// --- Constants ---
define( 'SUMAI_SETTINGS_OPTION', 'sumai_settings' );
define( 'SUMAI_PROCESSED_GUIDS_OPTION', 'sumai_processed_guids' );
define( 'SUMAI_CRON_HOOK', 'sumai_daily_event' );
define( 'SUMAI_CRON_TOKEN_OPTION', 'sumai_cron_token' );
define( 'SUMAI_ROTATE_TOKEN_HOOK', 'sumai_rotate_cron_token' );
define( 'SUMAI_PRUNE_LOGS_HOOK', 'sumai_prune_logs_event' );
define( 'SUMAI_LOG_DIR_NAME', 'sumai-logs' );
define( 'SUMAI_LOG_FILE_NAME', 'sumai.log' );
define( 'SUMAI_MAX_FEED_URLS', 3 );
define( 'SUMAI_FEED_ITEM_LIMIT', 7 );
define( 'SUMAI_MAX_INPUT_CHARS', 25000 );
define( 'SUMAI_PROCESSED_GUID_TTL', 30 * DAY_IN_SECONDS );
define( 'SUMAI_LOG_TTL', 30 * DAY_IN_SECONDS );

/* -------------------------------------------------------------------------
 * 1. ACTIVATION & DEACTIVATION HOOKS
 * ------------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'sumai_activate' );
register_deactivation_hook( __FILE__, 'sumai_deactivate' );

function sumai_activate() {
    $defaults = [
        'feed_urls' => '', 'context_prompt' => "Summarize the key points concisely.",
        'title_prompt' => "Generate a compelling title.", 'api_key' => '', 'draft_mode' => 0,
        'schedule_time' => '03:00', 'post_signature' => ''
    ];
    add_option( SUMAI_SETTINGS_OPTION, $defaults, '', 'no' );
    sumai_ensure_log_dir();
    sumai_schedule_daily_event();
    if (!wp_next_scheduled(SUMAI_ROTATE_TOKEN_HOOK)) wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK);
    if (!get_option(SUMAI_CRON_TOKEN_OPTION)) sumai_rotate_cron_token();
    if (!wp_next_scheduled(SUMAI_PRUNE_LOGS_HOOK)) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin activated. V' . get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
}

function sumai_deactivate() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    wp_clear_scheduled_hook(SUMAI_ROTATE_TOKEN_HOOK);
    wp_clear_scheduled_hook(SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin deactivated.');
}

/* -------------------------------------------------------------------------
 * 2. CRON SCHEDULING & HOOKS
 * ------------------------------------------------------------------------- */

function sumai_schedule_daily_event() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $time_str = (isset($options['schedule_time']) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $options['schedule_time'])) ? $options['schedule_time'] : '03:00';
    $tz = wp_timezone(); $now = current_time('timestamp', true); // GMT
    try {
        $dt = new DateTime(date('Y-m-d').' '.$time_str.':00', $tz);
        $ts = $dt->getTimestamp(); // GMT
        if ($ts <= $now) $dt->modify('+1 day');
        $first_run = $dt->getTimestamp();
        wp_schedule_event($first_run, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled. Next: '.wp_date('Y-m-d H:i:s T', $first_run));
    } catch (Exception $e) {
        sumai_log_event('Error scheduling event: '.$e->getMessage().'. Fallback.', true);
        $first_run = strtotime('tomorrow 03:00', $now);
        wp_schedule_event($first_run, 'daily', SUMAI_CRON_HOOK);
        sumai_log_event('Daily event scheduled (fallback). Next: '.wp_date('Y-m-d H:i:s T', $first_run));
    }
}

function sumai_rotate_cron_token() {
    update_option(SUMAI_CRON_TOKEN_OPTION, bin2hex(random_bytes(16)));
    sumai_log_event('Cron token rotated.');
}

add_action('update_option_'.SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event', 10, 0);
add_action(SUMAI_CRON_HOOK, 'sumai_generate_daily_summary'); // Direct call
add_action(SUMAI_ROTATE_TOKEN_HOOK, 'sumai_rotate_cron_token');
add_action(SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs');

/* -------------------------------------------------------------------------
 * 3. EXTERNAL CRON TRIGGER
 * ------------------------------------------------------------------------- */

add_action('init', 'sumai_check_external_trigger', 5);

function sumai_check_external_trigger() {
    if (!isset($_GET['sumai_trigger'], $_GET['token']) || $_GET['sumai_trigger'] !== '1') return;
    $provided = sanitize_text_field($_GET['token']);
    $stored = get_option(SUMAI_CRON_TOKEN_OPTION);
    if ($stored && hash_equals($stored, $provided)) {
        $lock_key = 'sumai_external_trigger_lock';
        if (false === get_transient($lock_key)) {
            set_transient($lock_key, 1, MINUTE_IN_SECONDS * 5);
            sumai_log_event('External trigger validated. Running summary generation...');
            do_action(SUMAI_CRON_HOOK); // Trigger main hook, function handles includes
            // Consider exit();
        } else { sumai_log_event('External trigger skipped, lock active.', true); }
    } else { sumai_log_event('Invalid external trigger token.', true); }
}

/* -------------------------------------------------------------------------
 * 4. MAIN SUMMARY GENERATION
 * ------------------------------------------------------------------------- */

function sumai_generate_daily_summary( bool $force_fetch = false ) {
    // Note: Removed dependency loading from top here. Will load just before use.

    sumai_log_event('Starting summary generation job.'.($force_fetch ? ' (Forced)' : ''));
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $api_key = sumai_get_api_key();

    if (empty($api_key)) { sumai_log_event('Error: API key missing.', true); return false; }
    if (empty($options['feed_urls'])) { sumai_log_event('Error: No feed URLs.', true); return false; }
    $feed_urls = array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (empty($feed_urls)) { sumai_log_event('Error: No valid feed URLs.', true); return false; }

    try {
        // Fetch Content
        list($new_content, $guids_to_add) = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
        if (empty($new_content)) { sumai_log_event('No new content found. Skipping summary.'); return false; }
        sumai_log_event('Fetched '.mb_strlen($new_content).' chars new content.');

        // Generate Summary
        $summary_result = sumai_summarize_text($new_content, $options['context_prompt'] ?? '', $options['title_prompt'] ?? '', $api_key);
        unset($new_content); // Free memory
        if (!$summary_result || empty($summary_result['title'])) { sumai_log_event('Error: Failed to get summary/title from API.', true); return false; }
        sumai_log_event('Summary & title generated.');

        // Create Post
        sumai_log_event('Preparing to create post...');

        // --- Load post functions immediately before use ---
        if (!function_exists('wp_unique_post_title') || !function_exists('wp_insert_post')) {
             $post_file = ABSPATH . 'wp-admin/includes/post.php';
             sumai_log_event('Loading post.php JUST BEFORE USE from: ' . $post_file);
             if (file_exists($post_file)) {
                 require_once $post_file;
                 // Check again immediately after loading
                 if (!function_exists('wp_unique_post_title') || !function_exists('wp_insert_post')) {
                     sumai_log_event('FATAL: Still cannot load functions after require_once!', true);
                     return false; // Give up
                 }
             } else {
                  sumai_log_event('FATAL: post.php file not found at expected path!', true);
                  return false; // Give up
             }
        }
        // --- End Load ---

        $clean_title = trim($summary_result['title'], '"\' ');
        $unique_title = wp_unique_post_title($clean_title);
        if ($unique_title !== $clean_title) sumai_log_event("Title adjusted: '{$clean_title}' -> '{$unique_title}'");

        $author_id = (is_user_logged_in() && ($uid = get_current_user_id()) > 0) ? $uid : 1;
        $author = get_userdata($author_id);
        if (!$author || !$author->has_cap('publish_posts')) {
            $author_id = 1; $author = get_userdata($author_id);
            if (!$author || !$author->has_cap('publish_posts')) { sumai_log_event("Error: Author ID {$author_id} invalid/cannot publish.", true); return false; }
        }

        $post_data = [
            'post_title'   => $unique_title,
            'post_content' => $summary_result['content'],
            'post_status'  => ($options['draft_mode'] ?? 0) ? 'draft' : 'publish',
            'post_type'    => 'post',
            'post_author'  => $author_id,
            'meta_input'   => ['_sumai_generated' => true]
        ];
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) { sumai_log_event("Error creating post: ".$post_id->get_error_message(), true); return false; }
        sumai_log_event("Post created ID: {$post_id}, Status: {$post_data['post_status']}.");

        // Update Processed GUIDs
        if (!empty($guids_to_add)) {
            $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
            $guids = array_merge($guids, $guids_to_add);
            $now = time(); $pruned = 0;
            foreach ($guids as $guid => $ts) { if ($ts < ($now - SUMAI_PROCESSED_GUID_TTL)) { unset($guids[$guid]); $pruned++; } }
            update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
            sumai_log_event("Processed GUIDs: Added ".count($guids_to_add).", Pruned {$pruned}. Total ".count($guids));
        }
        return $post_id;
    } catch (\Throwable $e) {
        sumai_log_event("FATAL during generation: ".$e->getMessage()." L".$e->getLine()." F".basename($e->getFile()), true);
        return false;
    }
}

/* -------------------------------------------------------------------------
 * 5. FEED FETCHING & PROCESSING
 * ------------------------------------------------------------------------- */

function sumai_fetch_new_articles_content( array $feed_urls, bool $force_fetch = false ): array {
    if (!function_exists('fetch_feed')) { include_once ABSPATH.WPINC.'/feed.php'; }
    if (!function_exists('fetch_feed')) { sumai_log_event('Error: fetch_feed unavailable.', true); return ['', []]; }

    $content = ''; $new_guids = []; $now = time(); $char_count = 0;
    $processed = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);

    foreach ($feed_urls as $url) {
        $url = esc_url_raw(trim($url)); if (empty($url)) continue;
        sumai_log_event("Processing feed: {$url}");
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) { sumai_log_event("Error fetch feed {$url}: ".$feed->get_error_message(), true); continue; }
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT);
        if (empty($items)) { sumai_log_event("No items in feed: {$url}"); continue; }

        $added_count = 0;
        foreach ($items as $item) {
            $guid = $item->get_id(true);
            if (isset($new_guids[$guid]) || (!$force_fetch && isset($processed[$guid]))) continue;
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($item->get_content() ?: $item->get_description())));
            if (empty($text)) continue;
            $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST);
            $item_title = strip_tags($item->get_title() ?: 'Untitled');
            $item_content = "Source: ".esc_html($feed_title)."\nTitle: ".esc_html($item_title)."\nContent:\n".$text."\n\n---\n\n";
            $item_len = mb_strlen($item_content);
            if (($char_count + $item_len) > SUMAI_MAX_INPUT_CHARS) { sumai_log_event("Skipping item '{$item_title}' - exceeds max chars."); break; }
            $content .= $item_content; $char_count += $item_len; $new_guids[$guid] = $now; $added_count++;
        }
        if ($added_count > 0) sumai_log_event("Added {$added_count} new items from {$url}. Total chars: {$char_count}");
        unset($feed, $items);
    }
    return [$content, $new_guids];
}

/* -------------------------------------------------------------------------
 * 6. OPENAI SUMMARIZATION & API
 * ------------------------------------------------------------------------- */

function sumai_summarize_text( string $text, string $ctx_prompt, string $title_prompt, string $api_key ): ?array {
    if (empty($text)) { sumai_log_event('Error: Empty text for summary.', true); return null; }
    if (mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);

    $messages = [[ 'role' => 'system', 'content' => "Output valid JSON {\"title\": \"...\", \"summary\": \"...\"}. Context: ".($ctx_prompt ?: "Summarize key points concisely."). " Title: ".($title_prompt ?: "Generate a compelling title.") ],
                 [ 'role' => 'user', 'content' => "Text:\n\n" . $text ]];
    $body = ['model'=>'gpt-4o-mini', 'messages'=>$messages, 'max_tokens'=>1500, 'temperature'=>0.6, 'response_format'=>['type'=>'json_object']];
    $args = ['headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$api_key],'body'=>json_encode($body),'method'=>'POST','timeout'=>90];

    sumai_log_event('Sending request to OpenAI API...');
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

    if (is_wp_error($resp)) { sumai_log_event('OpenAI WP Error: '.$resp->get_error_message(), true); return null; }
    $status = wp_remote_retrieve_response_code($resp); $body = wp_remote_retrieve_body($resp);
    if ($status !== 200) { sumai_log_event("OpenAI HTTP Error: {$status}. Body: ".$body, true); return null; }

    $data = json_decode($body, true); $json_str = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($json_str)) { sumai_log_event('Error: Invalid API response structure.', true); return null; }
    $parsed = json_decode($json_str, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed) || empty($parsed['title']) || !isset($parsed['summary'])) { sumai_log_event('Error: Failed parsing API JSON. Raw: '.$json_str, true); return null; }

    return ['title'=>trim($parsed['title']), 'content'=>trim($parsed['summary'])];
}

/* -------------------------------------------------------------------------
 * 7. API KEY & POST SIGNATURE
 * ------------------------------------------------------------------------- */

function sumai_get_api_key(): string {
    static $key = null; if ($key !== null) return $key;
    if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) return $key = SUMAI_OPENAI_API_KEY;
    $opts = get_option(SUMAI_SETTINGS_OPTION); $enc = $opts['api_key'] ?? '';
    if (empty($enc) || !function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !AUTH_KEY) return $key = '';
    $decoded = base64_decode($enc, true); $cipher = 'aes-256-cbc'; $ivlen = openssl_cipher_iv_length($cipher);
    if ($decoded === false || $ivlen === false || strlen($decoded) <= $ivlen) return $key = '';
    $iv = substr($decoded, 0, $ivlen); $cipher_raw = substr($decoded, $ivlen);
    $dec = openssl_decrypt($cipher_raw, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
    return $key = ($dec === false) ? '' : $dec;
}

function sumai_validate_api_key(string $api_key): bool {
    if (empty($api_key) || strpos($api_key, 'sk-') !== 0) return false;
    $resp = wp_remote_get('https://api.openai.com/v1/models', ['headers'=>['Authorization'=>'Bearer '.$api_key],'timeout'=>15]);
    $valid = (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200);
    sumai_log_event('API Key validation '.($valid ? 'OK.' : 'FAILED.').(is_wp_error($resp)?' WP Err: '.$resp->get_error_message():' Status: '.wp_remote_retrieve_response_code($resp)), !$valid);
    return $valid;
}

function sumai_append_signature_to_content($content) {
    if (is_singular('post') && !is_admin() && in_the_loop() && is_main_query()) {
        $sig = trim(get_option(SUMAI_SETTINGS_OPTION)['post_signature'] ?? '');
        if (!empty($sig)) { $html_sig = wp_kses_post($sig); if (strpos($content, $html_sig) === false) $content .= "\n\n<hr class=\"sumai-signature-divider\" />\n".$html_sig; }
    } return $content;
}
add_filter('the_content', 'sumai_append_signature_to_content', 99);

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'sumai_add_admin_menu');
add_action('admin_init', 'sumai_register_settings');

function sumai_add_admin_menu() { add_options_page('Sumai Settings', 'Sumai', 'manage_options', 'sumai-settings', 'sumai_render_settings_page'); }
function sumai_register_settings() { register_setting('sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings'); }

function sumai_sanitize_settings($input): array {
    $sanitized = []; $current = get_option(SUMAI_SETTINGS_OPTION, []); $current_enc = $current['api_key'] ?? '';

    // Feeds
    $valid_urls = []; if (isset($input['feed_urls'])) { $urls = array_map('trim', preg_split('/\r\n|\r|\n/', sanitize_textarea_field($input['feed_urls']))); foreach ($urls as $url) { if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) $valid_urls[] = $url; } $valid_urls = array_slice($valid_urls, 0, SUMAI_MAX_FEED_URLS); }
    $sanitized['feed_urls'] = implode("\n", $valid_urls);

    // Prompts, Mode, Signature, Time
    $sanitized['context_prompt'] = isset($input['context_prompt']) ? sanitize_textarea_field($input['context_prompt']) : '';
    $sanitized['title_prompt']   = isset($input['title_prompt']) ? sanitize_textarea_field($input['title_prompt']) : '';
    $sanitized['draft_mode']     = (isset($input['draft_mode']) && $input['draft_mode'] == '1') ? 1 : 0;
    $sanitized['post_signature'] = isset($input['post_signature']) ? wp_kses_post($input['post_signature']) : '';
    $time = isset($input['schedule_time']) ? sanitize_text_field($input['schedule_time']) : '03:00';
    $sanitized['schedule_time']  = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time) ? $time : ($current['schedule_time'] ?? '03:00');

    // API Key
    if (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) { $sanitized['api_key'] = $current_enc; }
    elseif (isset($input['api_key'])) {
        $new_key_in = sanitize_text_field(trim($input['api_key']));
        if ($new_key_in === '********************') $sanitized['api_key'] = $current_enc; // No change
        elseif (empty($new_key_in)) { $sanitized['api_key'] = ''; if (!empty($current_enc)) sumai_log_event('API key cleared.'); }
        else { // New key provided
            if (function_exists('openssl_encrypt') && defined('AUTH_KEY') && AUTH_KEY) {
                $cipher = 'aes-256-cbc'; $ivlen = openssl_cipher_iv_length($cipher);
                if ($ivlen !== false) {
                    $iv = openssl_random_pseudo_bytes($ivlen); $enc = openssl_encrypt($new_key_in, $cipher, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
                    if ($enc !== false && $iv !== false) { $new_enc = base64_encode($iv.$enc); if ($new_enc !== $current_enc) sumai_log_event('API key saved.'); $sanitized['api_key'] = $new_enc; }
                    else { sumai_log_event('Failed to encrypt API key.', true); $sanitized['api_key'] = $current_enc; }
                } else $sanitized['api_key'] = $current_enc;
            } else { sumai_log_event('Cannot encrypt API key (OpenSSL/AUTH_KEY missing).', true); $sanitized['api_key'] = $current_enc; }
        }
    } else $sanitized['api_key'] = $current_enc; // Not submitted

    return $sanitized;
}

function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    // Manual Trigger
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now_action')) {
        sumai_log_event('Manual generation trigger.');
        // sumai_generate_daily_summary handles its own includes now
        $result = sumai_generate_daily_summary(true);
        $type = ($result !== false && is_int($result)) ? 'success' : 'error'; $msg = ($type === 'success') ? sprintf('Generated Post ID: %d.', $result) : 'Generation failed/skipped. Check logs.';
        add_settings_error('sumai_settings', 'manual_gen', $msg, $type);
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('options-general.php?page=sumai-settings')); exit;
    }
    // Notices
    $notices = get_transient('settings_errors'); if ($notices) { settings_errors('sumai_settings'); delete_transient('settings_errors'); } else { settings_errors('sumai_settings'); }

    $opts = get_option(SUMAI_SETTINGS_OPTION, []); $opts += ['feed_urls'=>'','context_prompt'=>'','title_prompt'=>'','api_key'=>'','draft_mode'=>0,'schedule_time'=>'03:00','post_signature'=>''];
    $const_key = defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY); $db_key = !empty($opts['api_key']);
    $api_disp = $const_key ? '*** Defined in wp-config.php ***' : ($db_key ? '********************' : '');
    ?>
    <div class="wrap sumai-settings-wrap"><h1>Sumai Settings</h1>
    <div id="sumai-tabs"><nav class="nav-tab-wrapper wp-clearfix"><a href="#tab-main" class="nav-tab">Main</a><a href="#tab-advanced" class="nav-tab">Advanced</a><a href="#tab-debug" class="nav-tab">Debug</a></nav>
    <div id="tab-main" class="tab-content"><form method="post" action="options.php"><?php settings_fields('sumai_options_group'); ?><table class="form-table">
    <tr><th><label for="f_urls">Feed URLs</label></th><td><textarea id="f_urls" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[feed_urls]" rows="3" class="large-text" placeholder="One URL per line"><?= esc_textarea($opts['feed_urls']) ?></textarea><p class="description">Max <?= SUMAI_MAX_FEED_URLS ?> feeds.</p></td></tr>
    <tr><th><label for="f_key">OpenAI API Key</label></th><td>
        <?php if ($const_key): ?><input type="text" value="<?= esc_attr($api_disp) ?>" class="regular-text" readonly disabled /><p class="description">Defined in wp-config.php.</p>
        <?php else: ?><input type="password" id="f_key" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[api_key]" value="<?= esc_attr($api_disp) ?>" class="regular-text" placeholder="<?= $db_key ? 'Update key' : 'Enter Key' ?>" autocomplete="new-password" />
        <button type="button" id="test-api-btn" class="button">Test</button><span id="api-test-res" style="margin-left:10px;"></span>
        <p class="description">Securely stored. <?= $db_key ? 'Leave stars to keep.' : '<a href="https://platform.openai.com/api-keys" target="_blank">Get key</a>.' ?>
        <?php if (!function_exists('openssl_encrypt')||!defined('AUTH_KEY')||!AUTH_KEY) echo '<br><strong style="color:red;">Warn: Cannot encrypt in DB. Use constant.</strong>'; ?></p>
        <?php endif; ?>
    </td></tr>
    <tr><th><label for="f_ctx">Summary Prompt</label></th><td><textarea id="f_ctx" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[context_prompt]" rows="3" class="large-text" placeholder="Optional"><?= esc_textarea($opts['context_prompt']) ?></textarea></td></tr>
    <tr><th><label for="f_ttl">Title Prompt</label></th><td><textarea id="f_ttl" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[title_prompt]" rows="2" class="large-text" placeholder="Optional"><?= esc_textarea($opts['title_prompt']) ?></textarea></td></tr>
    <tr><th>Status</th><td><fieldset><label><input type="radio" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[draft_mode]" value="0" <?php checked(0,$opts['draft_mode']) ?>> Publish</label> <label><input type="radio" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[draft_mode]" value="1" <?php checked(1,$opts['draft_mode']) ?>> Draft</label></fieldset></td></tr>
    <tr><th><label for="f_time">Schedule Time</label></th><td><input type="time" id="f_time" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[schedule_time]" value="<?= esc_attr($opts['schedule_time']) ?>" required pattern="([01]?\d|2[0-3]):[0-5]\d" /><p class="description">Daily (HH:MM). TZ: <strong><?= esc_html(wp_timezone_string()) ?></strong>.<?php $next = wp_next_scheduled(SUMAI_CRON_HOOK); echo '<br>Next: '.($next ? wp_date('Y-m-d H:i', $next) : 'Not scheduled.'); ?></p></td></tr>
    <tr><th><label for="f_sig">Signature</label></th><td><textarea id="f_sig" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[post_signature]" rows="3" class="large-text" placeholder="Optional HTML/Text"><?= esc_textarea($opts['post_signature']) ?></textarea></td></tr>
    </table><?php submit_button('Save Settings'); ?></form></div>
    <div id="tab-advanced" class="tab-content" style="display:none;"><h2>Advanced</h2><div class="card"><h3>Generate Now</h3><form method="post"><input type="submit" name="sumai_generate_now" class="button button-primary" value="Generate Now"><?php wp_nonce_field('sumai_generate_now_action'); ?></form></div><div class="card"><h3>Test Feeds</h3><button type="button" id="test-feed-btn" class="button">Test</button><div id="feed-test-res" class="res-box"></div></div><div class="card"><h3>External Cron</h3><?php $tok = get_option(SUMAI_CRON_TOKEN_OPTION); if ($tok) { $url = add_query_arg(['sumai_trigger'=>'1','token'=>$tok], site_url('/')); echo '<input type="text" value="'.esc_url($url).'" class="large-text" readonly onfocus="this.select();"><p class="description"><code>wget -qO- \''.esc_url($url).'\' > /dev/null</code></p>'; } else echo '<p>Save settings.</p>'; ?></div></div>
    <div id="tab-debug" class="tab-content" style="display:none;"><h2>Debug</h2><?php sumai_render_debug_info(); ?></div></div></div>
    <style>.card{padding:15px 20px;border:1px solid #ccd0d4;background:#fff;margin:20px 0;}.res-box{margin-top:10px;padding:15px;background:#f6f7f7;border:1px solid #ccd0d4;max-height:400px;overflow-y:auto;display:none;white-space:pre-wrap;font-family:monospace;font-size:12px;}.nav-tab-wrapper{margin-bottom:20px;}</style>
    <script type="text/javascript">jQuery(document).ready(function($){var $tabs=$('#sumai-tabs'),$links=$tabs.find('.nav-tab'),$content=$tabs.find('.tab-content');function showTab(h){h=h||localStorage.getItem('sumaiActiveTab')||$links.first().attr('href');$links.removeClass('nav-tab-active');$content.hide();var $link=$links.filter('[href="'+h+'"]');if(!$link.length){$link=$links.first();h=$link.attr('href');}$link.addClass('nav-tab-active');$(h).show();try{localStorage.setItem('sumaiActiveTab',h);}catch(e){}} $links.on('click',function(e){e.preventDefault();showTab($(this).attr('href'));});showTab(window.location.hash||localStorage.getItem('sumaiActiveTab'));
    $('#test-api-btn').on('click',function(){var $b=$(this),$r=$('#api-test-res'),$k=$('#f_key'),t='';if($k.length)t=($k.val()==='********************')?'':$k.val();$b.prop('disabled',true).text('Testing...');$r.html('<span class="spinner is-active"></span>').css('color','');$.post(ajaxurl,{action:'sumai_test_api_key',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_api_key_nonce');?>',api_key_to_test:t},function(r){$r.html((r.success?'✅ ':'❌ ')+r.data.message).css('color',r.success?'green':'#d63638');},'json').fail(function(){$r.html('❌ AJAX Error').css('color','#d63638');}).always(function(){$b.prop('disabled',false).text('Test');});});
    $('#test-feed-btn').on('click',function(){var $b=$(this),$r=$('#feed-test-res');$b.prop('disabled',true).text('Testing...');$r.html('<span class="spinner is-active"></span>').css('color','').show();$.post(ajaxurl,{action:'sumai_test_feeds',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_feeds_nonce');?>'},function(r){if(r.success)$r.html(r.data.message).css('color','');else $r.html('❌ Error: '+r.data.message).css('color','#d63638');},'json').fail(function(){$r.html('❌ AJAX Error').css('color','#d63638');}).always(function(){$b.prop('disabled',false).text('Test');});});});</script>
    <?php
}

/* -------------------------------------------------------------------------
 * 9. AJAX HANDLERS (Restored)
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_sumai_test_api_key', 'sumai_ajax_test_api_key');
add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');

/** AJAX handler for testing API key. */
function sumai_ajax_test_api_key() {
    check_ajax_referer('sumai_test_api_key_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403);
    $key_input = isset($_POST['api_key_to_test']) ? trim(sanitize_text_field($_POST['api_key_to_test'])) : '';
    $key_to_test = empty($key_input) ? sumai_get_api_key() : $key_input; $context = empty($key_input) ? 'Current key' : 'Provided key';
    if (empty($key_to_test)) { wp_send_json_error(['message'=>'API key not configured.']); return; }
    if (sumai_validate_api_key($key_to_test)) wp_send_json_success(['message'=>$context.' validation OK.']);
    else wp_send_json_error(['message'=>$context.' validation FAILED. Check logs.']);
}

/** AJAX handler for testing feeds. */
function sumai_ajax_test_feeds() {
    check_ajax_referer('sumai_test_feeds_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403);
    $options = get_option(SUMAI_SETTINGS_OPTION, []); $urls = empty($options['feed_urls']) ? [] : array_slice(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (empty($urls)) { wp_send_json_error(['message'=>'No feeds configured.']); return; }
    if (!function_exists('fetch_feed')){ include_once ABSPATH.WPINC.'/feed.php'; if (!function_exists('fetch_feed')) { wp_send_json_error(['message'=>'WP feed functions unavailable.']); return; } }
    $output = sumai_test_feeds($urls); wp_send_json_success(['message'=>'<pre>'.esc_html($output).'</pre>']);
}

/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING (Restored fuller versions)
 * ------------------------------------------------------------------------- */

function sumai_test_feeds(array $feed_urls): string {
    if (!function_exists('fetch_feed')) return "Error: fetch_feed() unavailable.";
    $out = "--- Feed Test Results ---\nTime: ".wp_date('Y-m-d H:i:s T')."\n"; $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $out .= count($guids)." processed GUIDs tracked.\n\n"; $new_found = false; $items_total = 0; $new_total = 0;
    foreach ($feed_urls as $i => $url) {
        $out .= "--- Feed #".($i+1).": {$url} ---\n"; wp_feed_cache_transient_lifetime(0); $feed = fetch_feed($url); wp_feed_cache_transient_lifetime(HOUR_IN_SECONDS);
        if (is_wp_error($feed)) { $out .= "❌ Error: ".esc_html($feed->get_error_message())."\n\n"; continue; }
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); $count = count($items); $items_total += $count;
        if (empty($items)) { $out .= "⚠️ OK but no items found (limit ".SUMAI_FEED_ITEM_LIMIT.").\n\n"; continue; } $out .= "✅ OK. Found {$count} items:\n"; $feed_new = false;
        foreach ($items as $idx => $item) { $guid = $item->get_id(true); $title = mb_strimwidth(strip_tags($item->get_title()?:'N/A'),0,80,'...'); $out .= "- Item ".($idx+1).": ".esc_html($title)."\n"; if (isset($guids[$guid])) $out .= "  Status: Processed\n"; else { $out .= "  Status: ✨ NEW\n"; $feed_new = true; $new_found = true; $new_total++; } }
        if (!$feed_new && $count > 0) $out .= "  ℹ️ No new items in latest checked.\n"; $out .= "\n"; unset($feed, $items);
    } $out .= "--- Summary ---\nChecked ".count($feed_urls)." feeds, {$items_total} items total.\n"; $out .= $new_found ? "✅ Detected {$new_total} NEW items." : "ℹ️ No new content detected."; return $out;
}

function sumai_ensure_log_dir(): ?string {
    static $path=null,$chk=false; if($chk)return $path; $chk=true; $up=wp_upload_dir(); if(!empty($up['error'])){error_log("Sumai Log Err: wp_upload_dir: ".$up['error']); return null;} $dir=trailingslashit($up['basedir']).SUMAI_LOG_DIR_NAME; $file=trailingslashit($dir).SUMAI_LOG_FILE_NAME;
    if(!is_dir($dir)){if(!wp_mkdir_p($dir)){error_log("Sumai Log Err: Failed mkdir {$dir}"); return null;} @file_put_contents($dir.'/.htaccess',"Options -Indexes\nDeny from all"); @file_put_contents($dir.'/index.php','<?php // Silence');}
    if(!is_writable($dir)){error_log("Sumai Log Err: Dir not writable {$dir}"); return null;} if(!file_exists($file)){if(false===@file_put_contents($file,''))return null; @chmod($file,0644);} if(!is_writable($file)){error_log("Sumai Log Err: File not writable {$file}"); return null;} return $path=$file;
}
function sumai_log_event(string $msg, bool $is_error=false) { $file=sumai_ensure_log_dir(); if(!$file){error_log("Sumai ".($is_error?'[ERR]':'[INFO]')."(Log N/A): ".$msg); return;} $ts=wp_date('Y-m-d H:i:s T'); $lvl=$is_error?' [ERROR] ':' [INFO]  '; $line='['.$ts.']'.$lvl.trim(preg_replace('/\s+/',' ',wp_strip_all_tags($msg))).PHP_EOL; @file_put_contents($file,$line,FILE_APPEND|LOCK_EX); }
function sumai_prune_logs() { $days=SUMAI_LOG_TTL/DAY_IN_SECONDS; sumai_log_event("Running log pruning (keep {$days}d)..."); $file=sumai_ensure_log_dir(); if(!$file||!is_readable($file)||!is_writable($file)){sumai_log_event('Log pruning skipped: file inaccessible.',true);return;} $lines=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if(empty($lines)){return;} $cutoff=time()-SUMAI_LOG_TTL; $keep=[]; $pruned=0; $total=count($lines); foreach($lines as $line){$ts=false; if(preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/',$line,$m)){try{$dt=new DateTime($m[1]);$ts=$dt->getTimestamp();}catch(Exception $e){$ts=strtotime($m[1]);}} if($ts!==false&&$ts>=$cutoff)$keep[]=$line; else $pruned++;} if($pruned>0){$new_content=empty($keep)?'':implode(PHP_EOL,$keep).PHP_EOL; if(false===@file_put_contents($file,$new_content,LOCK_EX))sumai_log_event("Log pruning failed write.",true); else sumai_log_event("Log pruned. Removed {$pruned}/{$total} lines."); } }

function sumai_get_debug_info(): array {
    $dbg = []; $opts = get_option(SUMAI_SETTINGS_OPTION, []); $dbg['settings'] = $opts; $dbg['settings']['api_key'] = (defined('SUMAI_OPENAI_API_KEY') && !empty(SUMAI_OPENAI_API_KEY)) ? '*** Constant ***' : (!empty($opts['api_key']) ? '*** DB Set ***' : '*** Not Set ***');
    $crons = _get_cron_array() ?: []; $dbg['cron'] = []; $found = false; foreach ($crons as $t => $h) { foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $n) { if (isset($h[$n])) { $found=true; $k=key($h[$n]); $d=$h[$n][$k]; $dbg['cron'][$n] = ['next' => wp_date('Y-m-d H:i T', $t), 'schedule' => $d['schedule'] ?? 'N/A']; } } } if (!$found) $dbg['cron'] = 'No Sumai tasks scheduled.';
    $file = sumai_ensure_log_dir(); $dbg['log'] = ['path' => $file ?: 'ERROR', 'writable' => $file && is_writable($file), 'readable' => $file && is_readable($file)]; $dbg['log']['recent'] = ($dbg['log']['readable'] && ($c = @file_get_contents($file, false, null, -10240)) !== false) ? array_slice(explode("\n", trim($c)), -50) : ['Log unreadable or empty.'];
    global $wp_version; $dbg['sys'] = ['v' => $wp_version, 'php' => phpversion(), 'tz' => wp_timezone_string(), 'cron' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Disabled' : 'Enabled'), 'mem' => WP_MEMORY_LIMIT];
    return $dbg;
}
function sumai_render_debug_info() { $dbg = sumai_get_debug_info(); echo '<div><style>td{vertical-align:top;}pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:5px;border:1px solid #ccc;margin:0;font-size:12px;max-height:200px;overflow-y:auto;}</style><h3>Settings</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($dbg['settings'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(ucwords(str_replace('_',' ',$k))).'</td><td><pre>'.esc_html(is_array($v)?print_r($v,true):$v).'</pre></td></tr>'; echo '</tbody></table><h3>Scheduled Tasks</h3>'; if(is_array($dbg['cron'])&&!empty($dbg['cron'])){echo '<table class="wp-list-table fixed striped"><thead><tr><th>Hook</th><th>Next Run</th><th>Schedule</th></tr></thead><tbody>'; foreach($dbg['cron'] as $h=>$d) echo '<tr><td><code>'.esc_html($h).'</code></td><td>'.esc_html($d['next']).'</td><td>'.esc_html($d['schedule']).'</td></tr>'; echo '</tbody></table>';} else echo '<p>'.esc_html($dbg['cron']).'</p>'; echo '<h3>System</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($dbg['sys'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(strtoupper($k)).'</td><td>'.esc_html($v).'</td></tr>'; echo '</tbody></table><h3>Logging</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Path</td><td><code>'.esc_html($dbg['log']['path']).'</code></td></tr><tr><td>Status</td><td>'.($dbg['log']['readable']?'R':'Not R').', '.($dbg['log']['writable']?'W':'Not W').'</td></tr></tbody></table><h4>Recent Logs (tail ~50)</h4><pre style="max-height:400px;background:#1e1e1e;color:#d4d4d4;">'.esc_html(implode("\n",$dbg['log']['recent'])).'</pre></div>'; }

?>