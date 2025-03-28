<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Fetches RSS, summarizes with OpenAI, posts daily summary.
 * Version:     1.2.4
 * Author:      biglife360.com
 * Author URI:  https://biglife360.com
 * License:     GPL2
 * Text Domain: sumai
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

defined('ABSPATH') || exit;

define('SUMAI_SETTINGS_OPTION', 'sumai_settings');
define('SUMAI_PROCESSED_GUIDS_OPTION', 'sumai_processed_guids');
define('SUMAI_CRON_HOOK', 'sumai_daily_event');
define('SUMAI_CRON_TOKEN_OPTION', 'sumai_cron_token');
define('SUMAI_ROTATE_TOKEN_HOOK', 'sumai_rotate_cron_token');
define('SUMAI_PRUNE_LOGS_HOOK', 'sumai_prune_logs_event');
define('SUMAI_LOG_DIR_NAME', 'sumai-logs');
define('SUMAI_LOG_FILE_NAME', 'sumai.log');
define('SUMAI_MAX_FEED_URLS', 3);
define('SUMAI_FEED_ITEM_LIMIT', 7);
define('SUMAI_MAX_INPUT_CHARS', 25000);
define('SUMAI_PROCESSED_GUID_TTL', 30 * DAY_IN_SECONDS);
define('SUMAI_LOG_TTL', 30 * DAY_IN_SECONDS);

register_activation_hook(__FILE__, 'sumai_activate');
register_deactivation_hook(__FILE__, 'sumai_deactivate');

function sumai_activate() {
    add_option(SUMAI_SETTINGS_OPTION, ['feed_urls'=>'','context_prompt'=>'Summarize concisely.','title_prompt'=>'Generate title.','api_key'=>'','draft_mode'=>0,'schedule_time'=>'03:00','post_signature'=>'']);
    sumai_ensure_log_dir();
    sumai_schedule_daily_event();
    if (!wp_next_scheduled(SUMAI_ROTATE_TOKEN_HOOK)) wp_schedule_event(time() + WEEK_IN_SECONDS, 'weekly', SUMAI_ROTATE_TOKEN_HOOK);
    if (!get_option(SUMAI_CRON_TOKEN_OPTION)) sumai_rotate_cron_token();
    if (!wp_next_scheduled(SUMAI_PRUNE_LOGS_HOOK)) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin activated. V1.2.4');
}

function sumai_deactivate() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    wp_clear_scheduled_hook(SUMAI_ROTATE_TOKEN_HOOK);
    wp_clear_scheduled_hook(SUMAI_PRUNE_LOGS_HOOK);
    sumai_log_event('Plugin deactivated.');
}

function sumai_schedule_daily_event() {
    wp_clear_scheduled_hook(SUMAI_CRON_HOOK);
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $time_str = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $options['schedule_time'] ?? '03:00') ? $options['schedule_time'] : '03:00';
    $tz = wp_timezone();
    $now = current_time('timestamp', true);
    try {
        $dt = new DateTime(date('Y-m-d').' '.$time_str.':00', $tz);
        if ($dt->getTimestamp() <= $now) $dt->modify('+1 day');
        wp_schedule_event($dt->getTimestamp(), 'daily', SUMAI_CRON_HOOK);
    } catch (Exception $e) {
        $dt = new DateTime('now', $tz);
        $dt->modify('+1 day')->setTime(3, 0);
        wp_schedule_event($dt->getTimestamp(), 'daily', SUMAI_CRON_HOOK);
    }
}

function sumai_rotate_cron_token() {
    update_option(SUMAI_CRON_TOKEN_OPTION, bin2hex(random_bytes(16)));
}

add_action('update_option_'.SUMAI_SETTINGS_OPTION, 'sumai_schedule_daily_event');
add_action(SUMAI_CRON_HOOK, 'sumai_generate_daily_summary');
add_action(SUMAI_ROTATE_TOKEN_HOOK, 'sumai_rotate_cron_token');
add_action(SUMAI_PRUNE_LOGS_HOOK, 'sumai_prune_logs');

add_action('init', 'sumai_check_external_trigger', 5);
function sumai_check_external_trigger() {
    if (!isset($_GET['sumai_trigger'], $_GET['token']) || $_GET['sumai_trigger'] !== '1') return;
    $provided = sanitize_text_field($_GET['token']);
    $stored = get_option(SUMAI_CRON_TOKEN_OPTION);
    if ($stored && hash_equals($stored, $provided) && !get_transient('sumai_external_trigger_lock')) {
        set_transient('sumai_external_trigger_lock', 1, MINUTE_IN_SECONDS * 5);
        do_action(SUMAI_CRON_HOOK);
    }
}

