<?php
/**
 * Plugin Name: Sumai
 * Plugin URI:  https://biglife360.com/sumai
 * Description: Fetches RSS articles, summarizes with OpenAI, and posts a daily summary.
 * Version:     1.2.6
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
        $tz = wp_timezone(); $dt = new DateTime('now', $tz); $dt->modify('+1 day'); $dt->setTime(3, 0, 0);
        $first_run = $dt->getTimestamp();
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
            do_action(SUMAI_CRON_HOOK);
        } else { sumai_log_event('External trigger skipped, lock active.', true); }
    } else { sumai_log_event('Invalid external trigger token.', true); }
}

/* -------------------------------------------------------------------------
 * 4. MAIN SUMMARY GENERATION
 * ------------------------------------------------------------------------- */

function sumai_generate_daily_summary( bool $force_fetch = false ) {

    // Note: No wp-load.php include here. We load post.php JIT before wp_insert_post.

    sumai_log_event('Starting summary generation job.'.($force_fetch ? ' (Forced)' : ''));
    $options = get_option(SUMAI_SETTINGS_OPTION, []);
    $api_key = sumai_get_api_key();

    if (empty($api_key)) { sumai_log_event('Error: API key missing.', true); return false; }
    if (empty($options['feed_urls'])) { sumai_log_event('Error: No feed URLs.', true); return false; }
    $feed_urls = array_slice(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS);
    if (empty($feed_urls)) { sumai_log_event('Error: No valid feed URLs.', true); return false; }

    try {
        list($new_content, $guids_to_add) = sumai_fetch_new_articles_content($feed_urls, $force_fetch);
        if (empty($new_content)) { sumai_log_event('No new content found. Skipping summary.'); return false; }
        sumai_log_event('Fetched '.mb_strlen($new_content).' chars new content.');

        $summary_result = sumai_summarize_text($new_content, $options['context_prompt'] ?? '', $options['title_prompt'] ?? '', $api_key);
        unset($new_content);
        if (!$summary_result || empty($summary_result['title'])) { sumai_log_event('Error: Failed to get summary/title from API.', true); return false; }
        sumai_log_event('Summary & title generated.');

        sumai_log_event('Preparing to create post...');

        // Use the raw title directly, removing potential quotes
        $clean_title = trim($summary_result['title'], '"\' ');
        $post_title_to_use = $clean_title;

        // Ensure get_userdata is available (needed for author check)
        if (!function_exists('get_userdata')) {
             sumai_log_event('get_userdata() missing. Loading user.php...');
             require_once ABSPATH . WPINC . '/user.php';
             if (!function_exists('get_userdata')) {
                  sumai_log_event('FATAL: Failed loading user.php!', true); return false;
             }
        }

        $author_id = (is_user_logged_in() && function_exists('get_current_user_id') && ($uid = get_current_user_id()) > 0) ? $uid : 1;
        $author = get_userdata($author_id);
        if (!$author || !$author->has_cap('publish_posts')) {
            $author_id = 1; $author = get_userdata($author_id);
            if (!$author || !$author->has_cap('publish_posts')) { sumai_log_event("Error: Author ID {$author_id} invalid/cannot publish.", true); return false; }
        }

        $post_data = [
            'post_title'   => $post_title_to_use, // Use the potentially non-unique title
            'post_content' => $summary_result['content'],
            'post_status'  => ($options['draft_mode'] ?? 0) ? 'draft' : 'publish',
            'post_type'    => 'post',
            'post_author'  => $author_id,
            'meta_input'   => ['_sumai_generated' => true]
        ];

        // --- Load post.php JUST BEFORE wp_insert_post ---
        if (!function_exists('wp_insert_post')) {
             $post_file = ABSPATH . 'wp-admin/includes/post.php';
             sumai_log_event('Loading post.php JIT before insert from: ' . $post_file);
             if (file_exists($post_file)) {
                 require_once $post_file;
                 if (!function_exists('wp_insert_post')) {
                     sumai_log_event('FATAL: Failed loading wp_insert_post after require!', true); return false;
                 }
             } else { sumai_log_event('FATAL: post.php not found!', true); return false; }
        }
        // --- End Load ---

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) { sumai_log_event("Error creating post: ".$post_id->get_error_message(), true); return false; }
        sumai_log_event("Post created ID: {$post_id}, Status: {$post_data['post_status']}.");

        if (!empty($guids_to_add)) {
            $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $guids = array_merge($guids, $guids_to_add);
            $now = time(); $pruned = 0; foreach ($guids as $guid => $ts) { if ($ts < ($now - SUMAI_PROCESSED_GUID_TTL)) { unset($guids[$guid]); $pruned++; } }
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
    $content = ''; $new_guids = []; $now = time(); $char_count = 0; $processed = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    foreach ($feed_urls as $url) { $url = esc_url_raw(trim($url)); if (empty($url)) continue; $feed = fetch_feed($url); if (is_wp_error($feed)) { sumai_log_event("Err fetch {$url}: ".$feed->get_error_message(), true); continue; } $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); if (empty($items)) continue; $added = 0; foreach ($items as $item) { $guid = $item->get_id(true); if (isset($new_guids[$guid]) || (!$force_fetch && isset($processed[$guid]))) continue; $text = trim(preg_replace('/\s+/',' ',wp_strip_all_tags($item->get_content()?:$item->get_description()))); if (empty($text)) continue; $ft = $feed->get_title()?:parse_url($url, PHP_URL_HOST); $it = strip_tags($item->get_title()?:'Untitled'); $ic = "Source: ".esc_html($ft)."\nTitle: ".esc_html($it)."\nContent:\n".$text."\n\n---\n\n"; $il = mb_strlen($ic); if (($char_count+$il) > SUMAI_MAX_INPUT_CHARS) { break; } $content .= $ic; $char_count += $il; $new_guids[$guid] = $now; $added++; } unset($feed, $items); } return [$content, $new_guids];
}

