<?php
/**
 * Custom AI prompt management functionality for the Sumai plugin.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the custom prompt management hooks.
 */
function sumai_register_prompt_hooks() {
    add_action('admin_init', 'sumai_register_prompt_settings');
    add_action('wp_ajax_sumai_save_prompt_template', 'sumai_ajax_save_prompt_template');
    add_action('wp_ajax_sumai_delete_prompt_template', 'sumai_ajax_delete_prompt_template');
}
add_action('init', 'sumai_register_prompt_hooks');

/**
 * Registers the prompt settings.
 */
function sumai_register_prompt_settings() {
    register_setting(
        'sumai_options_group',
        'sumai_prompt_templates',
        [
            'sanitize_callback' => 'sumai_sanitize_prompt_templates',
            'default' => []
        ]
    );
}

/**
 * Sanitizes the prompt templates.
 *
 * @param array $templates The prompt templates to sanitize.
 * @return array The sanitized prompt templates.
 */
function sumai_sanitize_prompt_templates($templates) {
    if (!is_array($templates)) {
        return [];
    }
    
    $sanitized = [];
    foreach ($templates as $template) {
        if (!isset($template['name']) || !isset($template['content']) || !isset($template['type'])) {
            continue;
        }
        
        $sanitized[] = [
            'id' => isset($template['id']) ? sanitize_key($template['id']) : sanitize_key(uniqid('prompt_')),
            'name' => sanitize_text_field($template['name']),
            'content' => wp_kses_post($template['content']),
            'type' => in_array($template['type'], ['summary', 'title', 'custom']) ? $template['type'] : 'custom',
            'created' => isset($template['created']) ? sanitize_text_field($template['created']) : current_time('mysql')
        ];
    }
    
    return $sanitized;
}

/**
 * Gets the prompt templates.
 *
 * @return array The prompt templates.
 */
function sumai_get_prompt_templates() {
    $templates = get_option('sumai_prompt_templates', []);
    
    // Add default templates if none exist
    if (empty($templates)) {
        $templates = sumai_get_default_prompt_templates();
        update_option('sumai_prompt_templates', $templates);
    }
    
    return $templates;
}

/**
 * Gets the default prompt templates.
 *
 * @return array The default prompt templates.
 */
function sumai_get_default_prompt_templates() {
    $current_time = current_time('mysql');
    
    return [
        [
            'id' => 'default_summary',
            'name' => 'Default Summary',
            'content' => 'Summarize this article in a concise and informative way. Focus on the key points and main takeaways. Use a professional tone and avoid unnecessary details.',
            'type' => 'summary',
            'created' => $current_time
        ],
        [
            'id' => 'default_title',
            'name' => 'Default Title',
            'content' => 'Create a compelling and descriptive title for this article summary that accurately reflects its content.',
            'type' => 'title',
            'created' => $current_time
        ],
        [
            'id' => 'technical_summary',
            'name' => 'Technical Summary',
            'content' => 'Provide a technical summary of this article, focusing on specifications, methodologies, and technical details. Use precise language and maintain technical accuracy.',
            'type' => 'summary',
            'created' => $current_time
        ],
        [
            'id' => 'news_summary',
            'name' => 'News Summary',
            'content' => 'Summarize this news article in an objective and factual manner. Include key events, people, places, and the significance of the news. Maintain journalistic integrity.',
            'type' => 'summary',
            'created' => $current_time
        ]
    ];
}

/**
 * Validates a prompt template.
 *
 * @param array $template The template to validate.
 * @return array Validation results with 'valid' boolean and 'errors' array.
 */
function sumai_validate_prompt_template($template) {
    $result = [
        'valid' => true,
        'errors' => []
    ];
    
    // Check required fields
    if (empty($template['name'])) {
        $result['valid'] = false;
        $result['errors'][] = 'Template name is required.';
    }
    
    if (empty($template['content'])) {
        $result['valid'] = false;
        $result['errors'][] = 'Template content is required.';
    } else if (strlen($template['content']) > 500) {
        $result['valid'] = false;
        $result['errors'][] = 'Template content must be less than 500 characters.';
    }
    
    if (empty($template['type']) || !in_array($template['type'], ['summary', 'title', 'custom'])) {
        $result['valid'] = false;
        $result['errors'][] = 'Invalid template type.';
    }
    
    return $result;
}

/**
 * AJAX handler for saving a prompt template.
 */
