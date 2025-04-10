<?php
/**
 * Constants for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'SUMAI_SETTINGS_OPTION', 'sumai_settings' );
define( 'SUMAI_PROCESSED_GUIDS_OPTION', 'sumai_processed_guids' );
define( 'SUMAI_PROCESSED_HASHES_OPTION', 'sumai_processed_hashes' );
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
define( 'SUMAI_PROCESS_CONTENT_ACTION', 'sumai_process_content_action' );
define( 'SUMAI_MANUAL_GENERATE_ACTION', 'sumai_manual_generate_action' );
define( 'SUMAI_STATUS_OPTION', 'sumai_generation_status' );
define( 'SUMAI_STATUS_TRANSIENT', 'sumai_status_' );
define( 'SUMAI_VERSION', '1.0.3' );
define( 'SUMAI_ACTIVATION_ERRORS_OPTION', 'sumai_activation_errors' );
define( 'SUMAI_DEPENDENCY_CHECK_OPTION', 'sumai_dependency_check' );

// Retry configuration for API calls
define( 'SUMAI_MAX_RETRIES', 3 );
define( 'SUMAI_RETRY_DELAY', 5 * MINUTE_IN_SECONDS );
define( 'SUMAI_RETRY_ACTION', 'sumai_retry_content_action' );