/* -------------------------------------------------------------------------
 * 6. OPENAI SUMMARIZATION & API
 * ------------------------------------------------------------------------- */

function sumai_summarize_text( string $text, string $ctx_prompt, string $title_prompt, string $api_key ): ?array {
    if (empty($text)) return null; if (mb_strlen($text) > SUMAI_MAX_INPUT_CHARS) $text = mb_substr($text, 0, SUMAI_MAX_INPUT_CHARS);
    $messages = [ ['role'=>'system', 'content'=>"Output valid JSON {\"title\":\"...\",\"summary\":\"...\"}. Context: ".($ctx_prompt?:"Summarize concisely.")." Title: ".($title_prompt?:"Generate unique title.")], ['role'=>'user', 'content'=>"Text:\n\n".$text] ];
    $body = ['model'=>'gpt-4o-mini', 'messages'=>$messages, 'max_tokens'=>1500, 'temperature'=>0.6, 'response_format'=>['type'=>'json_object']];
    $args = ['headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$api_key],'body'=>json_encode($body),'method'=>'POST','timeout'=>90];
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
    if (is_wp_error($resp)) { sumai_log_event('OpenAI WP Error: '.$resp->get_error_message(), true); return null; }
    $status = wp_remote_retrieve_response_code($resp); $body = wp_remote_retrieve_body($resp); if ($status !== 200) { sumai_log_event("OpenAI HTTP Error: {$status}. Body: ".$body, true); return null; }
    $data = json_decode($body, true); $json_str = $data['choices'][0]['message']['content'] ?? null; if (!is_string($json_str)) return null; $parsed = json_decode($json_str, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed) || empty($parsed['title']) || !isset($parsed['summary'])) { sumai_log_event('Error parsing API JSON. Raw: '.$json_str, true); return null; }
    return ['title'=>trim($parsed['title']), 'content'=>trim($parsed['summary'])];
}

/* -------------------------------------------------------------------------
 * 7. API KEY & POST SIGNATURE
 * ------------------------------------------------------------------------- */

