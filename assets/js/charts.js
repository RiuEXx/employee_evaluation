/*
 * Draws every chart on the page with Chart.js (assets/vendor/chart.umd.min.js).
 *
 * PHP writes each chart's data as JSON into a <script type="application/json">
 * block (see chart_canvas() in includes/functions.php), and the canvas points
 * to it with data-chart="<id>". The JSON looks like:
 *   { "type": "bar" | "horizontalBar" | "radar" | "line",
 *     "labels": [...],
 *     "datasets": [{ "label": "...", "data": [...], "color": "blue" | "green" | "yellow" }] }
 * Scores are on the 1 to 5 scale, so every axis runs from 0 to 5.
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined') {
        return;
    }

    // Pastel colours, matching the CSS variables.
    var palette = {
        blue:   { line: '#6f9fcf', fill: 'rgba(169, 201, 232, 0.75)', soft: 'rgba(169, 201, 232, 0.30)' },
        green:  { line: '#6fb487', fill: 'rgba(191, 227, 204, 0.85)', soft: 'rgba(191, 227, 204, 0.30)' },
        yellow: { line: '#d1ae45', fill: 'rgba(246, 226, 161, 0.85)', soft: 'rgba(246, 226, 161, 0.30)' }
    };

    Chart.defaults.color = '#1e2935';
    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    Chart.defaults.font.size = 13;
    Chart.defaults.plugins.legend.labels.boxWidth = 14;

    var gridColor = 'rgba(30, 41, 53, 0.10)';
    var scoreTicks = { stepSize: 1, precision: 0 };

    function tooltipLabel(context) {
        var value = context.parsed && typeof context.parsed === 'object'
            ? (context.parsed.r !== undefined ? context.parsed.r : (context.chart.options.indexAxis === 'y' ? context.parsed.x : context.parsed.y))
            : context.parsed;
        if (value === null || value === undefined) {
            return context.dataset.label + ': no data';
        }
        return context.dataset.label + ': ' + Number(value).toFixed(2);
    }

    function buildDataset(ds, type) {
        var colors = palette[ds.color] || palette.blue;
        if (type === 'line') {
            return {
                label: ds.label,
                data: ds.data,
                borderColor: colors.line,
                backgroundColor: colors.fill,
                pointBackgroundColor: colors.line,
                pointRadius: 4,
                borderWidth: 2.5,
                tension: 0.25,
                spanGaps: true
            };
        }
        if (type === 'radar') {
            return {
                label: ds.label,
                data: ds.data,
                borderColor: colors.line,
                backgroundColor: colors.soft,
                pointBackgroundColor: colors.line,
                borderWidth: 2
            };
        }
        return {
            label: ds.label,
            data: ds.data,
            backgroundColor: colors.fill,
            borderColor: colors.line,
            borderWidth: 1,
            borderRadius: 4,
            maxBarThickness: 46
        };
    }

    // Splits a long label into lines of about 16 characters, so axis and radar
    // labels stay readable (and unclipped) even on a phone.
    function wrapLabel(label) {
        var words = String(label).split(' ');
        var lines = [];
        var line = '';
        words.forEach(function (word) {
            if (line && (line + ' ' + word).length > 16) {
                lines.push(line);
                line = word;
            } else {
                line = line ? line + ' ' + word : word;
            }
        });
        lines.push(line);
        return lines.length > 1 ? lines : label;
    }

    function buildConfig(spec) {
        var horizontal = spec.type === 'horizontalBar';
        var type = horizontal ? 'bar' : spec.type;
        var showLegend = spec.datasets.length > 1;
        var labels = spec.labels.map(wrapLabel);

        var config = {
            type: type,
            data: {
                labels: labels,
                datasets: spec.datasets.map(function (ds) { return buildDataset(ds, type); })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 400 },
                plugins: {
                    legend: { display: showLegend, position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: tooltipLabel,
                            // Show the unwrapped label as the tooltip title.
                            title: function (items) {
                                return items.length ? spec.labels[items[0].dataIndex] : '';
                            }
                        }
                    }
                }
            }
        };

        if (type === 'radar') {
            config.options.scales = {
                r: {
                    min: 0,
                    max: 5,
                    ticks: { stepSize: 1, backdropColor: 'transparent' },
                    grid: { color: gridColor },
                    angleLines: { color: gridColor },
                    pointLabels: { font: { size: 12 } }
                }
            };
        } else {
            var valueAxis = { min: 0, max: 5, ticks: scoreTicks, grid: { color: gridColor }, title: { display: true, text: 'Average score (1 to 5)' } };
            var categoryAxis = { grid: { display: false }, ticks: { autoSkip: false, maxRotation: horizontal ? 0 : 45 } };
            if (horizontal) {
                config.options.indexAxis = 'y';
                config.options.scales = { x: valueAxis, y: categoryAxis };
            } else {
                config.options.scales = { x: categoryAxis, y: valueAxis };
            }
        }

        return config;
    }

    document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
        var source = document.getElementById(canvas.getAttribute('data-chart'));
        if (!source) {
            return;
        }
        try {
            new Chart(canvas, buildConfig(JSON.parse(source.textContent)));
        } catch (err) {
            if (window.console) {
                window.console.error('Chart could not be drawn:', err);
            }
        }
    });
})();