function sumai_generate_daily_summary($force_fetch = false) {
    if (!function_exists('wp_insert_post')) {
        $is_background = (defined('DOING_CRON') && DOING_CRON) || isset($_GET['sumai_trigger']);
        if ($is_background && file_exists(ABSPATH.'wp-load.php')) {
            @include_once ABSPATH.'wp-load.php';
            if (!function_exists('wp_insert_post')) return false;
        } elseif (file_exists(ABSPATH.'wp-admin/includes/post.php')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
            if (!function_exists('wp_insert_post')) return false;
        } else return false;
    }

    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $api_key = defined('SUMAI_OPENAI_API_KEY') ? SUMAI_OPENAI_API_KEY : '';
    if (!$api_key || !$options['feed_urls']) return false;

    $feed_urls = array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (!$feed_urls) return false;

    list($new_content, $guids_to_add) = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
    if (!$new_content) return false;

    $summary_result = sumai_summarize_text($new_content, $options['context_prompt'], $options['title_prompt'], $api_key);
    if (!$summary_result || !$summary_result['title']) return false;

    $post_data = [
        'post_title' => trim($summary_result['title'], '"\' '),
        'post_content' => $summary_result['content'],
        'post_status' => $options['draft_mode'] ? 'draft' : 'publish',
        'post_type' => 'post',
        'post_author' => (is_user_logged_in() && ($uid = get_current_user_id()) > 0) ? $uid : 1,
        'meta_input' => ['_sumai_generated' => true]
    ];
    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) return false;

    if ($guids_to_add) {
        $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
        $guids = array_merge($guids, $guids_to_add);
        $now = time();
        foreach ($guids as $guid => $ts) if ($ts < $now - SUMAI_PROCESSED_GUID_TTL) unset($guids[$guid]);
        update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
    }
    return $post_id;
}

function sumai_fetch_new_articles_content($feed_urls, $force_fetch = false) {
    if (!function_exists('fetch_feed')) include_once ABSPATH.WPINC.'/feed.php';
    if (!function_exists('fetch_feed')) return ['', []];

    $content = '';
    $new_guids = [];
    $now = time();
    $char_count = 0;
    $processed = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);

    foreach ($feed_urls as $url) {
        $url = esc_url_raw(trim($url));
        if (!$url) continue;
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) continue;
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT);
        if (!$items) continue;

        foreach ($items as $item) {
            $guid = $item->get_id(true);
            if (isset($new_guids[$guid]) || (!$force_fetch && isset($processed[$guid]))) continue;
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($item->get_content() ?: $item->get_description())));
            if (!$text) continue;
            $feed_title = $feed->get_title() ?: parse_url($url, PHP_URL_HOST);
            $item_title = strip_tags($item->get_title() ?: 'Untitled');
            $item_content = "Source: $feed_title\nTitle: $item_title\n$text\n---\n";
            $item_len = mb_strlen($item_content);
            if ($char_count + $item_len > SUMAI_MAX_INPUT_CHARS) break;
            $content .= $item_content;
            $char_count += $item_len;
            $new_guids[$guid] = $now;
        }
        unset($feed, $items);
    }
    return [$content, $new_guids];
}

function sumai_summarize_text($text, $ctx_prompt, $title_prompt, $api_key) {
    if (!$text || mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);
    $messages = [
        ['role' => 'system', 'content' => "Output JSON {\"title\":\"...\",\"summary\":\"...\"}. Context: $ctx_prompt Title: $title_prompt"],
        ['role' => 'user', 'content' => $text]
    ];
    $body = ['model' => 'gpt-4o-mini', 'messages' => $messages, 'max_tokens' => 500, 'temperature' => 0.6, 'response_format' => ['type' => 'json_object']];
    $args = ['headers' => ['Content-Type' => 'application/json', 'Authorization' => "Bearer $api_key"], 'body' => json_encode($body), 'method' => 'POST', 'timeout' => 30];
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $json_str = $data['choices'][0]['message']['content'] ?? null;
    $parsed = json_decode($json_str, true);
    if (!$json_str || json_last_error() !== JSON_ERROR_NONE || !$parsed['title'] || !isset($parsed['summary'])) return null;
    return ['title' => trim($parsed['title']), 'content' => trim($parsed['summary'])];
}

function sumai_append_signature_to_content($content) {
    if (is_singular('post') && !is_admin() && in_the_loop() && is_main_query()) {
        $sig = trim(get_option(SUMAI_SETTINGS_OPTION)['post_signature'] ?? '');
        if ($sig && strpos($content, $sig) === false) $content .= "\n\n<hr>$sig";
    }
    return $content;
}
add_filter('the_content', 'sumai_append_signature_to_content', 99);

add_action('admin_menu', 'sumai_add_admin_menu');
add_action('admin_init', 'sumai_register_settings');

function sumai_add_admin_menu() { add_options_page('Sumai', 'Sumai', 'manage_options', 'sumai-settings', 'sumai_render_settings_page'); }
function sumai_register_settings() { register_setting('sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings'); }