function sumai_get_api_key(): string { static $k=null; if($k!==null)return $k; if(defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY))return $k=SUMAI_OPENAI_API_KEY; $o=get_option(SUMAI_SETTINGS_OPTION); $e=$o['api_key']??''; if(empty($e)||!function_exists('openssl_decrypt')||!defined('AUTH_KEY')||!AUTH_KEY)return $k=''; $d=base64_decode($e,true); $c='aes-256-cbc'; $il=openssl_cipher_iv_length($c); if($d===false||$il===false||strlen($d)<=$il)return $k=''; $iv=substr($d,0,$il); $cr=substr($d,$il); $dec=openssl_decrypt($cr,$c,AUTH_KEY,OPENSSL_RAW_DATA,$iv); return $k=($dec===false)?'':$dec; }
function sumai_validate_api_key(string $api_key): bool { if(empty($api_key)||strpos($api_key,'sk-')!==0)return false; $r=wp_remote_get('https://api.openai.com/v1/models',['headers'=>['Authorization'=>'Bearer '.$api_key],'timeout'=>15]); $v=(!is_wp_error($r)&&wp_remote_retrieve_response_code($r)===200); if(!$v)sumai_log_event('API Key validation FAILED.'.(is_wp_error($r)?' WP Err: '.$r->get_error_message():' Status: '.wp_remote_retrieve_response_code($r)),true); return $v; }
function sumai_append_signature_to_content($content) { if(is_singular('post')&&!is_admin()&&in_the_loop()&&is_main_query()){$s=trim(get_option(SUMAI_SETTINGS_OPTION)['post_signature']??'');if(!empty($s)){$h=wp_kses_post($s);if(strpos($content,$h)===false)$content.="\n\n<hr class=\"sumai-signature-divider\" />\n".$h;}} return $content;} add_filter('the_content','sumai_append_signature_to_content',99);

