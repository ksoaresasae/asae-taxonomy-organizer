/**
 * ASAE Taxonomy Organizer - Admin JavaScript
 * 
 * This file handles all the client-side functionality for the plugin's admin pages.
 * It manages form interactions, AJAX requests, result display, and the approval
 * workflow. The code is organized into a single ASAE_TO object to avoid polluting
 * the global namespace.
 * 
 * Key Features:
 * - Dynamic taxonomy loading based on post type selection
 * - Preview mode with individual approve/reject actions
 * - Batch action buttons (approve all, approve selected, reject all)
 * - Category selection when rejecting (to choose a different category)
 * - Rejection notes for AI training feedback
 * - Settings page handling (API key, model selection, connection test)
 * - Batch status monitoring with auto-refresh
 * 
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

(function($) {
    'use strict';

    /**
     * Main plugin object containing all functionality
     * Using an object pattern keeps everything organized and avoids global scope pollution
     */
    var ASAE_TO = {
        // Stores results that are pending user review
        pendingResults: [],
        
        // The current taxonomy being used (needed for saving)
        currentTaxonomy: '',
        
        // All available terms for the current taxonomy (for rejection category selection)
        allTerms: [],

        /**
         * Initialize the plugin functionality
         * Called when the DOM is ready
         */
        init: function() {
            this.bindEvents();
            this.startBatchPolling();
            this.initSettingsPage();
        },

        /**
         * Bind all event handlers
         * Organized by feature area for easier maintenance
         */
        bindEvents: function() {
            // === Form Controls ===
            $('#post_type').on('change', this.loadTaxonomies);
            $('#taxonomy').on('change', this.checkFormValidity);
            $('#preview_mode').on('change', this.handlePreviewModeChange);
            $('#items_count').on('change', this.handleItemsCountChange);
            $('#confidence_threshold').on('input', this.updateConfidenceDisplay);
            $('#asae-to-form').on('submit', this.processContent);
            
            // === Batch Management ===
            $(document).on('click', '.cancel-batch', this.cancelBatch);
            $('#cancel-all-batches').on('click', this.cancelAllBatches);
            
            // === Approval Workflow ===
            $('#approve-all-btn').on('click', this.approveAll);
            $('#approve-selected-btn').on('click', this.approveSelected);
            $('#reject-all-btn').on('click', this.rejectAll);
            $(document).on('click', '.approve-item', this.approveItem);
            $(document).on('click', '.reject-item', this.showRejectModal);
            $(document).on('change', '.result-checkbox', this.updateSelectedCount);
            
            // === Rejection Modal ===
            $(document).on('click', '.reject-confirm', this.confirmReject);
            $(document).on('click', '.reject-cancel', this.cancelReject);
            $(document).on('click', '.modal-overlay', this.cancelReject);
        },

        /**
         * Load taxonomies for the selected post type
         * Called when the post type dropdown changes
         */
        loadTaxonomies: function() {
            var postType = $(this).val();
            var $taxonomySelect = $('#taxonomy');

            // Reset if no post type selected
            if (!postType) {
                $taxonomySelect.prop('disabled', true)
                    .html('<option value="">Select a post type first...</option>');
                ASAE_TO.checkFormValidity();
                return;
            }

            // Show loading state
            $taxonomySelect.prop('disabled', true)
                .html('<option value="">Loading...</option>');

            // Fetch taxonomies via AJAX
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_taxonomies',
                    nonce: asaeToAdmin.nonce,
                    post_type: postType
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        var options = '<option value="">Select a taxonomy...</option>';
                        $.each(response.data, function(index, taxonomy) {
                            options += '<option value="' + ASAE_TO.escapeHtml(taxonomy.name) + '">' + 
                                       ASAE_TO.escapeHtml(taxonomy.label) + '</option>';
                        });
                        $taxonomySelect.html(options).prop('disabled', false);
                    } else {
                        $taxonomySelect.html('<option value="">No taxonomies available</option>');
                    }
                    ASAE_TO.checkFormValidity();
                },
                error: function() {
                    $taxonomySelect.html('<option value="">Error loading taxonomies</option>');
                    ASAE_TO.checkFormValidity();
                }
            });
        },

        /**
         * Handle preview mode toggle changes
         * Shows warning when "All" is selected (preview not available for all)
         */
        handlePreviewModeChange: function() {
            var isPreview = $(this).is(':checked');
            var $itemsCount = $('#items_count');
            var itemsValue = $itemsCount.val();
            
            // If enabling preview but "all" is selected, switch to 100
            if (isPreview && itemsValue === 'all') {
                $itemsCount.val('100');
                $('#batch-notice').hide();
                $('#preview-limit-note').show();
            } else {
                $('#preview-limit-note').hide();
            }
            
            ASAE_TO.handleItemsCountChange();
        },

        /**
         * Handle items count dropdown changes
         * Shows/hides batch notice and enforces preview limits
         */
        handleItemsCountChange: function() {
            var itemsCount = $('#items_count').val();
            var $previewMode = $('#preview_mode');
            var $batchNotice = $('#batch-notice');
            var $previewNote = $('#preview-limit-note');

            if (itemsCount === 'all') {
                // Disable preview mode for "all"
                $previewMode.prop('checked', false).prop('disabled', true);
                $batchNotice.show();
                $previewNote.hide();
            } else {
                $previewMode.prop('disabled', false);
                $batchNotice.hide();
                
                // Show note if selecting more than 100 with preview
                if (parseInt(itemsCount) > 100 && $previewMode.is(':checked')) {
                    $previewNote.show();
                } else {
                    $previewNote.hide();
                }
            }
        },

        /**
         * Update the confidence threshold display value
         */
        updateConfidenceDisplay: function() {
            var value = $(this).val();
            $('#confidence_value').text(value + '%');
        },

        /**
         * Check if the form is valid and enable/disable submit button
         */
        checkFormValidity: function() {
            var postType = $('#post_type').val();
            var taxonomy = $('#taxonomy').val();
            var $processBtn = $('#process-btn');

            $processBtn.prop('disabled', !(postType && taxonomy));
        },

        /**
         * Submit the form and process content
         * Handles the main analysis workflow
         */
        processContent: function(e) {
            e.preventDefault();

            var $spinner = $('#processing-spinner');
            var $processBtn = $('#process-btn');
            var $resultsCard = $('#results-card');
            var $resultsContainer = $('#results-container');
            var $resultsSummary = $('#results-summary');
            var $resultsActions = $('#results-actions');

            // Show loading state
            $processBtn.prop('disabled', true);
            $spinner.addClass('is-active');
            $resultsCard.hide();
            $resultsContainer.empty();
            $resultsSummary.empty();
            $resultsActions.hide();
            ASAE_TO.pendingResults = [];
            ASAE_TO.allTerms = [];

            // Gather form data
            var formData = {
                action: 'asae_to_process_content',
                nonce: asaeToAdmin.nonce,
                post_type: $('#post_type').val(),
                taxonomy: $('#taxonomy').val(),
                preview_mode: $('#preview_mode').is(':checked') ? 'true' : 'false',
                items_count: $('#items_count').val(),
                ignore_categorized: $('#ignore_categorized').is(':checked') ? 'true' : 'false',
                confidence_threshold: $('#confidence_threshold').val(),
                date_from: $('#date_from').val(),
                date_to: $('#date_to').val(),
                exclude_taxonomy: $('#exclude_taxonomy').val(),
                use_ai: $('#use_ai_toggle').is(':checked') ? 'true' : 'false'
            };

            ASAE_TO.currentTaxonomy = formData.taxonomy;

            // Submit the request
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $processBtn.prop('disabled', false);
                    $resultsCard.show();

                    if (response.success) {
                        if (response.is_batch) {
                            // Batch mode - show message and refresh status
                            $resultsContainer.html(
                                '<div class="processing-message">' +
                                '<strong>Batch Processing Started</strong><br>' +
                                response.message + '<br>' +
                                'Track progress in the Active Batches panel.' +
                                '</div>'
                            );
                            ASAE_TO.refreshBatchStatus();
                        } else {
                            // Store terms for category selection
                            if (response.all_terms) {
                                ASAE_TO.allTerms = response.all_terms;
                            }
                            ASAE_TO.displayResultsWithApproval(response);
                        }
                    } else {
                        $resultsContainer.html('<div class="error-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>');
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $processBtn.prop('disabled', false);
                    $resultsCard.show();
                    $resultsContainer.html('<div class="error-message">An error occurred while processing content.</div>');
                }
            });
        },

        /**
         * Display results with approval workflow UI
         * Builds the interactive results list with approve/reject buttons
         */
        displayResultsWithApproval: function(response) {
            var $resultsContainer = $('#results-container');
            var $resultsSummary = $('#results-summary');
            var $resultsActions = $('#results-actions');
            var html = '';

            // Show summary message
            if (response.message) {
                $resultsSummary.html('<div class="success-message">' + 
                    ASAE_TO.escapeHtml(response.message) + '</div>');
            }

            // Filter to items needing review
            ASAE_TO.pendingResults = response.results.filter(function(r) {
                return r.needs_review && r.term_id;
            });

            // Show batch action buttons if in preview mode with pending items
            if (response.preview_mode && ASAE_TO.pendingResults.length > 0) {
                $resultsActions.show();
            }

            // Build results list HTML
            if (response.results && response.results.length > 0) {
                html += '<div class="results-list">';
                $.each(response.results, function(index, result) {
                    var confidenceClass = 'confidence-' + result.confidence_level;
                    var statusClass = result.saved ? 'saved' : (result.needs_review ? 'pending' : 'skipped');
                    var statusText = result.saved ? 'Saved' : (result.needs_review ? 'Pending Review' : 'Skipped');
                    
                    html += '<div class="result-item-enhanced" ' +
                            'data-post-id="' + result.post_id + '" ' +
                            'data-term-id="' + result.term_id + '" ' +
                            'data-term-name="' + ASAE_TO.escapeHtml(result.suggested_category) + '">';
                    
                    // Checkbox for batch selection
                    if (result.needs_review && result.term_id) {
                        html += '<input type="checkbox" class="result-checkbox" checked>';
                    }
                    
                    // Content section
                    html += '<div class="result-content">';
                    html += '<div class="result-title-row">';
                    html += '<span class="result-title">' + ASAE_TO.escapeHtml(result.title) + '</span>';
                    html += '<span class="result-date">' + ASAE_TO.formatDate(result.post_date) + '</span>';
                    html += '</div>';
                    html += '<div class="result-meta-row">';
                    html += '<span class="result-category">' + ASAE_TO.escapeHtml(result.suggested_category) + '</span>';
                    html += '<span class="confidence-badge ' + confidenceClass + '">' + result.confidence + '%</span>';
                    html += '<span class="result-status ' + statusClass + '">' + statusText + '</span>';
                    html += '</div>';
                    html += '</div>';
                    
                    // Action buttons
                    if (result.needs_review && result.term_id) {
                        html += '<div class="result-actions">';
                        html += '<button class="button button-small button-primary approve-item" ' +
                                'data-post-id="' + result.post_id + '" ' +
                                'data-term-id="' + result.term_id + '">Approve</button>';
                        html += '<button class="button button-small reject-item" ' +
                                'data-post-id="' + result.post_id + '" ' +
                                'data-term-id="' + result.term_id + '">Reject</button>';
                        html += '</div>';
                    }
                    
                    html += '</div>';
                });
                html += '</div>';
            } else if (!response.message) {
                html += '<p>No results to display.</p>';
            }

            $resultsContainer.html(html);
            ASAE_TO.updateSelectedCount();
        },

        /**
         * Approve a single item
         * Saves the suggested categorization
         */
        approveItem: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $item = $btn.closest('.result-item-enhanced');
            var postId = $btn.data('post-id');
            var termId = $btn.data('term-id');

            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_save_items',
                    nonce: asaeToAdmin.nonce,
                    taxonomy: ASAE_TO.currentTaxonomy,
                    items: JSON.stringify([{post_id: postId, term_id: termId}])
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI to show saved state
                        $item.find('.result-status').removeClass('pending').addClass('saved').text('Saved');
                        $item.find('.result-actions').remove();
                        $item.find('.result-checkbox').remove();
                        
                        // Remove from pending list
                        ASAE_TO.pendingResults = ASAE_TO.pendingResults.filter(function(r) {
                            return r.post_id !== postId;
                        });
                        ASAE_TO.updateSelectedCount();
                    } else {
                        $btn.prop('disabled', false).text('Approve');
                        alert('Failed to save: ' + response.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Approve');
                    alert('An error occurred while saving.');
                }
            });
        },

        /**
         * Show the rejection modal for selecting a different category
         * Allows users to choose a different category and provide feedback
         */
        showRejectModal: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $item = $btn.closest('.result-item-enhanced');
            var postId = $item.data('post-id');
            var suggestedTermId = $item.data('term-id');
            var suggestedTermName = $item.data('term-name');
            
            // Build category options
            var categoryOptions = '<option value="">-- Keep no category --</option>';
            $.each(ASAE_TO.allTerms, function(i, term) {
                var selected = '';
                categoryOptions += '<option value="' + term.term_id + '"' + selected + '>' + 
                                   ASAE_TO.escapeHtml(term.name) + '</option>';
            });
            
            // Create modal HTML
            var modalHtml = '<div class="modal-overlay">' +
                '<div class="reject-modal">' +
                '<h3>Reject Suggestion</h3>' +
                '<p>The AI suggested: <strong>' + ASAE_TO.escapeHtml(suggestedTermName) + '</strong></p>' +
                '<div class="modal-field">' +
                '<label for="new-category">Select correct category (optional):</label>' +
                '<select id="new-category" class="regular-text">' + categoryOptions + '</select>' +
                '</div>' +
                '<div class="modal-field">' +
                '<label for="reject-notes">Why was this wrong? (helps improve AI):</label>' +
                '<textarea id="reject-notes" rows="3" class="large-text"></textarea>' +
                '</div>' +
                '<div class="modal-actions">' +
                '<button class="button button-primary reject-confirm" ' +
                'data-post-id="' + postId + '" ' +
                'data-suggested-term-id="' + suggestedTermId + '">Confirm Rejection</button>' +
                '<button class="button reject-cancel">Cancel</button>' +
                '</div>' +
                '</div>' +
                '</div>';
            
            // Add modal to page
            $('body').append(modalHtml);
        },

        /**
         * Confirm rejection with optional new category and notes
         */
        confirmReject: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var suggestedTermId = $btn.data('suggested-term-id');
            var selectedTermId = $('#new-category').val();
            var notes = $('#reject-notes').val();
            
            $btn.prop('disabled', true).text('Saving...');
            
            // Log the rejection feedback
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_log_rejection',
                    nonce: asaeToAdmin.nonce,
                    post_id: postId,
                    suggested_term_id: suggestedTermId,
                    selected_term_id: selectedTermId || '',
                    notes: notes,
                    taxonomy: ASAE_TO.currentTaxonomy
                },
                success: function(response) {
                    // If user selected a new category, save it
                    if (selectedTermId) {
                        $.ajax({
                            url: asaeToAdmin.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'asae_to_save_items',
                                nonce: asaeToAdmin.nonce,
                                taxonomy: ASAE_TO.currentTaxonomy,
                                items: JSON.stringify([{post_id: postId, term_id: parseInt(selectedTermId)}])
                            },
                            success: function(saveResponse) {
                                ASAE_TO.finishReject(postId, saveResponse.success ? 'Corrected' : 'Rejected');
                            },
                            error: function() {
                                ASAE_TO.finishReject(postId, 'Rejected');
                            }
                        });
                    } else {
                        ASAE_TO.finishReject(postId, 'Rejected');
                    }
                },
                error: function() {
                    ASAE_TO.finishReject(postId, 'Rejected');
                }
            });
        },

        /**
         * Finish the rejection process and update UI
         */
        finishReject: function(postId, statusText) {
            // Close modal
            $('.modal-overlay').remove();
            
            // Update the item in the list
            var $item = $('.result-item-enhanced[data-post-id="' + postId + '"]');
            $item.find('.result-status').removeClass('pending').addClass('skipped').text(statusText);
            $item.find('.result-actions').remove();
            $item.find('.result-checkbox').remove();
            
            // Remove from pending
            ASAE_TO.pendingResults = ASAE_TO.pendingResults.filter(function(r) {
                return r.post_id !== postId;
            });
            ASAE_TO.updateSelectedCount();
        },

        /**
         * Cancel rejection and close modal
         */
        cancelReject: function(e) {
            if ($(e.target).hasClass('modal-overlay') || $(e.target).hasClass('reject-cancel')) {
                $('.modal-overlay').remove();
            }
        },

        /**
         * Approve all pending items
         */
        approveAll: function(e) {
            e.preventDefault();
            var itemsToSave = ASAE_TO.pendingResults.map(function(r) {
                return {post_id: r.post_id, term_id: r.term_id};
            });

            if (itemsToSave.length === 0) {
                alert('No items to approve.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_save_items',
                    nonce: asaeToAdmin.nonce,
                    taxonomy: ASAE_TO.currentTaxonomy,
                    items: JSON.stringify(itemsToSave)
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Approve All');
                    if (response.success) {
                        // Update all items to saved state
                        $('.result-item-enhanced').each(function() {
                            var $item = $(this);
                            if ($item.find('.result-checkbox').length) {
                                $item.find('.result-status').removeClass('pending').addClass('saved').text('Saved');
                                $item.find('.result-actions').remove();
                                $item.find('.result-checkbox').remove();
                            }
                        });
                        ASAE_TO.pendingResults = [];
                        $('#results-actions').hide();
                        $('#results-summary').html('<div class="success-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>');
                    } else {
                        alert('Failed to save: ' + response.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Approve All');
                    alert('An error occurred while saving.');
                }
            });
        },

        /**
         * Approve only selected (checked) items
         */
        approveSelected: function(e) {
            e.preventDefault();
            var itemsToSave = [];
            
            $('.result-item-enhanced').each(function() {
                var $item = $(this);
                var $checkbox = $item.find('.result-checkbox');
                if ($checkbox.length && $checkbox.is(':checked')) {
                    itemsToSave.push({
                        post_id: $item.data('post-id'),
                        term_id: $item.data('term-id')
                    });
                }
            });

            if (itemsToSave.length === 0) {
                alert('No items selected.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_save_items',
                    nonce: asaeToAdmin.nonce,
                    taxonomy: ASAE_TO.currentTaxonomy,
                    items: JSON.stringify(itemsToSave)
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Approve Selected');
                    if (response.success) {
                        var savedIds = itemsToSave.map(function(i) { return i.post_id; });
                        $('.result-item-enhanced').each(function() {
                            var $item = $(this);
                            if (savedIds.indexOf($item.data('post-id')) !== -1) {
                                $item.find('.result-status').removeClass('pending').addClass('saved').text('Saved');
                                $item.find('.result-actions').remove();
                                $item.find('.result-checkbox').remove();
                            }
                        });
                        ASAE_TO.pendingResults = ASAE_TO.pendingResults.filter(function(r) {
                            return savedIds.indexOf(r.post_id) === -1;
                        });
                        ASAE_TO.updateSelectedCount();
                        $('#results-summary').html('<div class="success-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>');
                    } else {
                        alert('Failed to save: ' + response.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Approve Selected');
                    alert('An error occurred while saving.');
                }
            });
        },

        /**
         * Reject all pending items (clear without saving)
         */
        rejectAll: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to reject all pending items? This will clear the list without saving.')) {
                return;
            }

            $('.result-item-enhanced').each(function() {
                var $item = $(this);
                if ($item.find('.result-checkbox').length) {
                    $item.find('.result-status').removeClass('pending').addClass('skipped').text('Rejected');
                    $item.find('.result-actions').remove();
                    $item.find('.result-checkbox').remove();
                }
            });
            ASAE_TO.pendingResults = [];
            $('#results-actions').hide();
        },

        /**
         * Update the "Approve Selected" button count
         */
        updateSelectedCount: function() {
            var checkedCount = $('.result-checkbox:checked').length;
            var totalPending = $('.result-checkbox').length;
            
            if (totalPending === 0) {
                $('#results-actions').hide();
            } else {
                $('#approve-selected-btn').text('Approve Selected (' + checkedCount + ')');
            }
        },

        // =========================================================================
        // Settings Page Functions
        // =========================================================================

        /**
         * Initialize settings page functionality
         */
        initSettingsPage: function() {
            // API key show/hide toggle
            $('#toggle-api-key').on('click', function() {
                var $input = $('#openai_api_key');
                var $btn = $(this);
                
                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $btn.html('<span class="dashicons dashicons-hidden"></span> Hide');
                } else {
                    $input.attr('type', 'password');
                    $btn.html('<span class="dashicons dashicons-visibility"></span> Show');
                }
            });
            
            // Test connection button
            $('#test-connection-btn').on('click', this.testConnection);
            
            // Save settings form
            $('#asae-to-settings-form').on('submit', this.saveSettings);
            
            // AI toggle change
            $('#use_ai').on('change', this.updateAIStatus);
        },

        /**
         * Test the OpenAI connection
         */
        testConnection: function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $spinner = $('#settings-spinner');
            var $result = $('#connection-result');
            
            var apiKey = $('#openai_api_key').val();
            var model = $('#openai_model').val();
            
            if (!apiKey) {
                $result.html('<div class="error-message">Please enter an API key first.</div>').show();
                return;
            }
            
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.hide();
            
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_test_connection',
                    nonce: asaeToAdmin.nonce,
                    api_key: apiKey,
                    model: model
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    
                    if (response.success) {
                        $result.html('<div class="success-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>').show();
                    } else {
                        $result.html('<div class="error-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>').show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('<div class="error-message">Connection test failed.</div>').show();
                }
            });
        },

        /**
         * Save settings form
         */
        saveSettings: function(e) {
            e.preventDefault();
            
            var $btn = $('#save-settings-btn');
            var $spinner = $('#settings-spinner');
            var $result = $('#connection-result');
            
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_save_settings',
                    nonce: asaeToAdmin.nonce,
                    api_key: $('#openai_api_key').val(),
                    model: $('#openai_model').val(),
                    use_ai: $('#use_ai').is(':checked') ? 'yes' : 'no'
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    
                    if (response.success) {
                        $result.html('<div class="success-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>').show();
                        ASAE_TO.updateAIStatus();
                    } else {
                        $result.html('<div class="error-message">' + 
                            ASAE_TO.escapeHtml(response.message) + '</div>').show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('<div class="error-message">Failed to save settings.</div>').show();
                }
            });
        },

        /**
         * Update the AI status indicator
         */
        updateAIStatus: function() {
            var $status = $('#ai-status');
            var useAI = $('#use_ai').is(':checked');
            var hasKey = $('#openai_api_key').val().length > 0;
            
            if (useAI && hasKey) {
                $status.html('<span class="status-active">AI Analysis Active</span>');
            } else if (useAI && !hasKey) {
                $status.html('<span class="status-warning">AI Enabled but No API Key</span>');
            } else {
                $status.html('<span class="status-inactive">Using Keyword Matching</span>');
            }
        },

        // =========================================================================
        // Batch Management Functions
        // =========================================================================

        /**
         * Cancel a single batch
         */
        cancelBatch: function(e) {
            e.preventDefault();

            var batchId = $(this).data('batch-id');
            var $batchItem = $(this).closest('.batch-item');

            if (!confirm('Are you sure you want to cancel this batch?')) {
                return;
            }

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_cancel_batch',
                    nonce: asaeToAdmin.nonce,
                    batch_id: batchId
                },
                success: function(response) {
                    if (response.success) {
                        $batchItem.fadeOut(function() {
                            $(this).remove();
                            ASAE_TO.checkEmptyBatches();
                        });
                    } else {
                        alert(response.message || 'Failed to cancel batch.');
                    }
                },
                error: function() {
                    alert('An error occurred while cancelling the batch.');
                }
            });
        },

        /**
         * Cancel all active batches
         */
        cancelAllBatches: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to cancel ALL active batches?')) {
                return;
            }

            var batchIds = [];
            $('.batch-item').each(function() {
                batchIds.push($(this).data('batch-id'));
            });

            if (batchIds.length === 0) {
                return;
            }

            $.each(batchIds, function(index, batchId) {
                $.ajax({
                    url: asaeToAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'asae_to_cancel_batch',
                        nonce: asaeToAdmin.nonce,
                        batch_id: batchId
                    }
                });
            });

            $('.batch-item').fadeOut(function() {
                $(this).remove();
                ASAE_TO.checkEmptyBatches();
            });
        },

        /**
         * Refresh batch status from server
         */
        refreshBatchStatus: function() {
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_batch_status',
                    nonce: asaeToAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        ASAE_TO.updateBatchUI(response.data);
                    }
                }
            });
        },

        /**
         * Update the batch status UI
         */
        updateBatchUI: function(batches) {
            var $container = $('#active-batches');
            
            if (!batches || batches.length === 0) {
                $container.html('<p class="no-batches">No active batch processes.</p>');
                $('#cancel-all-batches').prop('disabled', true);
                return;
            }

            var html = '';
            $.each(batches, function(index, batch) {
                html += '<div class="batch-item" data-batch-id="' + ASAE_TO.escapeHtml(batch.batch_id) + '">';
                html += '<div class="batch-info">';
                html += '<strong>' + ASAE_TO.escapeHtml(batch.post_type) + '</strong> &rarr; ' + 
                        ASAE_TO.escapeHtml(batch.taxonomy);
                html += '<br>';
                html += '<span class="batch-progress">' + batch.processed_items + ' / ' + batch.total_items + '</span>';
                html += '<span class="batch-status status-' + batch.status + '">' + 
                        ASAE_TO.capitalize(batch.status) + '</span>';
                html += '</div>';
                html += '<button class="button button-small cancel-batch" data-batch-id="' + 
                        ASAE_TO.escapeHtml(batch.batch_id) + '">Cancel</button>';
                html += '</div>';
            });

            $container.html(html);
            $('#cancel-all-batches').prop('disabled', false);
        },

        /**
         * Check if there are any batches and update UI accordingly
         */
        checkEmptyBatches: function() {
            if ($('.batch-item').length === 0) {
                $('#active-batches').html('<p class="no-batches">No active batch processes.</p>');
                $('#cancel-all-batches').prop('disabled', true);
            }
        },

        /**
         * Start polling for batch status updates
         */
        startBatchPolling: function() {
            setInterval(function() {
                if ($('.batch-item').length > 0) {
                    ASAE_TO.refreshBatchStatus();
                }
            }, 10000); // Poll every 10 seconds
        },

        // =========================================================================
        // Utility Functions
        // =========================================================================

        /**
         * Format a date string for display
         */
        formatDate: function(dateStr) {
            if (!dateStr) return '';
            var date = new Date(dateStr);
            return date.toLocaleDateString();
        },

        /**
         * Escape HTML to prevent XSS
         * Always use this when inserting user-generated content into the DOM
         */
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Capitalize the first letter of a string
         */
        capitalize: function(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        ASAE_TO.init();
    });

})(jQuery);