function sumai_ajax_save_prompt_template() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sumai_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
    
    // Get and validate template data
    $template = [
        'id' => isset($_POST['id']) ? sanitize_key($_POST['id']) : sanitize_key(uniqid('prompt_')),
        'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
        'content' => isset($_POST['content']) ? wp_kses_post($_POST['content']) : '',
        'type' => isset($_POST['type']) && in_array($_POST['type'], ['summary', 'title', 'custom']) ? $_POST['type'] : 'custom',
        'created' => current_time('mysql')
    ];
    
    // Validate template
    $validation = sumai_validate_prompt_template($template);
    if (!$validation['valid']) {
        wp_send_json_error(['message' => implode(' ', $validation['errors'])]);
    }
    
    // Get existing templates
    $templates = sumai_get_prompt_templates();
    
    // Check if we're updating an existing template
    $updated = false;
    foreach ($templates as $key => $existing_template) {
        if ($existing_template['id'] === $template['id']) {
            $templates[$key] = $template;
            $updated = true;
            break;
        }
    }
    
    // If not updating, add as new
    if (!$updated) {
        $templates[] = $template;
    }
    
    // Save templates
    update_option('sumai_prompt_templates', $templates);
    
    // Log the event
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Prompt template ' . ($updated ? 'updated' : 'created') . ': ' . $template['name']);
    }
    
    wp_send_json_success([
        'message' => 'Prompt template saved successfully.',
        'template' => $template,
        'templates' => $templates
    ]);
}

/**
 * AJAX handler for deleting a prompt template.
 */
function sumai_ajax_delete_prompt_template() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sumai_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }
    
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
    
    // Get template ID
    $template_id = isset($_POST['id']) ? sanitize_key($_POST['id']) : '';
    if (empty($template_id)) {
        wp_send_json_error(['message' => 'Template ID is required.']);
    }
    
    // Get existing templates
    $templates = sumai_get_prompt_templates();
    
    // Find and remove the template
    $template_name = '';
    $found = false;
    foreach ($templates as $key => $template) {
        if ($template['id'] === $template_id) {
            $template_name = $template['name'];
            unset($templates[$key]);
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        wp_send_json_error(['message' => 'Template not found.']);
    }
    
    // Re-index the array
    $templates = array_values($templates);
    
    // Save templates
    update_option('sumai_prompt_templates', $templates);
    
    // Log the event
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Prompt template deleted: ' . $template_name);
    }
    
    wp_send_json_success([
        'message' => 'Prompt template deleted successfully.',
        'templates' => $templates
    ]);
}

/**
 * Renders the prompt management UI.
 */