/* -------------------------------------------------------------------------
 * 8. ADMIN SETTINGS PAGE
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'sumai_add_admin_menu'); add_action('admin_init', 'sumai_register_settings');
function sumai_add_admin_menu() { add_options_page('Sumai Settings', 'Sumai', 'manage_options', 'sumai-settings', 'sumai_render_settings_page'); }
function sumai_register_settings() { register_setting('sumai_options_group', SUMAI_SETTINGS_OPTION, 'sumai_sanitize_settings'); }

function sumai_sanitize_settings($input): array { $s=[];$c=get_option(SUMAI_SETTINGS_OPTION,[]);$ce=$c['api_key']??'';$vu=[];if(isset($input['feed_urls'])){$us=array_map('trim',preg_split('/\r\n|\r|\n/',sanitize_textarea_field($input['feed_urls'])));foreach($us as $u){if(!empty($u)&&filter_var($u,FILTER_VALIDATE_URL)&&preg_match('/^https?:\/\//',$u))$vu[]=$u;}$vu=array_slice($vu,0,SUMAI_MAX_FEED_URLS);}$s['feed_urls']=implode("\n",$vu);$s['context_prompt']=isset($input['context_prompt'])?sanitize_textarea_field($input['context_prompt']):'';$s['title_prompt']=isset($input['title_prompt'])?sanitize_textarea_field($input['title_prompt']):'';$s['draft_mode']=(isset($input['draft_mode'])&&$input['draft_mode']=='1')?1:0;$s['post_signature']=isset($input['post_signature'])?wp_kses_post($input['post_signature']):'';$t=isset($input['schedule_time'])?sanitize_text_field($input['schedule_time']):'03:00';$s['schedule_time']=preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/',$t)?$t:($c['schedule_time']??'03:00');
if(defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY)){$s['api_key']=$ce;}elseif(isset($input['api_key'])){$ni=sanitize_text_field(trim($input['api_key']));if($ni==='********************')$s['api_key']=$ce;elseif(empty($ni)){$s['api_key']='';if(!empty($ce))sumai_log_event('API key cleared.');}else{if(function_exists('openssl_encrypt')&&defined('AUTH_KEY')&&AUTH_KEY){$cp='aes-256-cbc';$il=openssl_cipher_iv_length($cp);if($il!==false){$iv=openssl_random_pseudo_bytes($il);$en=openssl_encrypt($ni,$cp,AUTH_KEY,OPENSSL_RAW_DATA,$iv);if($en!==false&&$iv!==false){$ne=base64_encode($iv.$en);if($ne!==$ce)sumai_log_event('API key saved.');$s['api_key']=$ne;}else{$s['api_key']=$ce;}}else $s['api_key']=$ce;}else $s['api_key']=$ce;}}else $s['api_key']=$ce; return $s;}

function sumai_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    if ('POST'===$_SERVER['REQUEST_METHOD'] && isset($_POST['sumai_generate_now']) && check_admin_referer('sumai_generate_now_action')) { $result = sumai_generate_daily_summary(true); $type = ($result!==false && is_int($result))?'success':'error'; $msg = ($type==='success')?sprintf('Generated Post ID: %d.',$result):'Gen failed/skipped.'; add_settings_error('sumai_settings','manual_gen',$msg,$type); set_transient('settings_errors',get_settings_errors(),30); wp_safe_redirect(admin_url('options-general.php?page=sumai-settings')); exit; }
    $notices = get_transient('settings_errors'); if ($notices) { settings_errors('sumai_settings'); delete_transient('settings_errors'); } else { settings_errors('sumai_settings'); }
    $opts = get_option(SUMAI_SETTINGS_OPTION, []); $opts += ['feed_urls'=>'','context_prompt'=>'','title_prompt'=>'','api_key'=>'','draft_mode'=>0,'schedule_time'=>'03:00','post_signature'=>'']; $const_key = defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY); $db_key = !empty($opts['api_key']); $api_disp = $const_key?'*** Constant ***':($db_key?'********************':'');
    ?>
    <div class="wrap"><h1>Sumai Settings</h1><div id="sumai-tabs"><nav class="nav-tab-wrapper"><a href="#tab-main" class="nav-tab">Main</a><a href="#tab-advanced" class="nav-tab">Advanced</a><a href="#tab-debug" class="nav-tab">Debug</a></nav>
    <div id="tab-main" class="tab-content"><form method="post" action="options.php"><?php settings_fields('sumai_options_group'); ?><table class="form-table">
    <tr><th><label for="f_urls">Feed URLs</label></th><td><textarea id="f_urls" name="<?= esc_attr(SUMAI_SETTINGS_OPTION) ?>[feed_urls]" rows="3" class="large-text"><?= esc_textarea($opts['feed_urls']) ?></textarea><p>Max <?= SUMAI_MAX_FEED_URLS ?> feeds.</p></td></tr>
    <tr><th><label for="f_key">API Key</label></th><td><?php if($const_key):?><input type="text" value="<?=esc_attr($api_disp)?>" readonly disabled/><p>Defined in wp-config.</p><?php else:?><input type="password" id="f_key" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[api_key]" value="<?=esc_attr($api_disp)?>" placeholder="<?= $db_key?'Update':'Enter Key' ?>"/><button type="button" id="test-api-btn" class="button">Test</button><span id="api-test-res"></span><p><?= $db_key?'Leave stars to keep.':''?></p><?php endif;?></td></tr>
    <tr><th><label for="f_ctx">Summary Prompt</label></th><td><textarea id="f_ctx" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[context_prompt]" rows="3" class="large-text"><?=esc_textarea($opts['context_prompt'])?></textarea></td></tr>
    <tr><th><label for="f_ttl">Title Prompt</label></th><td><textarea id="f_ttl" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[title_prompt]" rows="2" class="large-text"><?=esc_textarea($opts['title_prompt'])?></textarea><p class="description">Ask AI to make it unique.</p></td></tr>
    <tr><th>Status</th><td><fieldset><label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="0" <?php checked(0,$opts['draft_mode'])?>> Publish</label> <label><input type="radio" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[draft_mode]" value="1" <?php checked(1,$opts['draft_mode'])?>> Draft</label></fieldset></td></tr>
    <tr><th><label for="f_time">Schedule Time</label></th><td><input type="time" id="f_time" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[schedule_time]" value="<?=esc_attr($opts['schedule_time'])?>" required pattern="([01]?\d|2[0-3]):[0-5]\d"/><p>Daily (HH:MM). TZ: <strong><?=esc_html(wp_timezone_string())?></strong>.<?php $next=wp_next_scheduled(SUMAI_CRON_HOOK); echo '<br>Next: '.($next?wp_date('Y-m-d H:i',$next):'N/A');?></p></td></tr>
    <tr><th><label for="f_sig">Signature</label></th><td><textarea id="f_sig" name="<?=esc_attr(SUMAI_SETTINGS_OPTION)?>[post_signature]" rows="3" class="large-text"><?=esc_textarea($opts['post_signature'])?></textarea></td></tr>
    </table><?php submit_button('Save Settings');?></form></div>
    <div id="tab-advanced" class="tab-content" style="display:none;"><h2>Advanced</h2><div class="card"><h3>Generate Now</h3><form method="post"><input type="submit" name="sumai_generate_now" class="button button-primary" value="Generate Now"><?php wp_nonce_field('sumai_generate_now_action');?></form></div><div class="card"><h3>Test Feeds</h3><button type="button" id="test-feed-btn" class="button">Test</button><div id="feed-test-res"></div></div><div class="card"><h3>External Cron</h3><?php $tok=get_option(SUMAI_CRON_TOKEN_OPTION); if($tok){$url=add_query_arg(['sumai_trigger'=>'1','token'=>$tok],site_url('/')); echo '<input type="text" value="'.esc_url($url).'" readonly onfocus="this.select();"><p><code>wget -qO- \''.esc_url($url).'\' > /dev/null</code></p>';} else echo '<p>Save settings.</p>';?></div></div>
    <div id="tab-debug" class="tab-content" style="display:none;"><h2>Debug</h2><?php sumai_render_debug_info();?></div></div></div>
    <style>.card{padding:15px;border:1px solid #ccc;background:#fff;margin:20px 0;}.nav-tab-wrapper{margin-bottom:20px;}#api-test-res,#feed-test-res{margin-left:10px;vertical-align:middle;}#feed-test-res{display:none;white-space:pre-wrap;font-family:monospace;max-height:300px;overflow-y:auto;background:#f9f9f9;padding:10px;border:1px solid #ccc;}</style>
    <script type="text/javascript">jQuery(document).ready(function($){var $tabs=$('#sumai-tabs'),$links=$tabs.find('.nav-tab'),$content=$tabs.find('.tab-content');function showTab(h){h=h||localStorage.getItem('sumaiActiveTab')||$links.first().attr('href');$links.removeClass('nav-tab-active');$content.hide();var $link=$links.filter('[href="'+h+'"]');if(!$link.length){$link=$links.first();h=$link.attr('href');}$link.addClass('nav-tab-active');$(h).show();try{localStorage.setItem('sumaiActiveTab',h);}catch(e){}} $links.on('click',function(e){e.preventDefault();showTab($(this).attr('href'));});showTab(window.location.hash||localStorage.getItem('sumaiActiveTab'));
    $('#test-api-btn').on('click',function(){var $b=$(this),$r=$('#api-test-res'),$k=$('#f_key'),t='';if($k.length)t=($k.val()==='********************')?'':$k.val();$b.prop('disabled',true).text('...');$r.html('<span class="spinner is-active"></span>').css('color','');$.post(ajaxurl,{action:'sumai_test_api_key',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_api_key_nonce');?>',api_key_to_test:t},function(r){$r.html((r.success?'✅ ':'❌ ')+r.data.message).css('color',r.success?'green':'#d63638');},'json').fail(function(){$r.html('❌ AJAX Error');}).always(function(){$b.prop('disabled',false).text('Test');});});
    $('#test-feed-btn').on('click',function(){var $b=$(this),$r=$('#feed-test-res');$b.prop('disabled',true).text('...');$r.html('<span class="spinner is-active"></span>').css('color','').show();$.post(ajaxurl,{action:'sumai_test_feeds',_ajax_nonce:'<?php echo wp_create_nonce('sumai_test_feeds_nonce');?>'},function(r){if(r.success)$r.html(r.data.message).css('color','');else $r.html('❌ Error: '+r.data.message).css('color','#d63638');},'json').fail(function(){$r.html('❌ AJAX Error');}).always(function(){$b.prop('disabled',false).text('Test');});});});</script>
    <?php
}

/* -------------------------------------------------------------------------
 * 9. AJAX HANDLERS
 * ------------------------------------------------------------------------- */

