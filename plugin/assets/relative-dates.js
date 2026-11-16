/*
 * Static Bridge: relative dates that stay right on static pages (2.5.0).
 *
 * A theme or a builder prints «3 hours ago» with human_time_diff(); in a static
 * copy that text stays as it was at the export. With «Date relative aggiornate
 * nel browser» on, the copy carries the number in
 * <span data-sb-since="<from>" data-sb-to="<to>">3 hours</span>, with the
 * Unix times WordPress compared. This script works out the difference between
 * data-sb-since and now with the same steps as human_time_diff() and writes it
 * with Intl.NumberFormat in the language of <html lang>. «ago» or «in» is
 * outside the span and stays right. When the step and the number are those of
 * the export, the text is not touched, so a page just exported keeps the
 * WordPress translation. Without Intl, without a language, with a unit the
 * browser cannot write or with numbers that are not valid, the text stays.
 */
(function (window, document) {
    'use strict';

    var MINUTE = 60;
    var HOUR = 3600;
    var DAY = 86400;
    var WEEK = 604800;
    var MONTH = 2592000; // 30 days, as MONTH_IN_SECONDS
    var YEAR = 31536000; // 365 days, as YEAR_IN_SECONDS

    // The unit and the number human_time_diff() prints for a difference in seconds (WordPress 5.3+).
    function relativeStep(diff) {
        var steps = [[YEAR, 'year'], [MONTH, 'month'], [WEEK, 'week'], [DAY, 'day'], [HOUR, 'hour'], [MINUTE, 'minute']];
        for (var i = 0; i < steps.length; i++) {
            if (diff >= steps[i][0]) {
                return { unit: steps[i][1], value: Math.max(1, Math.round(diff / steps[i][0])) };
            }
        }
        return { unit: 'second', value: Math.max(1, diff) };
    }

    function readTime(element, name) {
        var value = element.getAttribute(name);
        return /^[0-9]{1,12}$/.test(value || '') ? parseInt(value, 10) : null;
    }

    function formatStep(lang, step) {
        try {
            var format = new window.Intl.NumberFormat(lang, { style: 'unit', unit: step.unit, unitDisplay: 'long' });
            var options = format.resolvedOptions();
            if (options.style !== 'unit' || options.unit !== step.unit) {
                return '';
            }
            return format.format(step.value);
        } catch (error) {
            return '';
        }
    }

    function updateRelativeDates(now) {
        var lang = document.documentElement ? (document.documentElement.getAttribute('lang') || '') : '';
        var updated = 0;
        if (!lang || !window.Intl || typeof window.Intl.NumberFormat !== 'function' || !document.querySelectorAll) {
            return updated;
        }
        var spans = document.querySelectorAll('[data-sb-since][data-sb-to]');
        for (var i = 0; i < spans.length; i++) {
            var since = readTime(spans[i], 'data-sb-since');
            var to = readTime(spans[i], 'data-sb-to');
            if (since === null || to === null) {
                continue;
            }
            // data-sb-to only says whether the date was past or future at the render: «ago» or «in» is fixed.
            var past = since <= to;
            var diff = past ? now - since : since - now;
            if (diff < 0) {
                continue;
            }
            var exported = relativeStep(Math.abs(to - since));
            var current = relativeStep(diff);
            if (current.unit === exported.unit && current.value === exported.value) {
                continue;
            }
            var text = formatStep(lang, current);
            if (text) {
                spans[i].textContent = text;
                updated++;
            }
        }
        return updated;
    }

    window.staticBridgeRelativeDates = {
        step: relativeStep,
        updated: updateRelativeDates(Math.floor(Date.now() / 1000))
    };
})(window, document);
