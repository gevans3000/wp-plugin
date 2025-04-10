<?php
/**
 * AI Templates for Sumai Plugin
 *
 * This file contains template code snippets that can be used by AI assistants
 * when developing or modifying the Sumai plugin. These templates follow the
 * project's coding standards and architectural patterns.
 *
 * @package Sumai
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TEMPLATE: Standard Function Declaration
 *
 * Use this template when creating new functions for the plugin.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_standard_function' ) ) {
	/**
	 * [Function description]
	 *
	 * @since 1.0.0
	 * @param [type] $param1 Description of parameter 1.
	 * @param [type] $param2 Description of parameter 2.
	 * @return [type] Description of return value.
	 */
	function sumai_template_standard_function( $param1, $param2 ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_standard_function', false ) ) {
			return;
		}
		
		// Function implementation
		$result = null;
		
		// Error handling
		if ( empty( $param1 ) ) {
			sumai_log_event( 'error', 'Invalid parameter provided to sumai_template_standard_function' );
			return false;
		}
		
		// Process logic
		
		// Return result
		return $result;
	}
}

/**
 * TEMPLATE: Hook Registration
 *
 * Use this template when registering WordPress hooks.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_register_hooks' ) ) {
	/**
	 * Register hooks for a specific component
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_register_hooks() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_register_hooks', false ) ) {
			return;
		}
		
		// Action hooks
		add_action( 'hook_name', 'callback_function_name' );
		add_action( 'hook_name_with_priority', 'callback_function_name', 10, 2 );
		
		// Filter hooks
		add_filter( 'filter_name', 'filter_callback_function_name' );
		add_filter( 'filter_name_with_priority', 'filter_callback_function_name', 10, 2 );
	}
}

/**
 * TEMPLATE: Admin Page Tab
 *
 * Use this template when adding a new tab to the admin interface.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_admin_tab' ) ) {
	/**
	 * Register a new tab in the Sumai admin interface
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_admin_tab() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_admin_tab', false ) ) {
			return;
		}
		
		// Register the tab
		add_filter( 'sumai_admin_tabs', function( $tabs ) {
			$tabs['tab_id'] = 'Tab Label';
			return $tabs;
		});
		
		// Register the tab content
		add_action( 'sumai_admin_tab_content_tab_id', 'sumai_template_render_tab_content' );
	}
}

/**
 * TEMPLATE: Admin Tab Content
 *
 * Use this template when creating content for a new admin tab.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_render_tab_content' ) ) {
	/**
	 * Render the content for an admin tab
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_render_tab_content() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_render_tab_content', false ) ) {
			return;
		}
		
		// Security check
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Tab content implementation
		?>
		<div class="sumai-admin-section">
			<h2><?php esc_html_e( 'Section Title', 'sumai' ); ?></h2>
			
			<p><?php esc_html_e( 'Section description text.', 'sumai' ); ?></p>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'sumai_settings_group' );
				do_settings_sections( 'sumai_tab_id_section' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

/**
 * TEMPLATE: Settings Registration
 *
 * Use this template when registering new settings.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_register_settings' ) ) {
	/**
	 * Register settings for the plugin
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_register_settings() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_register_settings', false ) ) {
			return;
		}
		
		// Register setting
		register_setting(
			'sumai_settings_group',
			'sumai_setting_name',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		
		// Add settings section
		add_settings_section(
			'sumai_section_id',
			__( 'Section Title', 'sumai' ),
			'sumai_template_section_callback',
			'sumai_tab_id_section'
		);
		
		// Add settings field
		add_settings_field(
			'sumai_field_id',
			__( 'Field Label', 'sumai' ),
			'sumai_template_field_callback',
			'sumai_tab_id_section',
			'sumai_section_id'
		);
	}
}

/**
 * TEMPLATE: Settings Section Callback
 *
 * Use this template for settings section descriptions.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_section_callback' ) ) {
	/**
	 * Render the settings section description
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_section_callback() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_section_callback', false ) ) {
			return;
		}
		
		echo '<p>' . esc_html__( 'Section description text.', 'sumai' ) . '</p>';
	}
}

/**
 * TEMPLATE: Settings Field Callback
 *
 * Use this template for rendering settings fields.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_field_callback' ) ) {
	/**
	 * Render a settings field
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_field_callback() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_field_callback', false ) ) {
			return;
		}
		
		$value = get_option( 'sumai_setting_name', '' );
		?>
		<input type="text" name="sumai_setting_name" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
		<p class="description"><?php esc_html_e( 'Field description text.', 'sumai' ); ?></p>
		<?php
	}
}

/**
 * TEMPLATE: AJAX Handler
 *
 * Use this template when creating AJAX handlers.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_ajax_handler' ) ) {
	/**
	 * Handle AJAX requests
	 *
	 * @since 1.0.0
	 * @return void
	 */
	function sumai_template_ajax_handler() {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_ajax_handler', false ) ) {
			return;
		}
		
		// Security check
		check_ajax_referer( 'sumai_nonce', 'nonce' );
		
		// Permission check
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'sumai' ) ) );
		}
		
		// Get parameters
		$param = isset( $_POST['param'] ) ? sanitize_text_field( wp_unslash( $_POST['param'] ) ) : '';
		
		// Validate parameters
		if ( empty( $param ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'sumai' ) ) );
		}
		
		// Process the request
		$result = array(
			'success' => true,
			'data'    => 'Result data',
		);
		
		// Send response
		wp_send_json_success( $result );
	}
}