add_action('wp_ajax_sumai_test_api_key', 'sumai_ajax_test_api_key');
add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');

function sumai_ajax_test_api_key() { check_ajax_referer('sumai_test_api_key_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403); $key_input = isset($_POST['api_key_to_test']) ? trim(sanitize_text_field($_POST['api_key_to_test'])) : ''; $key_to_test = empty($key_input) ? sumai_get_api_key() : $key_input; $context = empty($key_input) ? 'Current key' : 'Provided key'; if (empty($key_to_test)) { wp_send_json_error(['message'=>'API key not configured.']); return; } if (sumai_validate_api_key($key_to_test)) wp_send_json_success(['message'=>$context.' validation OK.']); else wp_send_json_error(['message'=>$context.' validation FAILED. Check logs.']); }
function sumai_ajax_test_feeds() { check_ajax_referer('sumai_test_feeds_nonce'); if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Denied.'],403); $options = get_option(SUMAI_SETTINGS_OPTION, []); $urls = empty($options['feed_urls']) ? [] : array_slice(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$options['feed_urls']))), 0, SUMAI_MAX_FEED_URLS); if (empty($urls)) { wp_send_json_error(['message'=>'No feeds configured.']); return; } if (!function_exists('fetch_feed')){ include_once ABSPATH.WPINC.'/feed.php'; if (!function_exists('fetch_feed')) { wp_send_json_error(['message'=>'WP feed functions unavailable.']); return; } } $output = sumai_test_feeds($urls); wp_send_json_success(['message'=>'<pre>'.esc_html($output).'</pre>']); }

/* -------------------------------------------------------------------------
 * 10. LOGGING & DEBUGGING (Restored)
 * ------------------------------------------------------------------------- */

function sumai_test_feeds(array $feed_urls): string { if (!function_exists('fetch_feed')) return "Error: fetch_feed unavailable."; $out = "--- Feed Test Results ---\nTime: ".wp_date('Y-m-d H:i:s T')."\n"; $guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $out .= count($guids)." processed GUIDs.\n\n"; $new=false; $items_total=0; $new_total=0; foreach ($feed_urls as $i=>$url) { $out .= "--- Feed #".($i+1).": {$url} ---\n"; $feed = fetch_feed($url); if (is_wp_error($feed)) { $out .= "❌ Err: ".esc_html($feed->get_error_message())."\n\n"; continue; } $items = $feed->get_items(0, SUMAI_FEED_ITEM_LIMIT); $count = count($items); $items_total += $count; if (empty($items)) { $out .= "⚠️ OK but no items.\n\n"; continue; } $out .= "✅ OK: {$count} items:\n"; $feed_new = false; foreach ($items as $idx => $item) { $guid = $item->get_id(true); $title = mb_strimwidth(strip_tags($item->get_title()?:'N/A'),0,80,'...'); $out .= "- ".($idx+1).": ".esc_html($title)."\n"; if (isset($guids[$guid])) $out .= "  Status: Processed\n"; else { $out .= "  Status: ✨ NEW\n"; $feed_new=true; $new=true; $new_total++; } } if (!$feed_new && $count>0) $out .= "  ℹ️ No new items.\n"; $out .= "\n"; unset($feed,$items); } $out .= "--- Summary ---\nChecked ".count($feed_urls)." feeds, {$items_total} items.\n".($new?"✅ Detected {$new_total} NEW items.":"ℹ️ No new content."); return $out; }
function sumai_ensure_log_dir(): ?string { static $path=null,$chk=false; if($chk)return $path; $chk=true; $up=wp_upload_dir(); if(!empty($up['error']))return null; $dir=trailingslashit($up['basedir']).SUMAI_LOG_DIR_NAME; $file=trailingslashit($dir).SUMAI_LOG_FILE_NAME; if(!is_dir($dir)){if(!wp_mkdir_p($dir))return null; @file_put_contents($dir.'/.htaccess',"Options -Indexes\nDeny from all"); @file_put_contents($dir.'/index.php','<?php // Silence');} if(!is_writable($dir))return null; if(!file_exists($file)){if(false===@file_put_contents($file,''))return null; @chmod($file,0644);} if(!is_writable($file))return null; return $path=$file; }
// ** Restored Log Event - Does NOT accept path argument **
function sumai_log_event(string $msg, bool $is_error=false) { $file=sumai_ensure_log_dir(); if(!$file){error_log("Sumai ".($is_error?'[ERR]':'[INFO]')."(Log N/A): ".$msg); return;} $ts=wp_date('Y-m-d H:i:s T'); $lvl=$is_error?' [ERROR] ':' [INFO]  '; $line='['.$ts.']'.$lvl.trim(preg_replace('/\s+/',' ',wp_strip_all_tags($msg))).PHP_EOL; @file_put_contents($file,$line,FILE_APPEND|LOCK_EX); }
function sumai_prune_logs() { $file=sumai_ensure_log_dir(); if(!$file||!is_readable($file)||!is_writable($file)) return; $lines=@file($file,4|2); if(empty($lines))return; $cutoff=time()-SUMAI_LOG_TTL; $keep=[]; $pruned=0; foreach($lines as $line){$ts=false; if(preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [A-Z\/+-\w\:]+)\]/',$line,$m)){try{$dt=new DateTime($m[1]);$ts=$dt->getTimestamp();}catch(Exception $e){$ts=strtotime($m[1]);}} if($ts!==false&&$ts>=$cutoff)$keep[]=$line; else $pruned++;} if($pruned>0){$new_content=empty($keep)?'':implode(PHP_EOL,$keep).PHP_EOL; @file_put_contents($file,$new_content,LOCK_EX); } }

// ** Restored Debug Info Functions (More Robust) **
function sumai_get_debug_info(): array {
    $debug_info = []; $options = get_option(SUMAI_SETTINGS_OPTION, []); $debug_info['settings'] = $options; $debug_info['settings']['api_key'] = (defined('SUMAI_OPENAI_API_KEY')&&!empty(SUMAI_OPENAI_API_KEY))?'*** Constant ***':(!empty($options['api_key'])?'*** DB Set ***':'*** Not Set ***');
    $cron_jobs = _get_cron_array()?:[]; $debug_info['cron_jobs'] = []; $has_sumai_jobs = false; foreach ($cron_jobs as $time => $hooks) { foreach ([SUMAI_CRON_HOOK, SUMAI_ROTATE_TOKEN_HOOK, SUMAI_PRUNE_LOGS_HOOK] as $hook_name) { if (isset($hooks[$hook_name])) { $has_sumai_jobs = true; $event_key = key($hooks[$hook_name]); $schedule_details = $hooks[$hook_name][$event_key]; $schedule_name = $schedule_details['schedule'] ?? '(One-off?)'; $interval = isset($schedule_details['interval']) ? ($schedule_details['interval'] . 's') : 'N/A'; $debug_info['cron_jobs'][$hook_name] = ['next_run_gmt' => gmdate('Y-m-d H:i:s', $time), 'next_run_site' => wp_date('Y-m-d H:i:s T', $time), 'schedule_name' => $schedule_name, 'interval' => $interval ]; } } } if (!$has_sumai_jobs) $debug_info['cron_jobs'] = 'No Sumai tasks scheduled.';
    $log_file = sumai_ensure_log_dir(); $debug_info['log_file_path'] = $log_file ?: 'ERROR: Log path fail.'; $debug_info['log_writable'] = $log_file && is_writable($log_file); $debug_info['log_readable'] = $log_file && is_readable($log_file); $debug_info['log_size_kb'] = ($debug_info['log_readable'] && file_exists($log_file)) ? round(filesize($log_file)/1024, 2) : 'N/A';
    if ($debug_info['log_readable']) { $log_content = ($debug_info['log_size_kb'] > 0) ? @file_get_contents($log_file, false, null, -10240) : ''; $debug_info['recent_logs'] = ($log_content!==false)?array_slice(explode("\n", trim($log_content)), -50):['Error reading log.']; } else { $debug_info['recent_logs'] = ['Log not readable.']; }
    $processed_guids = get_option(SUMAI_PROCESSED_GUIDS_OPTION, []); $debug_info['guids_count'] = count($processed_guids); $debug_info['guids_newest'] = empty($processed_guids)?'N/A':wp_date('Y-m-d H:i:s T', max($processed_guids)); $debug_info['guids_oldest'] = empty($processed_guids)?'N/A':wp_date('Y-m-d H:i:s T', min($processed_guids));
    global $wp_version; $wp_cron_disabled = defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON; $debug_info['system'] = ['plugin_v' => get_file_data(__FILE__, ['Version'=>'Version'])['Version'], 'php_v' => phpversion(), 'wp_v' => $wp_version, 'wp_tz' => wp_timezone_string(), 'wp_debug' => (defined('WP_DEBUG')&&WP_DEBUG?'Yes':'No'), 'wp_mem' => WP_MEMORY_LIMIT, 'wp_cron' => $wp_cron_disabled?'Disabled':'Enabled', 'server_time' => wp_date('Y-m-d H:i:s T'), 'openssl' => extension_loaded('openssl')?'Yes':'No', 'auth_key' => (defined('AUTH_KEY')&&AUTH_KEY?'Yes':'No'), 'curl' => extension_loaded('curl')?'Yes':'No', 'mbstring' => extension_loaded('mbstring')?'Yes':'No'];
    return $debug_info;
}
function sumai_render_debug_info() { $d = sumai_get_debug_info(); echo '<div><style>td{vertical-align:top;}pre{white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:5px;border:1px solid #ccc;margin:0;font-size:12px;max-height:200px;overflow-y:auto;}</style><h3>Settings</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($d['settings'] as $k=>$v) echo '<tr><td width="30%">'.esc_html(ucwords(str_replace('_',' ',$k))).'</td><td><pre>'.esc_html(is_array($v)?print_r($v,true):$v).'</pre></td></tr>'; echo '</tbody></table><h3>Processed Items</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Count</td><td>'.esc_html($d['guids_count']).'</td></tr><tr><td>Newest</td><td>'.esc_html($d['guids_newest']).'</td></tr><tr><td>Oldest</td><td>'.esc_html($d['guids_oldest']).' (TTL: '.esc_html(SUMAI_PROCESSED_GUID_TTL/DAY_IN_SECONDS).'d)</td></tr></tbody></table><h3>Scheduled Tasks</h3>'; if(is_array($d['cron_jobs'])&&!empty($d['cron_jobs'])){echo '<table class="wp-list-table fixed striped"><thead><tr><th>Hook</th><th>Next Run (Site)</th><th>Schedule</th></tr></thead><tbody>'; foreach($d['cron_jobs'] as $h=>$det) echo '<tr><td><code>'.esc_html($h).'</code></td><td>'.esc_html($det['next_run_site']).'</td><td>'.esc_html($det['schedule_name']).'</td></tr>'; echo '</tbody></table>';} else echo '<p>'.esc_html($d['cron_jobs']).'</p>'; echo '<h3>System Info</h3><table class="wp-list-table fixed striped"><tbody>'; foreach($d['system'] as $k=>$v):?><tr><td width="30%"><strong><?=esc_html(ucwords(str_replace('_',' ',$k)))?></strong></td><td><?=wp_kses_post($v)?></td></tr><?php endforeach; echo '</tbody></table><h3>Logging</h3><table class="wp-list-table fixed striped"><tbody><tr><td width="30%">Path</td><td><code>'.esc_html($d['log_file_path']).'</code></td></tr><tr><td>Status</td><td>'.($d['log_readable']?'Readable':'Not Readable').', '.($d['log_writable']?'Writable':'Not Writable').' (Size: '.esc_html($d['log_size_kb']).' KB)</td></tr></tbody></table><h4>Recent Logs (tail ~50)</h4><div style="max-height:400px;overflow-y:auto;background:#1e1e1e;color:#d4d4d4;border:1px solid #3c3c3c;padding:10px;font-family:monospace;white-space:pre-wrap;word-break:break-word;">'.esc_html(implode("\n",$d['recent_logs'])).'</div></div>'; }

?>