function sumai_sanitize_settings($input) {
    $sanitized = [];
    $current = get_option(SUMAI_SETTINGS_OPTION, []);
    $valid_urls = [];
    if (isset($input['feed_urls'])) {
        $urls = array_map('trim', preg_split('/\r\n|\r|\n/', sanitize_textarea_field($input['feed_urls'])));
        foreach ($urls as $url) if ($url && filter_var($url, FILTER_VALIDATE_URL)) $valid_urls[] = $url;
        $valid_urls = array_slice($valid_urls, 0, SUMAI_MAX_FEED_URLS);
    }
    $sanitized['feed_urls'] = implode("\n", $valid_urls);
    $sanitized['context_prompt'] = sanitize_textarea_field($input['context_prompt'] ?? '');
    $sanitized['title_prompt'] = sanitize_textarea_field($input['title_prompt'] ?? '');
    $sanitized['draft_mode'] = ($input['draft_mode'] ?? 0) == '1' ? 1 : 0;
    $sanitized['post_signature'] = wp_kses_post($input['post_signature'] ?? '');
    $sanitized['schedule_time'] = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $input['schedule_time'] ?? '03:00') ? $input['schedule_time'] : $current['schedule_time'] ?? '03:00';
    $sanitized['api_key'] = defined('SUMAI_OPENAI_API_KEY') ? $current['api_key'] ?? '' : sanitize_text_field($input['api_key'] ?? '');
    return $sanitized;
}

function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now_action')) {
        $result = sumai_generate_daily_summary(true);
        add_settings_error('sumai_settings', 'manual_gen', $result ? "Post ID: $result" : 'Failed', $result ? 'success' : 'error');
        wp_safe_redirect(admin_url('options-general.php?page=sumai-settings'));
        exit;
    }
    settings_errors('sumai_settings');
    $opts = get_option(SUMAI_SETTINGS_OPTION, ['feed_urls'=>'','context_prompt'=>'','title_prompt'=>'','api_key'=>'','draft_mode'=>0,'schedule_time'=>'03:00','post_signature'=>'']);
    $api_disp = defined('SUMAI_OPENAI_API_KEY') ? '*** Constant ***' : ($opts['api_key'] ? '*** Set ***' : '');
    ?>
    <div class="wrap"><h1>Sumai</h1><div id="sumai-tabs"><nav class="nav-tab-wrapper"><a href="#tab-main" class="nav-tab">Main</a><a href="#tab-advanced" class="nav-tab">Advanced</a><a href="#tab-debug" class="nav-tab">Debug</a></nav>
    <div id="tab-main"><form method="post" action="options.php"><?php settings_fields('sumai_options_group'); ?>
    <table class="form-table">
    <tr><th>Feeds</th><td><textarea name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[feed_urls]" rows="3" class="large-text"><?=esc_textarea($opts['feed_urls'])?></textarea></td></tr>
    <tr><th>API Key</th><td><input type="text" value="<?=esc_attr($api_disp)?>" readonly disabled/></td></tr>
    <tr><th>Prompt</th><td><textarea name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[context_prompt]" rows="2" class="large-text"><?=esc_textarea($opts['context_prompt'])?></textarea></td></tr>
    <tr><th>Title</th><td><textarea name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[title_prompt]" rows="2" class="large-text"><?=esc_textarea($opts['title_prompt'])?></textarea></td></tr>
    <tr><th>Status</th><td><label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="0" <?php checked(0,$opts['draft_mode'])?>> Publish</label> <label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="1" <?php checked(1,$opts['draft_mode'])?>> Draft</label></td></tr>
    <tr><th>Time</th><td><input type="time" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[schedule_time]" value="<?=esc_attr($opts['schedule_time'])?>" required pattern="([01]?\d|2[0-3]):[0-5]\d"/></td></tr>
    <tr><th>Sig</th><td><textarea name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[post_signature]" rows="2" class="large-text"><?=esc_textarea($opts['post_signature'])?></textarea></td></tr>
    </table><?php submit_button('Save');?></form></div>
    <div id="tab-advanced" style="display:none;"><div class="card"><h3>Generate</h3><form method="post"><input type="submit" name="sumai_generate_now" class="button" value="Now"><?php wp_nonce_field('sumai_generate_now_action');?></form></div><div class="card"><h3>Test Feeds</h3><button id="test-feed-btn" class="button">Test</button><div id="feed-test-res"></div></div><div class="card"><h3>Cron</h3><?php $tok=get_option(SUMAI_CRON_TOKEN_OPTION); if($tok) echo '<input type="text" value="'.esc_url(add_query_arg(['sumai_trigger'=>'1','token'=>$tok],site_url('/'))).'" readonly>';?></div></div>
    <div id="tab-debug" style="display:none;"><?php sumai_render_debug_info();?></div></div></div>
    <style>.card{padding:10px;border:1px solid #ccc;margin:10px 0;}#feed-test-res{display:none;white-space:pre-wrap;font-family:monospace;padding:5px;border:1px solid #ccc;}</style>
    <script>jQuery(function($){var $tabs=$('#sumai-tabs'),$links=$tabs.find('.nav-tab'),$content=$tabs.find('div');function showTab(h){h=h||$links.first().attr('href');$links.removeClass('nav-tab-active');$content.hide();$links.filter('[href="'+h+'"]').addClass('nav-tab-active');$(h).show();localStorage.setItem('sumaiActiveTab',h);}$links.on('click',function(e){e.preventDefault();showTab($(this).attr('href'));});showTab(localStorage.getItem('sumaiActiveTab'));
    $('#test-feed-btn').on('click',function(){var $b=$(this),$r=$('#feed-test-res');$b.prop('disabled',true).text('...');$r.html('<span class="spinner is-active"></span>').show();$.post(ajaxurl,{action:'sumai_test_feeds',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_feeds_nonce');?>'},function(r){$r.html(r.success?r.data.message:'❌ '+r.data.message);},'json').fail(function(){$r.html('❌ AJAX Error');}).always(function(){$b.prop('disabled',false).text('Test');});});});</script>
    <?php
}

