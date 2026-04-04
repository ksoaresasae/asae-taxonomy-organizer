/**
 * ASAE Taxonomy Organizer - Admin JavaScript
 *
 * v0.1.0 changes:
 * - Chunked preview: processes items in small AJAX batches so partial results
 *   survive timeouts.
 * - Cost estimate confirmation before AI processing.
 * - Progress bar during chunked preview.
 * - Rate-limit pause with automatic retry.
 * - Reset-usage button on Settings page.
 *
 * @package ASAE_Taxonomy_Organizer
 * @author Keith M. Soares
 * @since 0.0.1
 */

(function($) {
    'use strict';

    var ASAE_TO = {
        pendingResults: [],
        currentTaxonomy: '',
        allTerms: [],
        previewChunkSize: 10,

        // =====================================================================
        // Initialisation
        // =====================================================================

        currentBatchId: null,
        batchPollTimer: null,
        heartbeatTimer: null,

        init: function() {
            this.bindEvents();
            this.initResumeBanner();
            this.initSettingsPage();
        },

        bindEvents: function() {
            // Form controls
            $('#post_type').on('change', this.loadTaxonomies);
            $('#taxonomy').on('change', this.checkFormValidity);
            $('#preview_mode').on('change', this.handlePreviewModeChange);
            $('#items_count').on('change', this.handleItemsCountChange);
            $('#confidence_threshold').on('input', this.updateConfidenceDisplay);
            $('#asae-to-form').on('submit', this.processContent);

            // Inline batch progress
            $('#asae-to-cancel-batch-btn').on('click', this.cancelCurrentBatch);
            $('#asae-to-resume-btn').on('click', this.resumeBatch);
            $('#asae-to-cancel-running-btn').on('click', this.cancelRunningBatch);
            $('#asae-to-cancel-all-btn').on('click', this.cancelAllBatches);

            // Approval workflow
            $('#approve-all-btn').on('click', this.approveAll);
            $('#approve-selected-btn').on('click', this.approveSelected);
            $('#reject-all-btn').on('click', this.rejectAll);
            $(document).on('click', '.approve-item', this.approveItem);
            $(document).on('click', '.reject-item', this.showRejectModal);
            $(document).on('change', '.result-checkbox', this.updateSelectedCount);

            // Rejection modal
            $(document).on('click', '.reject-confirm', this.confirmReject);
            $(document).on('click', '.reject-cancel', this.cancelReject);
            $(document).on('click', '.modal-overlay', this.cancelReject);
        },

        // =====================================================================
        // Form helpers (unchanged from v0.0.x)
        // =====================================================================

        loadTaxonomies: function() {
            var postType = $(this).val();
            var $taxonomySelect = $('#taxonomy');

            if (!postType) {
                $taxonomySelect.prop('disabled', true)
                    .html('<option value="">Select a post type first...</option>');
                ASAE_TO.checkFormValidity();
                return;
            }

            $taxonomySelect.prop('disabled', true)
                .html('<option value="">Loading...</option>');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'asae_to_get_taxonomies', nonce: asaeToAdmin.nonce, post_type: postType },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        var options = '<option value="">Select a taxonomy...</option>';
                        $.each(response.data, function(i, tax) {
                            options += '<option value="' + ASAE_TO.escapeHtml(tax.name) + '">' +
                                       ASAE_TO.escapeHtml(tax.label) + '</option>';
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

        handlePreviewModeChange: function() {
            var isPreview = $(this).is(':checked');
            if (isPreview && $('#items_count').val() === 'all') {
                $('#items_count').val('100');
                $('#batch-notice').hide();
                $('#preview-limit-note').show();
            } else {
                $('#preview-limit-note').hide();
            }
            ASAE_TO.handleItemsCountChange();
        },

        handleItemsCountChange: function() {
            var itemsCount = $('#items_count').val();
            var $previewMode = $('#preview_mode');

            if (itemsCount === 'all') {
                $previewMode.prop('checked', false).prop('disabled', true);
                $('#batch-notice').show();
                $('#preview-limit-note').hide();
            } else {
                $previewMode.prop('disabled', false);
                $('#batch-notice').hide();
                $('#preview-limit-note').toggle(parseInt(itemsCount) > 100 && $previewMode.is(':checked'));
            }
        },

        updateConfidenceDisplay: function() {
            $('#confidence_value').text($(this).val() + '%');
        },

        checkFormValidity: function() {
            $('#process-btn').prop('disabled', !($('#post_type').val() && $('#taxonomy').val()));
        },

        // =====================================================================
        // Main processing entry point
        // =====================================================================

        processContent: function(e) {
            e.preventDefault();

            var $spinner = $('#processing-spinner');
            var $processBtn = $('#process-btn');
            var $resultsCard = $('#results-card');
            var $resultsContainer = $('#results-container');
            var $resultsSummary = $('#results-summary');
            var $resultsActions = $('#results-actions');

            $processBtn.prop('disabled', true);
            $spinner.addClass('is-active');
            $resultsCard.hide();
            $resultsContainer.empty();
            $resultsSummary.empty();
            $resultsActions.hide();
            ASAE_TO.pendingResults = [];
            ASAE_TO.allTerms = [];

            var formData = {
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

            // If AI is enabled, show cost estimate first
            if (formData.use_ai === 'true') {
                ASAE_TO.getCostEstimateAndProcess(formData);
            } else {
                ASAE_TO.startProcessing(formData);
            }
        },

        /**
         * Fetch cost estimate, show confirmation, then start processing.
         */
        getCostEstimateAndProcess: function(formData) {
            var $spinner = $('#processing-spinner');
            var $processBtn = $('#process-btn');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_cost_estimate',
                    nonce: asaeToAdmin.nonce,
                    post_type: formData.post_type,
                    taxonomy: formData.taxonomy,
                    items_count: formData.items_count,
                    ignore_categorized: formData.ignore_categorized,
                    date_from: formData.date_from,
                    date_to: formData.date_to,
                    exclude_taxonomy: formData.exclude_taxonomy
                },
                success: function(response) {
                    if (!response.success) {
                        $spinner.removeClass('is-active');
                        $processBtn.prop('disabled', false);
                        return;
                    }

                    var d = response.data;

                    if (d.count === 0) {
                        $spinner.removeClass('is-active');
                        $processBtn.prop('disabled', false);
                        $('#results-card').show();
                        $('#results-container').html('<div class="success-message">No content found matching your criteria.</div>');
                        return;
                    }

                    // Build confirmation message
                    var msg = 'Found ' + d.count + ' items to analyse.\n';
                    if (d.ai_enabled) {
                        msg += 'Estimated cost: $' + d.estimated_cost + ' (' + d.model + ').\n';
                        if (d.monthly_limit > 0) {
                            msg += 'Remaining budget: ' + d.remaining_calls + ' calls this month.\n';
                        }
                        if (d.budget_exceeded) {
                            msg += '\nNOTE: This would exceed your monthly API call limit.\n' +
                                   'Processing will pause when the limit is reached and retry automatically.';
                        }
                    }
                    msg += '\nContinue?';

                    if (confirm(msg)) {
                        ASAE_TO.startProcessing(formData);
                    } else {
                        $spinner.removeClass('is-active');
                        $processBtn.prop('disabled', false);
                    }
                },
                error: function() {
                    // If estimate fails, proceed anyway
                    ASAE_TO.startProcessing(formData);
                }
            });
        },

        /**
         * Dispatch to the correct processing mode.
         */
        startProcessing: function(formData) {
            if (formData.preview_mode === 'true') {
                ASAE_TO.processPreviewChunked(formData);
            } else {
                ASAE_TO.processContentDirect(formData);
            }
        },

        // =====================================================================
        // Chunked preview mode
        // =====================================================================

        processPreviewChunked: function(formData) {
            ASAE_TO.startHeartbeat();
            var $resultsCard = $('#results-card');
            var $resultsContainer = $('#results-container');
            var $resultsSummary = $('#results-summary');

            $resultsCard.show();
            $resultsContainer.html(
                '<div class="chunked-progress">' +
                '<div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Analysis progress">' +
                '<div class="progress-fill" style="width: 0%"></div>' +
                '</div>' +
                '<span class="progress-text" role="status" aria-live="polite">Starting analysis...</span>' +
                '</div>' +
                '<div class="results-list"></div>'
            );

            var offset = 0;
            var chunkSize = ASAE_TO.previewChunkSize;

            function processNextChunk() {
                $.ajax({
                    url: asaeToAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'asae_to_process_preview_chunk',
                        nonce: asaeToAdmin.nonce,
                        post_type: formData.post_type,
                        taxonomy: formData.taxonomy,
                        ignore_categorized: formData.ignore_categorized,
                        date_from: formData.date_from,
                        date_to: formData.date_to,
                        exclude_taxonomy: formData.exclude_taxonomy,
                        use_ai: formData.use_ai,
                        offset: offset,
                        chunk_size: chunkSize
                    },
                    success: function(response) {
                        if (!response.success) {
                            ASAE_TO.finishChunkedPreview('Error: ' + ASAE_TO.escapeHtml(response.data || 'Unknown error'));
                            return;
                        }

                        var data = response.data;

                        // Store terms from the first chunk
                        if (data.all_terms && data.all_terms.length > 0) {
                            ASAE_TO.allTerms = data.all_terms;
                        }

                        // Append results
                        ASAE_TO.appendChunkResults(data.results);

                        // Update progress
                        var processed = offset + data.results.length;
                        var total = data.total;
                        var pct = total > 0 ? Math.round((processed / total) * 100) : 100;
                        $('.progress-fill').css('width', pct + '%');
                        $('.progress-bar').attr('aria-valuenow', pct);
                        $('.progress-text').text(processed + ' of ' + total + ' items processed');

                        offset += chunkSize;

                        // API unavailable (rate limited, budget exceeded, or error) — stop preview
                        if (data.api_unavailable) {
                            var reason = data.api_status === 'rate_limited' ? 'rate limited' :
                                         data.api_status === 'budget_exceeded' ? 'monthly budget reached' : 'API error';
                            ASAE_TO.finishChunkedPreview(
                                'API unavailable (' + reason + '). ' + processed + ' of ' + total +
                                ' items processed so far. Results above are preserved. Please try again later.',
                                processed
                            );
                            return;
                        }

                        if (data.has_more) {
                            processNextChunk();
                        } else {
                            ASAE_TO.finishChunkedPreview(null, processed);
                        }
                    },
                    error: function() {
                        ASAE_TO.finishChunkedPreview('Connection error. Results so far are preserved.');
                    }
                });
            }

            processNextChunk();
        },

        appendChunkResults: function(results) {
            var $list = $('#results-container .results-list');

            $.each(results, function(i, result) {
                var confidenceClass = 'confidence-' + result.confidence_level;
                var statusClass = result.saved ? 'saved' : (result.needs_review ? 'pending' : 'skipped');
                var statusText = result.saved ? 'Saved' : (result.needs_review ? 'Pending Review' : 'Skipped');

                var tagsJson = result.suggested_tags && result.suggested_tags.length > 0
                    ? ASAE_TO.escapeHtml(JSON.stringify(result.suggested_tags)) : '';

                var html = '<div class="result-item-enhanced" ' +
                    'data-post-id="' + result.post_id + '" ' +
                    'data-term-id="' + result.term_id + '" ' +
                    'data-term-name="' + ASAE_TO.escapeHtml(result.suggested_category) + '" ' +
                    'data-tags="' + tagsJson + '">';

                if (result.needs_review && result.term_id) {
                    html += '<input type="checkbox" class="result-checkbox" checked aria-label="Select: ' + ASAE_TO.escapeHtml(result.title) + '">';
                    // Track in pending
                    ASAE_TO.pendingResults.push(result);
                }

                html += '<div class="result-content">';
                html += '<div class="result-title-row">';
                html += '<span class="result-title">' + ASAE_TO.escapeHtml(result.title) + '</span>';
                html += '<span class="result-date">' + ASAE_TO.formatDate(result.post_date) + '</span>';
                html += '</div>';
                html += '<div class="result-meta-row">';
                html += '<span class="result-category">' + ASAE_TO.escapeHtml(result.suggested_category) + '</span>';
                html += '<span class="confidence-badge ' + confidenceClass + '">' + result.confidence + '%</span>';
                if (result.suggested_tags && result.suggested_tags.length > 0) {
                    html += '<span class="result-tags">';
                    $.each(result.suggested_tags, function(j, tag) {
                        html += '<span class="result-tag">' + ASAE_TO.escapeHtml(tag) + '</span>';
                    });
                    html += '</span>';
                }
                html += '<span class="result-status ' + statusClass + '">' + statusText + '</span>';
                html += '</div></div>';

                if (result.needs_review && result.term_id) {
                    html += '<div class="result-actions">';
                    html += '<button class="button button-small button-primary approve-item" ' +
                            'data-post-id="' + result.post_id + '" data-term-id="' + result.term_id + '">Approve</button>';
                    html += '<button class="button button-small reject-item" ' +
                            'data-post-id="' + result.post_id + '" data-term-id="' + result.term_id + '">Reject</button>';
                    html += '</div>';
                }

                html += '</div>';
                $list.append(html);
            });
        },

        finishChunkedPreview: function(errorMsg, processed) {
            ASAE_TO.stopHeartbeat();
            $('.chunked-progress').remove();
            $('#processing-spinner').removeClass('is-active');
            $('#process-btn').prop('disabled', false);

            if (errorMsg) {
                $('#results-summary').html('<div class="error-message" role="alert">' + errorMsg + '</div>');
            } else {
                $('#results-summary').html('<div class="success-message" role="status">Analysis complete. ' +
                    (processed || 0) + ' items processed.</div>');
            }

            if (ASAE_TO.pendingResults.length > 0) {
                $('#results-actions').show();
            }
            ASAE_TO.updateSelectedCount();
        },

        // =====================================================================
        // Direct / batch processing (non-preview, non-chunked)
        // =====================================================================

        processContentDirect: function(formData) {
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: $.extend({
                    action: 'asae_to_process_content',
                    nonce: asaeToAdmin.nonce
                }, formData),
                success: function(response) {
                    if (response.success) {
                        if (response.is_batch) {
                            // Batch started — switch to inline progress polling
                            ASAE_TO.currentBatchId = response.batch_id;
                            ASAE_TO.showInlineProgress(response.total_items);
                            ASAE_TO.startBatchProgressPolling();
                        } else {
                            $('#processing-spinner').removeClass('is-active');
                            $('#process-btn').prop('disabled', false);
                            $('#results-card').show();
                            if (response.all_terms) { ASAE_TO.allTerms = response.all_terms; }
                            ASAE_TO.displayResultsWithApproval(response);
                        }
                    } else {
                        $('#processing-spinner').removeClass('is-active');
                        $('#process-btn').prop('disabled', false);
                        $('#results-card').show();
                        $('#results-container').html('<div class="error-message" role="alert">' +
                            ASAE_TO.escapeHtml(response.message) + '</div>');
                    }
                },
                error: function() {
                    $('#processing-spinner').removeClass('is-active');
                    $('#process-btn').prop('disabled', false);
                    $('#results-card').show();
                    $('#results-container').html('<div class="error-message" role="alert">An error occurred while processing content.</div>');
                }
            });
        },

        // =====================================================================
        // Inline batch progress (Content Ingestor pattern)
        // =====================================================================

        startHeartbeat: function() {
            ASAE_TO.stopHeartbeat();
            ASAE_TO.heartbeatTimer = setInterval(function() {
                $.post(asaeToAdmin.ajaxUrl, { action: 'asae_to_heartbeat', nonce: asaeToAdmin.nonce });
            }, 30000);
        },

        stopHeartbeat: function() {
            if (ASAE_TO.heartbeatTimer) {
                clearInterval(ASAE_TO.heartbeatTimer);
                ASAE_TO.heartbeatTimer = null;
            }
        },

        initResumeBanner: function() {
            if (asaeToAdmin.runningBatchId) {
                var $banner = $('#asae-to-resume-banner');
                var detail = ' ' + asaeToAdmin.runningBatchProcessed + ' of ' +
                    asaeToAdmin.runningBatchTotal + ' items processed so far.';

                if (asaeToAdmin.runningBatchStatus === 'paused') {
                    var reason = asaeToAdmin.runningBatchPauseReason === 'rate_limited' ? 'rate limited by OpenAI' :
                                 asaeToAdmin.runningBatchPauseReason === 'budget_exceeded' ? 'monthly budget reached' : 'API error';
                    detail += ' Paused (' + reason + ').';
                    if (asaeToAdmin.runningBatchNextRetry) {
                        detail += ' Will auto-retry at ' + asaeToAdmin.runningBatchNextRetry + '.';
                    }
                    detail += ' You can leave this page — processing will resume automatically via cron.';
                } else {
                    detail += ' Processing continues in the background.';
                }

                $('#asae-to-resume-detail').text(detail);
                $banner.show();
            }

            // Restart polling when tab returns from sleep/background
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden && ASAE_TO.currentBatchId && !ASAE_TO.batchPollTimer) {
                    ASAE_TO.pollBatchProgress();
                }
            });
        },

        resumeBatch: function() {
            $('#asae-to-resume-banner').hide();
            ASAE_TO.currentBatchId = asaeToAdmin.runningBatchId;
            ASAE_TO.showInlineProgress(asaeToAdmin.runningBatchTotal);
            $('#asae-to-processed-count').text(asaeToAdmin.runningBatchProcessed);
            var pct = asaeToAdmin.runningBatchTotal > 0
                ? Math.round((asaeToAdmin.runningBatchProcessed / asaeToAdmin.runningBatchTotal) * 100) : 0;
            ASAE_TO.setProgressBar(pct);
            $('#asae-to-phase-label').text('Processing…');
            ASAE_TO.startBatchProgressPolling();
        },

        cancelRunningBatch: function() {
            var batchId = asaeToAdmin.runningBatchId;
            $('#asae-to-cancel-running-btn').prop('disabled', true).text('Cancelling…');
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'asae_to_cancel_batch', nonce: asaeToAdmin.nonce, batch_id: batchId },
                success: function(response) {
                    $('#asae-to-resume-banner').hide();
                },
                complete: function() {
                    $('#asae-to-cancel-running-btn').prop('disabled', false).text('Cancel Job');
                }
            });
        },

        cancelAllBatches: function() {
            if (!confirm('Cancel ALL running, pending, and paused batches? Already-processed items keep their assignments.')) {
                return;
            }
            var $btn = $('#asae-to-cancel-all-btn');
            $btn.prop('disabled', true).text('Cancelling…');
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'asae_to_cancel_all_batches', nonce: asaeToAdmin.nonce },
                success: function(response) {
                    $('#asae-to-resume-banner').hide();
                    if (ASAE_TO.currentBatchId) {
                        ASAE_TO.finishBatchProgress('All batches cancelled.');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Cancel All Jobs');
                }
            });
        },

        showInlineProgress: function(totalItems) {
            ASAE_TO.startHeartbeat();
            $('#processing-spinner').removeClass('is-active');
            $('#process-btn').prop('disabled', true);
            $('#asae-to-progress-panel').show();
            $('#asae-to-total-count').text(totalItems);
            $('#asae-to-processed-count').text('0');
            $('#asae-to-phase-label').text('Processing…');
            $('#asae-to-progress-complete').hide();
            $('#asae-to-cancel-batch-btn').show().prop('disabled', false);
            ASAE_TO.setProgressBar(0);
        },

        setProgressBar: function(pct) {
            pct = Math.min(100, Math.max(0, pct));
            $('#asae-to-progress-bar').css('width', pct + '%');
            $('#asae-to-progress-bar-wrap').attr('aria-valuenow', pct);
        },

        startBatchProgressPolling: function() {
            if (ASAE_TO.batchPollTimer) {
                clearTimeout(ASAE_TO.batchPollTimer);
            }
            ASAE_TO.pollBatchProgress();
        },

        pollBatchProgress: function() {
            if (!ASAE_TO.currentBatchId) { return; }

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_batch_progress',
                    nonce: asaeToAdmin.nonce,
                    batch_id: ASAE_TO.currentBatchId
                },
                success: function(response) {
                    if (!response.success) {
                        var errMsg = response.data || 'batch not found';
                        ASAE_TO.finishBatchProgress('Error: ' + errMsg);
                        return;
                    }

                    var d = response.data;
                    $('#asae-to-processed-count').text(d.processed_items);
                    $('#asae-to-total-count').text(d.total_items);

                    var pct = d.total_items > 0
                        ? Math.round((d.processed_items / d.total_items) * 100) : 0;
                    ASAE_TO.setProgressBar(pct);

                    if (d.status === 'paused') {
                        var reason = d.pause_reason === 'rate_limited' ? 'rate limited by OpenAI' :
                                     d.pause_reason === 'budget_exceeded' ? 'monthly budget reached' : 'API error';
                        var retryText = d.next_retry_at
                            ? ' Will auto-retry at ' + ASAE_TO.formatDateTime(d.next_retry_at) + '.'
                            : '';
                        $('#asae-to-phase-label').html(
                            '<span class="asae-to-paused-label">Paused — ' + reason + '.</span>' +
                            retryText +
                            '<br><small>You can close this page. Processing resumes automatically via background cron.</small>'
                        );
                        $('#asae-to-progress-panel').addClass('asae-to-panel-paused');
                    } else if (d.status === 'processing' || d.status === 'pending') {
                        var statusText = 'Processing… ' + d.processed_items + ' of ' + d.total_items;
                        // If idle too long, show diagnostic hint
                        if (d.idle_seconds > 120) {
                            var idleMin = Math.floor(d.idle_seconds / 60);
                            statusText += ' — idle ' + idleMin + 'min';
                            if (d.lock_held) {
                                statusText += ' (locked, waiting for chunk to finish)';
                            } else if (d.cron_scheduled) {
                                statusText += ' (next cron in ' + d.cron_due_in + 's)';
                            } else {
                                statusText += ' (no cron scheduled — watchdog will requeue)';
                            }
                        }
                        $('#asae-to-phase-label').text(statusText);
                        $('#asae-to-progress-panel').removeClass('asae-to-panel-paused');
                    }

                    // Diagnostics footer
                    var diag = [];
                    diag.push('API calls: ' + d.api_calls_made);
                    if (d.updated_at) {
                        diag.push('last activity: ' + d.updated_at);
                    }
                    if (d.cron_scheduled) {
                        diag.push('cron: ' + (d.cron_due_in > 0 ? 'due in ' + d.cron_due_in + 's' : 'due now'));
                    } else if (!d.is_complete) {
                        diag.push('cron: not scheduled');
                    }
                    if (d.lock_held) {
                        diag.push('lock: held');
                    }
                    if (d.ran_directly) {
                        diag.push('ran chunk directly (cron bypass)');
                    }
                    $('#asae-to-diagnostics').text(diag.join(' · '));

                    if (d.is_complete) {
                        var msg = d.status === 'cancelled' ? 'Batch cancelled.' :
                                  'Complete! ' + d.processed_items + ' items processed.';
                        ASAE_TO.finishBatchProgress(msg);
                    } else {
                        // Keep polling — 2s normal, 30s if paused
                        var delay = d.status === 'paused' ? 30000 : 2000;
                        ASAE_TO.batchPollTimer = setTimeout(ASAE_TO.pollBatchProgress, delay);
                    }
                },
                error: function() {
                    // Network error — retry after 5s
                    ASAE_TO.batchPollTimer = setTimeout(ASAE_TO.pollBatchProgress, 5000);
                }
            });
        },

        finishBatchProgress: function(message) {
            ASAE_TO.stopHeartbeat();
            ASAE_TO.currentBatchId = null;
            if (ASAE_TO.batchPollTimer) {
                clearTimeout(ASAE_TO.batchPollTimer);
                ASAE_TO.batchPollTimer = null;
            }
            ASAE_TO.setProgressBar(100);
            $('#asae-to-phase-label').text('Done');
            $('#asae-to-progress-complete').text(message).show();
            $('#asae-to-cancel-batch-btn').hide();
            $('#process-btn').prop('disabled', false);
        },

        cancelCurrentBatch: function() {
            if (!ASAE_TO.currentBatchId) { return; }
            var $btn = $('#asae-to-cancel-batch-btn');
            $btn.prop('disabled', true).text('Cancelling…');
            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'asae_to_cancel_batch', nonce: asaeToAdmin.nonce, batch_id: ASAE_TO.currentBatchId },
                success: function() {
                    ASAE_TO.finishBatchProgress('Batch cancelled.');
                },
                error: function() {
                    $btn.prop('disabled', false).text('Cancel');
                }
            });
        },

        /**
         * Display results for direct-save mode (non-chunked).
         */
        displayResultsWithApproval: function(response) {
            var $resultsContainer = $('#results-container');
            var $resultsSummary = $('#results-summary');
            var $resultsActions = $('#results-actions');

            if (response.message) {
                $resultsSummary.html('<div class="success-message" role="status">' +
                    ASAE_TO.escapeHtml(response.message) + '</div>');
            }

            ASAE_TO.pendingResults = response.results.filter(function(r) {
                return r.needs_review && r.term_id;
            });

            if (response.preview_mode && ASAE_TO.pendingResults.length > 0) {
                $resultsActions.show();
            }

            if (response.results && response.results.length > 0) {
                $resultsContainer.html('<div class="results-list"></div>');
                ASAE_TO.appendChunkResults(response.results);
            } else if (!response.message) {
                $resultsContainer.html('<p>No results to display.</p>');
            }

            ASAE_TO.updateSelectedCount();
        },

        // =====================================================================
        // Approval workflow (mostly unchanged)
        // =====================================================================

        approveItem: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $item = $btn.closest('.result-item-enhanced');
            var postId = $btn.data('post-id');
            var termId = $btn.data('term-id');
            var tags = ASAE_TO.getItemTags($item);

            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_save_items',
                    nonce: asaeToAdmin.nonce,
                    taxonomy: ASAE_TO.currentTaxonomy,
                    items: JSON.stringify([{post_id: postId, term_id: termId, tags: tags}])
                },
                success: function(response) {
                    if (response.success) {
                        $item.find('.result-status').removeClass('pending').addClass('saved').text('Saved');
                        $item.find('.result-actions').remove();
                        $item.find('.result-checkbox').remove();
                        ASAE_TO.pendingResults = ASAE_TO.pendingResults.filter(function(r) { return r.post_id !== postId; });
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

        showRejectModal: function(e) {
            e.preventDefault();
            var $item = $(this).closest('.result-item-enhanced');
            var postId = $item.data('post-id');
            var suggestedTermId = $item.data('term-id');
            var suggestedTermName = $item.data('term-name');

            var categoryOptions = '<option value="">-- Keep no category --</option>';
            $.each(ASAE_TO.allTerms, function(i, term) {
                categoryOptions += '<option value="' + term.term_id + '">' +
                    ASAE_TO.escapeHtml(term.name) + '</option>';
            });

            var modalHtml = '<div class="modal-overlay" role="dialog" aria-modal="true" aria-label="Reject suggestion">' +
                '<div class="reject-modal">' +
                '<h3>Reject Suggestion</h3>' +
                '<p>The AI suggested: <strong>' + ASAE_TO.escapeHtml(suggestedTermName) + '</strong></p>' +
                '<div class="modal-field">' +
                '<label for="new-category">Select correct category (optional):</label>' +
                '<select id="new-category" class="regular-text">' + categoryOptions + '</select></div>' +
                '<div class="modal-field">' +
                '<label for="reject-notes">Why was this wrong? (helps improve AI):</label>' +
                '<textarea id="reject-notes" rows="3" class="large-text"></textarea></div>' +
                '<div class="modal-actions">' +
                '<button class="button button-primary reject-confirm" data-post-id="' + postId +
                '" data-suggested-term-id="' + suggestedTermId + '">Confirm Rejection</button>' +
                '<button class="button reject-cancel">Cancel</button></div></div></div>';

            $('body').append(modalHtml);
            // Focus the first interactive element in the modal
            $('#new-category').focus();
            // Escape key closes modal
            $(document).on('keydown.asae-modal', function(ev) {
                if (ev.key === 'Escape') {
                    ASAE_TO.closeModal();
                }
            });
        },

        confirmReject: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var suggestedTermId = $btn.data('suggested-term-id');
            var selectedTermId = $('#new-category').val();
            var notes = $('#reject-notes').val();

            $btn.prop('disabled', true).text('Saving...');

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
                success: function() {
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
                            success: function(r) { ASAE_TO.finishReject(postId, r.success ? 'Corrected' : 'Rejected'); },
                            error: function()    { ASAE_TO.finishReject(postId, 'Rejected'); }
                        });
                    } else {
                        ASAE_TO.finishReject(postId, 'Rejected');
                    }
                },
                error: function() { ASAE_TO.finishReject(postId, 'Rejected'); }
            });
        },

        closeModal: function() {
            var $overlay = $('.modal-overlay');
            if ($overlay.length) {
                $overlay.remove();
                $(document).off('keydown.asae-modal');
            }
        },

        finishReject: function(postId, statusText) {
            ASAE_TO.closeModal();
            var $item = $('.result-item-enhanced[data-post-id="' + postId + '"]');
            $item.find('.result-status').removeClass('pending').addClass('skipped').text(statusText);
            $item.find('.result-actions').remove();
            $item.find('.result-checkbox').remove();
            // Restore focus to the result item
            $item.attr('tabindex', '-1').focus();
            ASAE_TO.pendingResults = ASAE_TO.pendingResults.filter(function(r) { return r.post_id !== postId; });
            ASAE_TO.updateSelectedCount();
        },

        cancelReject: function(e) {
            if ($(e.target).hasClass('modal-overlay') || $(e.target).hasClass('reject-cancel')) {
                ASAE_TO.closeModal();
            }
        },

        approveAll: function(e) {
            e.preventDefault();
            var itemsToSave = [];
            $('.result-item-enhanced').each(function() {
                var $item = $(this);
                if ($item.find('.result-checkbox').length) {
                    itemsToSave.push({
                        post_id: $item.data('post-id'),
                        term_id: $item.data('term-id'),
                        tags: ASAE_TO.getItemTags($item)
                    });
                }
            });
            if (itemsToSave.length === 0) { alert('No items to approve.'); return; }

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

        approveSelected: function(e) {
            e.preventDefault();
            var itemsToSave = [];
            $('.result-item-enhanced').each(function() {
                var $item = $(this);
                var $cb = $item.find('.result-checkbox');
                if ($cb.length && $cb.is(':checked')) {
                    itemsToSave.push({
                        post_id: $item.data('post-id'),
                        term_id: $item.data('term-id'),
                        tags: ASAE_TO.getItemTags($item)
                    });
                }
            });
            if (itemsToSave.length === 0) { alert('No items selected.'); return; }

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

        rejectAll: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to reject all pending items?')) { return; }
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

        updateSelectedCount: function() {
            var checkedCount = $('.result-checkbox:checked').length;
            var totalPending = $('.result-checkbox').length;
            if (totalPending === 0) {
                $('#results-actions').hide();
            } else {
                $('#approve-selected-btn').text('Approve Selected (' + checkedCount + ')');
            }
        },

        // =====================================================================
        // Settings page
        // =====================================================================

        initSettingsPage: function() {
            $('#toggle-api-key').on('click', function() {
                var $input = $('#openai_api_key');
                var $btn = $(this);
                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $btn.attr('aria-label', 'Hide API key').html('<span class="dashicons dashicons-hidden"></span> Hide');
                } else {
                    $input.attr('type', 'password');
                    $btn.attr('aria-label', 'Show API key').html('<span class="dashicons dashicons-visibility"></span> Show');
                }
            });

            $('#test-connection-btn').on('click', this.testConnection);
            $('#asae-to-settings-form').on('submit', this.saveSettings);
            $('#use_ai').on('change', this.updateAIStatus);
            $('#reset-usage-btn').on('click', this.resetUsage);

            // GA4 settings
            $('#ga4-replace-creds-btn').on('click', function() {
                $('#ga4-creds-status').hide();
                $('#ga4-creds-input').show();
            });

            $('#save-ga4-settings-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ga4-settings-result');
                $btn.prop('disabled', true);
                $result.text('Saving…');

                $.post(asaeToAdmin.ajaxUrl, {
                    action: 'asae_to_save_ga4_settings',
                    nonce: asaeToAdmin.nonce,
                    property_id: $('#ga4_property_id').val(),
                    service_account_json: $('#ga4_service_account_json').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color:#155724;">' + ASAE_TO.escapeHtml(response.data.message) + '</span>');
                        if ($('#ga4_service_account_json').val()) {
                            // Reload to show the "configured" state
                            setTimeout(function() { location.reload(); }, 1000);
                        }
                    } else {
                        $result.html('<span style="color:#721c24;">' + ASAE_TO.escapeHtml(response.data) + '</span>');
                        $('#ga4-creds-error').text(response.data).show();
                    }
                    setTimeout(function() { $result.text(''); }, 5000);
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.text('Connection error.');
                });
            });

            $('#test-ga4-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#ga4-settings-result');
                $btn.prop('disabled', true);
                $result.text('Testing…');

                $.post(asaeToAdmin.ajaxUrl, {
                    action: 'asae_to_test_ga4_connection',
                    nonce: asaeToAdmin.nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color:#155724;">' + ASAE_TO.escapeHtml(response.data.message) + '</span>');
                    } else {
                        $result.html('<span style="color:#721c24;">' + ASAE_TO.escapeHtml(response.data) + '</span>');
                    }
                    setTimeout(function() { $result.text(''); }, 5000);
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.text('Connection error.');
                });
            });

            $('#cleanup-redundant-tags-btn').on('click', function() {
                ASAE_TO.startRedundantTagCleanup();
            });

            $('#save-report-settings-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#report-settings-result');
                $btn.prop('disabled', true);
                $result.text('Saving…');

                $.post(asaeToAdmin.ajaxUrl, {
                    action: 'asae_to_save_report_settings',
                    nonce: asaeToAdmin.nonce,
                    report_ignored_tags: $('#report_ignored_tags').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    $result.text(response.success ? response.data.message : 'Failed to save.');
                    setTimeout(function() { $result.text(''); }, 3000);
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.text('Connection error.');
                });
            });

            $('#check-updates-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#update-check-result');
                $btn.prop('disabled', true).text('Checking…');
                $result.text('');

                $.post(asaeToAdmin.ajaxUrl, {
                    action: 'asae_to_check_updates',
                    nonce: asaeToAdmin.nonce
                }, function(response) {
                    $btn.prop('disabled', false).text('Check for Updates Now');
                    if (response.success) {
                        var d = response.data;
                        if (d.has_update) {
                            $result.html('<strong style="color:#155724;">Update available: v' +
                                ASAE_TO.escapeHtml(d.new_version) + '</strong> — go to <a href="' +
                                asaeToAdmin.pluginsUrl + '">Plugins</a> to update.');
                        } else {
                            $result.html('<span style="color:#666;">You are running the latest version (v' +
                                ASAE_TO.escapeHtml(d.current_version) + ').</span>');
                        }
                    } else {
                        $result.text('Failed to check for updates.');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Check for Updates Now');
                    $result.text('Connection error.');
                });
            });
        },

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
                data: { action: 'asae_to_test_connection', nonce: asaeToAdmin.nonce, api_key: apiKey, model: model },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    var cls = response.success ? 'success-message' : 'error-message';
                    $result.html('<div class="' + cls + '">' + ASAE_TO.escapeHtml(response.message) + '</div>').show();
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('<div class="error-message">Connection test failed.</div>').show();
                }
            });
        },

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
                    use_ai: $('#use_ai').is(':checked') ? 'yes' : 'no',
                    monthly_api_limit: $('#monthly_api_limit').val(),
                    api_delay: $('#api_delay').val(),
                    retry_delay: $('#retry_delay').val()
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    var cls = response.success ? 'success-message' : 'error-message';
                    $result.html('<div class="' + cls + '">' + ASAE_TO.escapeHtml(response.message) + '</div>').show();
                    ASAE_TO.updateAIStatus();
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('<div class="error-message">Failed to save settings.</div>').show();
                }
            });
        },

        updateAIStatus: function() {
            var $status = $('#ai-status');
            var useAI = $('#use_ai').is(':checked');
            var hasKey = $('#openai_api_key').val().length > 0;

            if (useAI && hasKey)       { $status.html('<span class="status-active">AI Analysis Active</span>'); }
            else if (useAI && !hasKey)  { $status.html('<span class="status-warning">AI Enabled but No API Key</span>'); }
            else                        { $status.html('<span class="status-inactive">Using Keyword Matching</span>'); }
        },

        resetUsage: function(e) {
            e.preventDefault();
            if (!confirm('Reset the monthly API call counter to zero?')) { return; }

            $.ajax({
                url: asaeToAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'asae_to_reset_usage', nonce: asaeToAdmin.nonce },
                success: function(response) {
                    if (response.success) {
                        $('#usage-count').text('0');
                        $('.usage-bar-fill').css('width', '0%').removeClass('usage-danger usage-warning').addClass('usage-ok');
                    }
                }
            });
        },

        // =====================================================================
        // Redundant Tag Cleanup
        // =====================================================================

        startRedundantTagCleanup: function() {
            var $btn = $('#cleanup-redundant-tags-btn');
            var $results = $('#cleanup-results');
            var $summary = $('#cleanup-summary');

            $btn.prop('disabled', true).text('Scanning…');
            $summary.hide();
            $results.hide();

            // First call gets total count
            $.post(asaeToAdmin.ajaxUrl, {
                action: 'asae_to_cleanup_redundant_tags',
                nonce: asaeToAdmin.nonce,
                offset: 0,
                chunk_size: 1 // Just get the count without doing much work
            }, function(response) {
                if (!response.success) {
                    $btn.prop('disabled', false).text('Remove Redundant Tags');
                    $summary.html('<div class="error-message">Failed to scan.</div>').show();
                    return;
                }

                var total = response.data.total || 0;
                if (total === 0) {
                    $btn.prop('disabled', false).text('Remove Redundant Tags');
                    $summary.html('<div class="success-message">No posts found with both categories and tags.</div>').show();
                    return;
                }

                if (!confirm('Found ' + total.toLocaleString() + ' posts with both categories and tags. Scan and remove redundant tags?')) {
                    $btn.prop('disabled', false).text('Remove Redundant Tags');
                    return;
                }

                $btn.text('Processing…');
                $results.show();
                ASAE_TO.processCleanupChunk(0, total, 0, 0);
            }).fail(function() {
                $btn.prop('disabled', false).text('Remove Redundant Tags');
                $summary.html('<div class="error-message">Connection error.</div>').show();
            });
        },

        processCleanupChunk: function(offset, total, totalCleaned, totalRemoved) {
            $.post(asaeToAdmin.ajaxUrl, {
                action: 'asae_to_cleanup_redundant_tags',
                nonce: asaeToAdmin.nonce,
                offset: offset,
                chunk_size: 100
            }, function(response) {
                if (!response.success) {
                    $('#cleanup-progress-text').text('Error at offset ' + offset + '. Retrying…');
                    setTimeout(function() {
                        ASAE_TO.processCleanupChunk(offset, total, totalCleaned, totalRemoved);
                    }, 2000);
                    return;
                }

                var d = response.data;
                totalCleaned += d.posts_cleaned;
                totalRemoved += d.tags_removed;
                var processed = offset + d.processed;
                var pct = total > 0 ? Math.round((processed / total) * 100) : 100;

                $('#cleanup-progress-fill').css('width', pct + '%');
                $('.progress-bar').attr('aria-valuenow', pct);
                $('#cleanup-progress-text').text(
                    processed.toLocaleString() + ' of ' + total.toLocaleString() + ' posts scanned — ' +
                    totalRemoved + ' redundant tags removed'
                );

                if (d.has_more) {
                    ASAE_TO.processCleanupChunk(d.offset, total, totalCleaned, totalRemoved);
                } else {
                    $('#cleanup-results').hide();
                    $('#cleanup-redundant-tags-btn').prop('disabled', false).text('Remove Redundant Tags');
                    $('#cleanup-summary').html(
                        '<div class="success-message">Done. Scanned ' + processed.toLocaleString() +
                        ' posts. Cleaned ' + totalCleaned.toLocaleString() + ' posts, removed ' +
                        totalRemoved.toLocaleString() + ' redundant tags.</div>'
                    ).show();
                }
            }).fail(function() {
                $('#cleanup-progress-text').text('Connection error. Retrying…');
                setTimeout(function() {
                    ASAE_TO.processCleanupChunk(offset, total, totalCleaned, totalRemoved);
                }, 3000);
            });
        },

        // =====================================================================
        // Utilities
        // =====================================================================

        formatDate: function(dateStr) {
            if (!dateStr) return '';
            return new Date(dateStr).toLocaleDateString();
        },

        formatDateTime: function(dateStr) {
            if (!dateStr) return '';
            var d = new Date(dateStr.replace(' ', 'T') + 'Z');
            return d.toLocaleString();
        },

        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        getItemTags: function($item) {
            var tagsAttr = $item.attr('data-tags');
            if (!tagsAttr) return [];
            try { return JSON.parse(tagsAttr); } catch(e) { return []; }
        },

        capitalize: function(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
    };

    $(document).ready(function() { ASAE_TO.init(); });

})(jQuery);