/**
 * TEMPLATE: Fallback Function
 *
 * Use this template when creating fallback mechanisms.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_fallback_function' ) ) {
	/**
	 * Fallback implementation for a critical function
	 *
	 * @since 1.0.0
	 * @param [type] $param1 Description of parameter 1.
	 * @param [type] $param2 Description of parameter 2.
	 * @return [type] Description of return value.
	 */
	function sumai_template_fallback_function( $param1, $param2 ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_fallback_function', false ) ) {
			return;
		}
		
		// Log the fallback usage
		sumai_safe_function_call( 'sumai_log_event', array(
			'event'   => 'fallback_used',
			'message' => 'Using fallback for function_name',
			'level'   => 'warning',
		), null, false );
		
		// Simplified implementation
		$fallback_result = null;
		
		// Return result
		return $fallback_result;
	}
}

/**
 * TEMPLATE: Database Interaction
 *
 * Use this template when interacting with the WordPress database.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_database_function' ) ) {
	/**
	 * Perform database operations
	 *
	 * @since 1.0.0
	 * @param [type] $param Description of parameter.
	 * @return [type] Description of return value.
	 */
	function sumai_template_database_function( $param ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_database_function', false ) ) {
			return;
		}
		
		global $wpdb;
		
		// Prepare query with proper escaping
		$table_name = $wpdb->prefix . 'sumai_table';
		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE column = %s",
			$param
		);
		
		// Execute query
		$results = $wpdb->get_results( $query );
		
		// Check for errors
		if ( $wpdb->last_error ) {
			sumai_log_event( 'error', 'Database error: ' . $wpdb->last_error );
			return false;
		}
		
		return $results;
	}
}

/**
 * TEMPLATE: API Interaction
 *
 * Use this template when interacting with external APIs.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_api_function' ) ) {
	/**
	 * Interact with an external API
	 *
	 * @since 1.0.0
	 * @param [type] $param Description of parameter.
	 * @return [type] Description of return value.
	 */
	function sumai_template_api_function( $param ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_api_function', false ) ) {
			return;
		}
		
		// Prepare request
		$api_url = 'https://api.example.com/endpoint';
		$args = array(
			'timeout'     => 30,
			'redirection' => 5,
			'httpversion' => '1.1',
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . sumai_get_api_key(),
			),
			'body'        => wp_json_encode( array(
				'param' => $param,
			) ),
		);
		
		// Make request
		$response = wp_remote_post( $api_url, $args );
		
		// Check for errors
		if ( is_wp_error( $response ) ) {
			sumai_log_event( 'error', 'API error: ' . $response->get_error_message() );
			return false;
		}
		
		// Get response code
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			sumai_log_event( 'error', 'API error: Unexpected response code ' . $response_code );
			return false;
		}
		
		// Parse response
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			sumai_log_event( 'error', 'API error: Invalid JSON response' );
			return false;
		}
		
		return $data;
	}
}

/**
 * TEMPLATE: Scheduled Task
 *
 * Use this template when creating scheduled tasks with Action Scheduler.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_schedule_task' ) ) {
	/**
	 * Schedule a task using Action Scheduler
	 *
	 * @since 1.0.0
	 * @param [type] $param Description of parameter.
	 * @return [type] Description of return value.
	 */
	function sumai_template_schedule_task( $param ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_schedule_task', false ) ) {
			return;
		}
		
		// Check if Action Scheduler is available
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			sumai_log_event( 'error', 'Action Scheduler not available' );
			return false;
		}
		
		// Schedule the task
		$timestamp = time() + 3600; // 1 hour from now
		$hook = 'sumai_scheduled_task_hook';
		$args = array( 'param' => $param );
		
		$action_id = as_schedule_single_action( $timestamp, $hook, $args );
		
		if ( ! $action_id ) {
			sumai_log_event( 'error', 'Failed to schedule task' );
			return false;
		}
		
		return $action_id;
	}
}

/**
 * TEMPLATE: Scheduled Task Handler
 *
 * Use this template when creating handlers for scheduled tasks.
 * Replace placeholders with actual implementation.
 */
if ( ! function_exists( 'sumai_template_task_handler' ) ) {
	/**
	 * Handle a scheduled task
	 *
	 * @since 1.0.0
	 * @param [type] $param Description of parameter.
	 * @return void
	 */
	function sumai_template_task_handler( $param ) {
		// Check if function already exists to prevent redeclaration
		if ( ! sumai_function_not_exists( 'sumai_template_task_handler', false ) ) {
			return;
		}
		
		// Log task start
		sumai_log_event( 'info', 'Starting scheduled task with param: ' . $param );
		
		// Process the task
		
		// Log task completion
		sumai_log_event( 'info', 'Completed scheduled task with param: ' . $param );
	}
}
