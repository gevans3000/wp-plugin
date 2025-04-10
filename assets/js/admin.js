/**
 * Admin JavaScript functionality for the Sumai plugin.
 */
(function($) {
    'use strict';
    
    // Store status checking interval
    let statusCheckInterval = null;
    
    // Initialize tabs
    function initTabs() {
        const tabs = $('#sumai-tabs .nav-tab');
        const tabContents = $('.tab-content');
        
        tabs.on('click', function(e) {
            e.preventDefault();
            
            // Get target tab
            const target = $(this).attr('href');
            
            // Update active tab
            tabs.removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show target content
            tabContents.hide();
            $(target).show();
            
            // Update URL hash
            window.location.hash = target;
            
            // Load data for specific tabs
            if (target === '#tab-processed') {
                loadProcessedArticles();
            }
        });
        
        // Check if hash exists and activate tab
        if (window.location.hash) {
            const tab = $(`a[href="${window.location.hash}"]`);
            if (tab.length) {
                tab.trigger('click');
            }
        }
    }
    
    // Create progress bar
    function createProgressBar(container, initialProgress = 0) {
        const progressBar = `
            <div class="sumai-progress-container">
                <div class="sumai-progress-bar" style="width: ${initialProgress}%">
                    <div class="sumai-progress-text">${initialProgress}%</div>
                </div>
            </div>
        `;
        
        container.append(progressBar);
        return container.find('.sumai-progress-bar');
    }
    
    // Update progress bar
    function updateProgressBar(progressBar, percent, message = null) {
        progressBar.css('width', percent + '%');
        progressBar.find('.sumai-progress-text').text(percent + '%');
        
        if (message && progressBar.closest('.sumai-status').length) {
            // Update message if container has one
            let messageEl = progressBar.closest('.sumai-status').find('.sumai-message');
            if (!messageEl.length) {
                messageEl = $('<p class="sumai-message"></p>');
                progressBar.after(messageEl);
            }
            messageEl.html(message);
        }
    }
    
    // Test feeds with real-time progress updates
    function initTestFeeds() {
        $('#test-feed-btn').on('click', function() {
            const button = $(this);
            const resultsContainer = $('#feed-test-res');
            
            // Disable button
            button.prop('disabled', true).text('Testing...');
            resultsContainer.empty().show();
            
            // Create status container
            const statusContainer = $('<div class="sumai-status pending"></div>');
            resultsContainer.append(statusContainer);
            
            statusContainer.html('<p>Initializing feed test...</p>');
            
            // Create progress bar
            const progressBar = createProgressBar(statusContainer, 0);
            
            // Track if we've received the final response
            let finalResponseReceived = false;
            
            // Function to handle progress updates
            function handleProgress(response) {
                if (finalResponseReceived) return;
                
                if (response.success) {
                    // Update progress
                    updateProgressBar(progressBar, response.data.progress || 0, response.data.message);
                    
                    if (response.data.status === 'complete') {
                        finalResponseReceived = true;
                        displayFinalResults(response.data);
                    } else {
                        // Continue processing
                        sendTestRequest();
                    }
                } else {
                    // Handle error
                    statusContainer.removeClass('pending').addClass('error');
                    statusContainer.html(`<p>Error: ${response.data.message}</p>`);
                    button.prop('disabled', false).text('Test');
                }
            }
            
            // Function to display final results
            function displayFinalResults(data) {
                // Update status
                statusContainer.removeClass('pending').addClass('success');
                statusContainer.html('<p>Testing complete!</p>');
                
                // Format results
                let html = '<div class="sumai-results">';
                html += '<h3>Feed Test Results</h3>';
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Feed URL</th><th>Status</th><th>Details</th></tr></thead>';
                html += '<tbody>';
                
                $.each(data.results, function(i, result) {
                    const statusClass = result.status === 'success' ? 'sumai-success' : 'sumai-error';
                    html += '<tr>';
                    html += `<td>${result.url}</td>`;
                    html += `<td><span class="${statusClass}">${result.status}</span></td>`;
                    html += `<td>${result.message}</td>`;
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                
                // Append results
                resultsContainer.append(html);
                
                // Re-enable button
                button.prop('disabled', false).text('Test');
            }
            
            // Function to send the test request
            function sendTestRequest() {
                $.ajax({
                    url: sumaiAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sumai_test_feeds',
                        nonce: sumaiAdmin.nonce
                    },
                    success: handleProgress,
                    error: function() {
                        statusContainer.removeClass('pending').addClass('error');
                        statusContainer.html('<p>An error occurred while testing feeds.</p>');
                        button.prop('disabled', false).text('Test');
                    }
                });
            }
            
            // Start the process
            sendTestRequest();
        });
    }
    
    // Manual generation with real-time progress updates
    function initManualGeneration() {
        $('#sumai-generate-btn').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const draftMode = $('#sumai-draft-mode').is(':checked') ? '1' : '0';
            const respectProcessed = $('#sumai-respect-processed').is(':checked') ? '1' : '0';
            
            // Confirm action
            if (!confirm(sumaiAdmin.messages?.confirm_generate || 'This will generate a new summary post from your RSS feeds. Continue?')) {
                return;
            }
            
            // Create status container if doesn't exist
            if ($('#generation-status').length === 0) {
                $('<div id="generation-status" class="sumai-status pending"></div>').insertAfter(button.parent());
            }
            
            const statusContainer = $('#generation-status');
            statusContainer.html('<p>Starting generation process...</p>');
            
            // Create progress bar
            const progressBar = createProgressBar(statusContainer, 0);
            
            // Disable button
            button.prop('disabled', true).text('Generating...');
            
            // Track if we've received the final response
            let finalResponseReceived = false;
            
            // Function to handle progress updates
            function handleProgress(response) {
                if (finalResponseReceived) return;
                
                if (response.success) {
                    // Update progress
                    updateProgressBar(progressBar, response.data.progress || 0, response.data.message);
                    
                    if (response.data.status === 'complete') {
                        finalResponseReceived = true;
                        
                        // Update status
                        statusContainer.removeClass('pending').addClass('success');
                        statusContainer.html(`<p>${response.data.message}</p>`);
                        
                        // Re-enable button
                        button.prop('disabled', false).text('Generate Now');
                    } else {
                        // Continue processing
                        sendGenerateRequest();
                    }
                } else {
                    // Handle error
                    statusContainer.removeClass('pending').addClass('error');
                    statusContainer.html(`<p>Error: ${response.data.message}</p>`);
                    button.prop('disabled', false).text('Generate Now');
                }
            }
            
            // Function to send the generate request
            function sendGenerateRequest() {
                $.ajax({
                    url: sumaiAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sumai_generate_now',
                        nonce: sumaiAdmin.nonce,
                        draft_mode: draftMode,
                        respect_processed: respectProcessed
                    },
                    success: handleProgress,
                    error: function() {
                        statusContainer.removeClass('pending').addClass('error');
                        statusContainer.html('<p>An error occurred during content generation.</p>');
                        button.prop('disabled', false).text('Generate Now');
                    }
                });
            }
            
            // Start the process
            sendGenerateRequest();
        });
    }
    
    // Initialize API key field
    function initApiKeyField() {
        $('#sumai-toggle-api-key').on('click', function() {
            const apiKeyField = $('#sumai-api-key');
            
            if (apiKeyField.attr('type') === 'password') {
                apiKeyField.attr('type', 'text');
                $(this).text('Hide');
            } else {
                apiKeyField.attr('type', 'password');
                $(this).text('Show');
            }
        });
    }
    
    // Load processed articles
    function loadProcessedArticles() {
        const container = $('#processed-articles');
        
        // Show loading indicator
        container.html('<div class="sumai-status pending"><p>Loading articles...</p></div>');
        
        $.ajax({
            url: sumaiAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'sumai_get_processed',
                nonce: sumaiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (!response.data || response.data.length === 0) {
                        container.html('<div class="sumai-status info"><p>No processed articles found.</p></div>');
                        return;
                    }
                    
                    // Create table for articles
                    let html = '<table class="widefat striped">';
                    html += '<thead><tr><th>Date Processed</th><th>Post</th><th>GUID</th></tr></thead>';
                    html += '<tbody>';
                    
                    $.each(response.data, function(i, article) {
                        html += '<tr>';
                        html += `<td>${article.date}</td>`;
                        html += '<td>';
                        
                        if (article.post_id) {
                            html += `<a href="${article.post_url}" target="_blank">${article.post_title}</a> `;
                            html += `(<a href="${article.edit_url}">Edit</a>)`;
                        } else {
                            html += '<em>Post not found</em>';
                        }
                        
                        html += '</td>';
                        html += `<td><code>${article.guid}</code></td>`;
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                    
                    container.html(html);
                } else {
                    container.html(`<div class="sumai-status error"><p>${response.data}</p></div>`);
                }
            },
            error: function() {
                container.html('<div class="sumai-status error"><p>An error occurred while loading processed articles.</p></div>');
            }
        });
    }
    
    // Clear all processed articles
    function initClearProcessed() {
        $('#sumai-clear-all').on('click', function() {
            const button = $(this);
            
            // Confirm action
            if (!confirm(sumaiAdmin.messages?.confirm_clear_all || 'Are you sure you want to clear all processed articles? This will allow them to be processed again.')) {
                return;
            }
            
            // Disable button
            button.prop('disabled', true).text('Clearing...');
            
            $.ajax({
                url: sumaiAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sumai_clear_processed',
                    nonce: sumaiAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                            .insertBefore(button.parent())
                            .delay(3000)
                            .fadeOut(function() {
                                $(this).remove();
                            });
                        
                        // Refresh processed articles list
                        loadProcessedArticles();
                    } else {
                        // Show error message
                        $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                            .insertBefore(button.parent());
                    }
                    
                    // Re-enable button
                    button.prop('disabled', false).text('Clear All Processed Articles');
                },
                error: function() {
                    // Show error message
                    $('<div class="notice notice-error is-dismissible"><p>An error occurred while clearing processed articles.</p></div>')
                        .insertBefore(button.parent());
                    
                    // Re-enable button
                    button.prop('disabled', false).text('Clear All Processed Articles');
                }
            });
        });
    }
    
    // Initialize search functionality
    function initSearch() {
        $('#sumai-search-processed').on('submit', function(e) {
            e.preventDefault();
            
            const searchTerm = $('#sumai-search-term').val();
            const container = $('#processed-articles');
            
            // Show loading indicator
            container.html('<div class="sumai-status pending"><p>Searching articles...</p></div>');
            
            // TODO: Implement search functionality
            
            return false;
        });
        
        $('#sumai-clear-search').on('click', function() {
            $('#sumai-search-term').val('');
            loadProcessedArticles();
        });
    }
    
    // Run when DOM is ready
    $(document).ready(function() {
        // Initialize everything
        initTabs();
        initTestFeeds();
        initManualGeneration();
        initApiKeyField();
        initClearProcessed();
        initSearch();
        
        // Load processed articles if on that tab
        if (window.location.hash === '#tab-processed') {
            loadProcessedArticles();
        }
    });
})(jQuery);