function sumai_render_prompt_management() {
    $templates = sumai_get_prompt_templates();
    ?>
    <div class="sumai-prompt-management">
        <h2>Custom AI Prompts</h2>
        <p>Create and manage custom prompts for AI-generated summaries and titles.</p>
        
        <div class="sumai-prompt-templates">
            <h3>Prompt Templates</h3>
            
            <div class="sumai-prompt-list">
                <?php if (empty($templates)): ?>
                    <p>No prompt templates found. Create your first template below.</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Content</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr data-id="<?php echo esc_attr($template['id']); ?>">
                                    <td><?php echo esc_html($template['name']); ?></td>
                                    <td><?php echo esc_html(ucfirst($template['type'])); ?></td>
                                    <td><?php echo esc_html(substr($template['content'], 0, 50) . (strlen($template['content']) > 50 ? '...' : '')); ?></td>
                                    <td>
                                        <button type="button" class="button button-small sumai-edit-prompt" data-id="<?php echo esc_attr($template['id']); ?>">Edit</button>
                                        <button type="button" class="button button-small sumai-delete-prompt" data-id="<?php echo esc_attr($template['id']); ?>">Delete</button>
                                        <button type="button" class="button button-small sumai-use-prompt" data-id="<?php echo esc_attr($template['id']); ?>" data-type="<?php echo esc_attr($template['type']); ?>">Use</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="sumai-prompt-form" style="margin-top: 20px; background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">
                <h3 id="sumai-prompt-form-title">Add New Prompt Template</h3>
                
                <input type="hidden" id="sumai-prompt-id" value="">
                
                <div class="sumai-form-row" style="margin-bottom: 15px;">
                    <label for="sumai-prompt-name" style="display: block; margin-bottom: 5px; font-weight: bold;">Template Name:</label>
                    <input type="text" id="sumai-prompt-name" class="regular-text" placeholder="Enter a name for this template">
                </div>
                
                <div class="sumai-form-row" style="margin-bottom: 15px;">
                    <label for="sumai-prompt-type" style="display: block; margin-bottom: 5px; font-weight: bold;">Template Type:</label>
                    <select id="sumai-prompt-type">
                        <option value="summary">Summary Prompt</option>
                        <option value="title">Title Prompt</option>
                        <option value="custom">Custom Prompt</option>
                    </select>
                </div>
                
                <div class="sumai-form-row" style="margin-bottom: 15px;">
                    <label for="sumai-prompt-content" style="display: block; margin-bottom: 5px; font-weight: bold;">Template Content:</label>
                    <textarea id="sumai-prompt-content" rows="4" class="large-text" placeholder="Enter the prompt template content"></textarea>
                    <p class="description">Maximum 500 characters. Use clear instructions for the AI to generate the desired output.</p>
                </div>
                
                <div class="sumai-form-row">
                    <button type="button" id="sumai-save-prompt" class="button button-primary">Save Template</button>
                    <button type="button" id="sumai-cancel-prompt" class="button button-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Save prompt template
        $('#sumai-save-prompt').on('click', function() {
            var id = $('#sumai-prompt-id').val();
            var name = $('#sumai-prompt-name').val();
            var type = $('#sumai-prompt-type').val();
            var content = $('#sumai-prompt-content').val();
            
            if (!name) {
                alert('Please enter a template name.');
                return;
            }
            
            if (!content) {
                alert('Please enter template content.');
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_save_prompt_template',
                    nonce: sumaiAdmin.nonce,
                    id: id,
                    name: name,
                    type: type,
                    content: content
                },
                beforeSend: function() {
                    $('#sumai-save-prompt').prop('disabled', true).text('Saving...');
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                },
                complete: function() {
                    $('#sumai-save-prompt').prop('disabled', false).text('Save Template');
                }
            });
        });
        
        // Edit prompt template
        $('.sumai-edit-prompt').on('click', function() {
            var id = $(this).data('id');
            var row = $(this).closest('tr');
            
            // Find the template in the list
            var templates = <?php echo json_encode($templates); ?>;
            var template = templates.find(function(t) { return t.id === id; });
            
            if (template) {
                $('#sumai-prompt-form-title').text('Edit Prompt Template');
                $('#sumai-prompt-id').val(template.id);
                $('#sumai-prompt-name').val(template.name);
                $('#sumai-prompt-type').val(template.type);
                $('#sumai-prompt-content').val(template.content);
                
                // Scroll to form
                $('html, body').animate({
                    scrollTop: $('.sumai-prompt-form').offset().top - 50
                }, 500);
            }
        });
        
        // Delete prompt template
        $('.sumai-delete-prompt').on('click', function() {
            if (!confirm('Are you sure you want to delete this template?')) {
                return;
            }
            
            var id = $(this).data('id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_delete_prompt_template',
                    nonce: sumaiAdmin.nonce,
                    id: id
                },
                beforeSend: function() {
                    $(this).prop('disabled', true).text('Deleting...');
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                },
                complete: function() {
                    $(this).prop('disabled', false).text('Delete');
                }
            });
        });
        
        // Use prompt template
        $('.sumai-use-prompt').on('click', function() {
            var id = $(this).data('id');
            var type = $(this).data('type');
            
            // Find the template in the list
            var templates = <?php echo json_encode($templates); ?>;
            var template = templates.find(function(t) { return t.id === id; });
            
            if (template) {
                if (type === 'summary') {
                    $('#sumai-context-prompt').val(template.content);
                    alert('Summary prompt template applied. Remember to save your settings!');
                } else if (type === 'title') {
                    $('#sumai-title-prompt').val(template.content);
                    alert('Title prompt template applied. Remember to save your settings!');
                } else {
                    alert('This prompt type cannot be directly applied to settings.');
                }
                
                // Switch to settings tab
                if (type === 'summary' || type === 'title') {
                    $('a[href="#tab-settings"]').click();
                }
            }
        });
        
        // Cancel editing
        $('#sumai-cancel-prompt').on('click', function() {
            $('#sumai-prompt-form-title').text('Add New Prompt Template');
            $('#sumai-prompt-id').val('');
            $('#sumai-prompt-name').val('');
            $('#sumai-prompt-type').val('summary');
            $('#sumai-prompt-content').val('');
        });
    });
    </script>
    <?php
}