add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');
function sumai_ajax_test_feeds() {
    check_ajax_referer('sumai_test_feeds_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied'], 403);
    $urls = array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', get_option(SUMAI_SETTINGS_OPTION, [])['feed_urls'] ?? ''))), 0, SUMAI_MAX_FEED_URLS);
    if (!$urls) wp_send_json_error(['message'=>'No feeds']);
    if (!function_exists('fetch_feed')) include_once ABSPATH.WPINC.'/feed.php';
    if (!function_exists('fetch_feed')) wp_send_json_error(['message'=>'Feed error']);
    wp_send_json_success(['message'=>'<pre>'.esc_html(sumai_test_feeds($urls)).'</pre>']);
}

function sumai_test_feeds($feed_urls) {
    if (!function_exists('fetch_feed')) return "Feed error";
    $out = "Feeds\n".wp_date('Y-m-d H:i:s')."\n";
    $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    $items_total = 0;
    $new_total = 0;
    foreach ($feed_urls as $i => $url) {
        $out .= "#".($i+1).": $url\n";
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) { $out .= "❌ ".esc_html($feed->get_error_message())."\n"; continue; }
        $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT);
        $count = count($items);
        $items_total += $count;
        if (!$count) { $out .= "⚠️ No items\n"; continue; }
        $out .= "✅ $count items:\n";
        foreach ($items as $idx => $item) {
            $guid = $item->get_id(true);
            $title = mb_strimwidth(strip_tags($item->get_title()?:'N/A'), 0, 80, '...');
            $out .= "- ".($idx+1).": $title\n";
            $out .= isset($guids[$guid]) ? "  Processed\n" : "  ✨ NEW\n" && $new_total++;
        }
        $out .= "\n";
    }
    $out .= "Summary: $items_total items, $new_total new";
    return $out;
}

function sumai_ensure_log_dir() {
    static $path = null;
    if ($path !== null) return $path;
    $up = wp_upload_dir();
    if ($up['error']) return null;
    $dir = trailingslashit($up['basedir']).SUMAI_LOG_DIR_NAME;
    $file = trailingslashit($dir).SUMAI_LOG_FILE_NAME;
    if (!is_dir($dir)) wp_mkdir_p($dir);
    if (!file_exists($file)) @file_put_contents($file, '');
    return is_writable($file) ? $file : null;
}

function sumai_prune_logs() {
    $file = sumai_ensure_log_dir();
    if (!$file || !is_writable($file)) return;
    $lines = @file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    $cutoff = time() - SUMAI_LOG_TTL;
    $keep = [];
    foreach ($lines as $line) if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m) && strtotime($m[1]) >= $cutoff) $keep[] = $line;
    @file_put_contents($file, implode(PHP_EOL, $keep).PHP_EOL, LOCK_EX);
}

function sumai_render_debug_info() {
    $opts = get_option(SUMAI_SETTINGS_OPTION, []);
    $crons = _get_cron_array() ?: [];
    $cron_info = [];
    foreach ($crons as $t => $h) foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $n) if (isset($h[$n])) $cron_info[$n] = wp_date('Y-m-d H:i', $t);
    $file = sumai_ensure_log_dir();
    $logs = $file && is_readable($file) ? array_slice(explode("\n", @file_get_contents($file, false, null, -10240)), -20) : ['No logs'];
    echo "<h3>Cron</h3><pre>".esc_html(print_r($cron_info, true))."</pre><h3>Logs</h3><pre>".esc_html(implode("\n", $logs))."</pre>";
}