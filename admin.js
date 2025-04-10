/**
 * Sumai Admin JavaScript
 * Handles AJAX requests and UI updates for the Sumai plugin
 */
(function($) {
    'use strict';

    // Status update interval in milliseconds
    const STATUS_UPDATE_INTERVAL = 3000;
    
    // Status polling variables
    let statusPollingInterval = null;
    let currentStatusId = null;
    
    // Initialize when document is ready
    $(document).ready(function() {
        // Initialize tabs
        initTabs();
        
        // Initialize manual generation
        initManualGeneration();
        
        // Initialize feed testing
        initFeedTesting();
    });
    
    /**
     * Initialize tab functionality
     */
    function initTabs() {
        var $tabs = $('#sumai-tabs');
        var $links = $tabs.find('.nav-tab');
        var $content = $tabs.find('.tab-content');
        
        function showTab(hash) {
            hash = hash || localStorage.getItem('sumaiActiveTab') || $links.first().attr('href');
            $links.removeClass('nav-tab-active');
            $content.hide();
            
            var $link = $links.filter('[href="' + hash + '"]');
            if (!$link.length) {
                $link = $links.first();
                hash = $link.attr('href');
            }
            
            $link.addClass('nav-tab-active');
            $(hash).show();
            
            try {
                localStorage.setItem('sumaiActiveTab', hash);
            } catch (e) {
                // Local storage not available
            }
        }
        
        $links.on('click', function(e) {
            e.preventDefault();
            showTab($(this).attr('href'));
        });
        
        showTab(window.location.hash || localStorage.getItem('sumaiActiveTab'));
    }
    
    /**
     * Initialize manual generation functionality
     */
    function initManualGeneration() {
        // Add status container after the generate button
        $('form[name="sumai_generate_now"]').after(
            '<div id="sumai-status-container" style="display:none; margin-top: 15px;">' +
            '<div class="sumai-status-header" style="margin-bottom: 10px; font-weight: bold;">Generation Status:</div>' +
            '<div class="sumai-status-message" style="padding: 10px; background: #f8f8f8; border-left: 4px solid #2271b1;"></div>' +
            '</div>'
        );
        
        // Replace the form submission with AJAX
        $('form[name="sumai_generate_now"]').on('submit', function(e) {
            e.preventDefault();
            
            // Get draft mode value
            const draftMode = $('input[name="' + sumaiAdmin.settingsOption + '[draft_mode]"]:checked').val() === '1';
            
            // Disable the button and show loading state
            const $button = $(this).find('input[type="submit"]');
            const originalText = $button.val();
            $button.prop('disabled', true).val('Processing...');
            
            // Show the status container with initial message
            $('#sumai-status-container').show();
            $('.sumai-status-message').html('<span class="spinner is-active" style="float:left; margin-right:10px;"></span> Starting generation process...');
            
            // Make the AJAX request
            $.ajax({
                url: sumaiAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_manual_generate',
                    nonce: sumaiAdmin.nonce,
                    draft_mode: draftMode
                },
                success: function(response) {
                    if (response.success) {
                        // Store the status ID and start polling
                        currentStatusId = response.data.status_id;
                        startStatusPolling();
                    } else {
                        // Show error message
                        $('.sumai-status-message').html('<span style="color: #d63638;">❌ Error: ' + (response.data || 'Unknown error') + '</span>');
                        $button.prop('disabled', false).val(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    $('.sumai-status-message').html('<span style="color: #d63638;">❌ AJAX request failed. Please try again.</span>');
                    $button.prop('disabled', false).val(originalText);
                }
            });
        });
    }
    
    /**
     * Start polling for status updates
     */
    function startStatusPolling() {
        // Clear any existing interval
        if (statusPollingInterval) {
            clearInterval(statusPollingInterval);
        }
        
        // Set up new polling interval
        statusPollingInterval = setInterval(function() {
            if (!currentStatusId) {
                clearInterval(statusPollingInterval);
                return;
            }
            
            // Make AJAX request to get status
            $.ajax({
                url: sumaiAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_get_generation_status',
                    nonce: sumaiAdmin.nonce,
                    status_id: currentStatusId
                },
                success: function(response) {
                    if (response.success) {
                        updateStatusUI(response.data);
                        
                        // If status is complete or error, stop polling
                        if (response.data.status === 'complete' || response.data.status === 'error') {
                            clearInterval(statusPollingInterval);
                            statusPollingInterval = null;
                            
                            // Re-enable the button
                            $('form[name="sumai_generate_now"] input[type="submit"]').prop('disabled', false).val('Generate Now');
                        }
                    } else {
                        // If status not found, stop polling
                        clearInterval(statusPollingInterval);
                        statusPollingInterval = null;
                        
                        // Update UI with error
                        $('.sumai-status-message').html('<span style="color: #d63638;">❌ ' + (response.data || 'Status not found') + '</span>');
                        
                        // Re-enable the button
                        $('form[name="sumai_generate_now"] input[type="submit"]').prop('disabled', false).val('Generate Now');
                    }
                },
                error: function() {
                    // If AJAX fails, keep polling but show error
                    $('.sumai-status-message').html('<span style="color: #d63638;">❌ Failed to get status update. Retrying...</span>');
                }
            });
        }, STATUS_UPDATE_INTERVAL);
    }
    
    /**
     * Update the status UI based on the current status
     */
    function updateStatusUI(statusData) {
        const $statusMessage = $('.sumai-status-message');
        let statusHtml = '';
        
        // Set color based on status
        let borderColor = '#2271b1'; // Default blue
        
        switch (statusData.status) {
            case 'pending':
                borderColor = '#f0c33c'; // Yellow
                statusHtml = '<span class="spinner is-active" style="float:left; margin-right:10px;"></span> ';
                break;
            case 'processing':
                borderColor = '#2271b1'; // Blue
                statusHtml = '<span class="spinner is-active" style="float:left; margin-right:10px;"></span> ';
                break;
            case 'complete':
                borderColor = '#46b450'; // Green
                statusHtml = '✅ ';
                break;
            case 'error':
                borderColor = '#d63638'; // Red
                statusHtml = '❌ ';
                break;
        }
        
        // Add the message
        statusHtml += statusData.message;
        
        // Update the UI
        $statusMessage.html(statusHtml).css('border-left-color', borderColor);
    }
    
    /**
     * Initialize feed testing functionality
     */
    function initFeedTesting() {
        $('#test-feed-btn').on('click', function() {
            var $button = $(this);
            var $result = $('#feed-test-res');
            
            $button.prop('disabled', true).text('...');
            $result.html('<span class="spinner is-active"></span>').css('color', '').show();
            
            $.post(
                sumaiAdmin.ajaxurl,
                {
                    action: 'sumai_test_feed',
                    nonce: sumaiAdmin.nonce
                },
                function(response) {
                    if (response.success) {
                        $result.html(response.data.message).css('color', '');
                    } else {
                        $result.html('❌ Error: ' + response.data.message).css('color', '#d63638');
                    }
                },
                'json'
            ).fail(function() {
                $result.html('❌ AJAX Error');
            }).always(function() {
                $button.prop('disabled', false).text('Test');
            });
        });
    }
    
})(jQuery);
