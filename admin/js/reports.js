/**
 * ASAE Taxonomy Organizer - Reports JavaScript
 *
 * Handles Chart.js donut chart rendering, AJAX data loading,
 * drill-down interaction, and accessible data table generation.
 *
 * @package ASAE_Taxonomy_Organizer
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var ASAE_TO_Reports = {
        chart: null,
        currentView: 'categories', // 'categories' or 'tags'
        currentPostType: 'post',
        currentCategoryId: null,
        currentCategoryName: '',
        canvasId: null,
        tableWrapId: null,
        spinnerId: null,
        backBtnId: null,
        titleId: null,
        isDashboard: false,

        /**
         * Initialize the Reports tab chart.
         */
        initTab: function() {
            this.canvasId = 'asae-to-report-chart';
            this.tableWrapId = 'asae-to-report-table-wrap';
            this.spinnerId = 'asae-to-chart-spinner';
            this.backBtnId = 'asae-to-report-back';
            this.titleId = 'asae-to-report-title';
            this.isDashboard = false;

            var $postType = $('#asae-to-report-post-type');
            if (!$postType.length) return;

            this.currentPostType = $postType.val();

            $postType.on('change', function() {
                ASAE_TO_Reports.currentPostType = $(this).val();
                ASAE_TO_Reports.loadCategories();
            });

            $('#' + this.backBtnId).on('click', function() {
                ASAE_TO_Reports.loadCategories();
            });

            this.loadCategories();
        },

        /**
         * Initialize the Dashboard widget chart.
         */
        initDashboard: function() {
            this.canvasId = 'asae-to-dash-chart';
            this.tableWrapId = 'asae-to-dash-table-wrap';
            this.spinnerId = 'asae-to-dash-spinner';
            this.backBtnId = 'asae-to-dash-back';
            this.titleId = null;
            this.isDashboard = true;
            this.currentPostType = 'post';

            var $canvas = $('#' + this.canvasId);
            if (!$canvas.length) return;

            $('#' + this.backBtnId).on('click', function() {
                ASAE_TO_Reports.loadCategories();
            });

            this.loadCategories();
        },

        /**
         * Load category breakdown via AJAX.
         */
        loadCategories: function() {
            this.currentView = 'categories';
            this.currentCategoryId = null;
            this.showSpinner();
            this.hideBackBtn();
            this.setTitle('Content by Category');

            $.ajax({
                url: asaeToReports.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_report_categories',
                    nonce: asaeToReports.nonce,
                    post_type: this.currentPostType
                },
                success: function(response) {
                    if (!response.success) {
                        ASAE_TO_Reports.showEmpty('Failed to load report data.');
                        return;
                    }
                    ASAE_TO_Reports.renderCategoryChart(response.data);
                },
                error: function() {
                    ASAE_TO_Reports.showEmpty('Connection error loading report.');
                }
            });
        },

        /**
         * Load tag drill-down via AJAX.
         */
        loadTags: function(termId, categoryName, catCount) {
            this.currentView = 'tags';
            this.currentCategoryId = termId;
            this.currentCategoryName = categoryName;
            this.showSpinner();
            this.showBackBtn();
            var countLabel = catCount ? ' (' + catCount.toLocaleString() + ' posts)' : '';
            this.setTitle('Tags in "' + categoryName + '"' + countLabel);

            $.ajax({
                url: asaeToReports.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asae_to_get_report_tags',
                    nonce: asaeToReports.nonce,
                    post_type: this.currentPostType,
                    category_term_id: termId
                },
                success: function(response) {
                    if (!response.success) {
                        ASAE_TO_Reports.showEmpty('Failed to load tag data.');
                        return;
                    }
                    ASAE_TO_Reports.renderTagChart(response.data);
                },
                error: function() {
                    ASAE_TO_Reports.showEmpty('Connection error loading tags.');
                }
            });
        },

        /**
         * Render category donut chart + data table.
         */
        renderCategoryChart: function(data) {
            var labels = [];
            var counts = [];
            var colors = [];
            var termIds = [];

            $.each(data.categories, function(i, cat) {
                labels.push(cat.name);
                counts.push(cat.count);
                colors.push(cat.color);
                termIds.push(cat.term_id);
            });

            if (data.uncategorized_count > 0) {
                labels.push('Uncategorized');
                counts.push(data.uncategorized_count);
                colors.push('#ccc');
                termIds.push(null);
            }

            if (labels.length === 0) {
                this.showEmpty('No categorized content found for this post type.');
                return;
            }

            this.renderChart(labels, counts, colors, function(index) {
                var tid = termIds[index];
                if (tid) {
                    ASAE_TO_Reports.loadTags(tid, labels[index], counts[index]);
                }
            });

            this.renderTable(labels, counts, colors, data.total_posts, termIds, true);

            // Update canvas aria-label
            var summary = 'Donut chart showing ' + data.total_posts + ' posts across ' +
                data.categories.length + ' categories.';
            if (data.categories.length > 0) {
                summary += ' Largest: ' + data.categories[0].name + ' with ' + data.categories[0].count + ' posts.';
            }
            $('#' + this.canvasId).attr('aria-label', summary);
        },

        /**
         * Render tag donut chart + data table.
         */
        renderTagChart: function(data) {
            var labels = [];
            var counts = [];
            var colors = [];

            $.each(data.tags, function(i, tag) {
                labels.push(tag.name);
                counts.push(tag.count);
                colors.push(tag.color);
            });

            if (data.other_count > 0) {
                labels.push('Other');
                counts.push(data.other_count);
                colors.push('#ccc');
            }

            if (labels.length === 0) {
                this.showEmpty('No tags found for posts in this category.');
                return;
            }

            // Use total posts in category as denominator (not sum of tag counts,
            // since one post can have multiple tags)
            var totalInCat = data.total_in_category;

            this.renderChart(labels, counts, colors, null);
            this.renderTable(labels, counts, colors, totalInCat, null, false);

            var countLabel = totalInCat ? ' (' + totalInCat.toLocaleString() + ' posts)' : '';
            this.setTitle('Tags in "' + data.category_name + '"' + countLabel);

            var summary = 'Donut chart showing top ' + data.tags.length + ' tags in "' +
                data.category_name + '". ' + data.total_in_category + ' posts in this category.';
            $('#' + this.canvasId).attr('aria-label', summary);
        },

        /**
         * Render or update the Chart.js donut chart.
         */
        renderChart: function(labels, counts, colors, onClickFn) {
            this.hideSpinner();
            var $canvas = $('#' + this.canvasId);
            $canvas.show();

            if (this.chart) {
                this.chart.destroy();
            }

            var ctx = $canvas[0].getContext('2d');
            this.chart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: counts,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '45%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                    return ctx.label + ': ' + ctx.parsed.toLocaleString() + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    onClick: function(evt, elements) {
                        if (onClickFn && elements.length > 0) {
                            onClickFn(elements[0].index);
                        }
                    }
                }
            });
        },

        /**
         * Render the accessible data table.
         */
        renderTable: function(labels, counts, colors, total, termIds, drillable) {
            var html = '<table class="asae-to-report-table" role="table">';
            html += '<thead><tr><th scope="col">Name</th><th scope="col" class="count-cell">Count</th><th scope="col" class="pct-cell">%</th></tr></thead>';
            html += '<tbody>';

            $.each(labels, function(i, label) {
                var pct = total > 0 ? Math.round((counts[i] / total) * 100) : 0;
                var isDrillable = drillable && termIds && termIds[i] !== null;
                var escapedLabel = $('<span>').text(label).html();

                html += '<tr>';
                html += '<td>';
                html += '<span class="color-swatch" style="background:' + colors[i] + ';" aria-hidden="true"></span>';
                if (isDrillable) {
                    html += '<button type="button" class="drill-btn" data-term-id="' + termIds[i] + '" data-name="' + escapedLabel + '" data-count="' + counts[i] + '">';
                    html += escapedLabel + ' &#9654;';
                    html += '</button>';
                } else {
                    html += '<span class="no-drill">' + escapedLabel + '</span>';
                }
                html += '</td>';
                html += '<td class="count-cell">' + counts[i].toLocaleString() + '</td>';
                html += '<td class="pct-cell">' + pct + '%</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';

            var $wrap = $('#' + this.tableWrapId);
            $wrap.html(html);

            // Bind drill-down clicks on table buttons
            $wrap.find('.drill-btn').on('click', function() {
                var tid = $(this).data('term-id');
                var name = $(this).data('name');
                var count = $(this).data('count');
                ASAE_TO_Reports.loadTags(tid, name, count);
            });
        },

        // =================================================================
        // UI helpers
        // =================================================================

        showSpinner: function() {
            $('#' + this.spinnerId).show();
            $('#' + this.canvasId).hide();
            $('#' + this.tableWrapId).empty();
        },

        hideSpinner: function() {
            $('#' + this.spinnerId).hide();
        },

        showBackBtn: function() {
            $('#' + this.backBtnId).show();
            if (this.isDashboard) {
                $('#asae-to-dash-controls').show();
            }
        },

        hideBackBtn: function() {
            $('#' + this.backBtnId).hide();
            if (this.isDashboard) {
                $('#asae-to-dash-controls').hide();
            }
        },

        setTitle: function(text) {
            if (this.titleId) {
                $('#' + this.titleId).text(text);
            }
        },

        showEmpty: function(message) {
            this.hideSpinner();
            $('#' + this.canvasId).hide();
            $('#' + this.tableWrapId).html(
                '<p class="asae-to-report-empty">' + $('<span>').text(message).html() + '</p>'
            );
        }
    };

    // Initialize on DOM ready
    $(function() {
        // Reports tab (plugin page)
        if ($('#asae-to-report-chart').length) {
            ASAE_TO_Reports.initTab();
        }

        // Dashboard widget
        if ($('#asae-to-dash-chart').length) {
            ASAE_TO_Reports.initDashboard();
        }
    });

})(jQuery);
