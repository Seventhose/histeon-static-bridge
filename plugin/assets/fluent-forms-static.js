/*
 * Static Bridge: current Fluent Forms nonces on static pages.
 *
 * A static copy keeps the nonces Fluent Forms printed when the page was
 * generated; they expire after 12-24 hours. This script asks WordPress for
 * current ones (admin-ajax, POST, no-store) the first time a visitor uses a
 * form, and makes sure Fluent Forms' own requests carry them:
 * - token generation (action fluentform_generate_protection_token, "nonce");
 * - submission (action fluentform_submit, _fluentform_<id>_fluentformnonce);
 * - uploaded file removal (_fluentform_file_delete_nonce);
 * - step-form progress (fluentFormVars.nonce, wp_create_nonce() with no
 *   action, sent back only by Fluent Forms Pro as "nonce").
 * A request sent before the refresh has answered waits for it. If the refresh
 * fails, requests leave unchanged, exactly as without this script.
 */
(function (window, document) {
    'use strict';

    var script = document.currentScript;
    var endpoint = script ? script.getAttribute('data-endpoint') : '';
    if (!endpoint || window.staticBridgeFluentForms) {
        return;
    }

    var ACTIONS = /(?:^|&)action=(fluentform_generate_protection_token|fluentform_submit|fluentform_delete_uploaded_file|fluentform_step_form_get_data|fluentform_step_form_save_data)(?:&|$)/;
    var state = { values: null, fetchedAt: 0, maxAge: 0, pending: null, requests: 0 };
    window.staticBridgeFluentForms = state;

    function fresh() {
        return !!state.values && Date.now() - state.fetchedAt < state.maxAge;
    }

    function refresh() {
        if (fresh()) {
            return Promise.resolve(state.values);
        }
        if (state.pending) {
            return state.pending;
        }
        state.pending = new Promise(function (resolve) {
            var xhr = new XMLHttpRequest();
            var settled = false;
            var done = function (values) {
                if (settled) {
                    return;
                }
                settled = true;
                state.pending = null;
                if (values) {
                    state.values = values;
                    state.fetchedAt = Date.now();
                    // Valid for at least ttl seconds; refresh well before that.
                    state.maxAge = Math.max(30, Math.min(1800, Math.floor(values.ttl / 2) || 0)) * 1000;
                    apply(values);
                }
                resolve(state.values);
            };
            state.requests++;
            xhr.open('POST', endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.timeout = 15000;
            xhr.onload = function () {
                var values = null;
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.success && response.data && typeof response.data.submit_nonce === 'string') {
                        values = response.data;
                    }
                } catch (error) {
                    values = null;
                }
                done(values);
            };
            xhr.onerror = xhr.ontimeout = xhr.onabort = function () {
                done(null);
            };
            xhr.send('action=static_bridge_public_form_nonces');
        });
        return state.pending;
    }

    /* Also update the page, for code that reads the values without jQuery.ajax. */
    function apply(values) {
        var vars = window.fluentFormVars;
        if (vars && typeof vars === 'object') {
            if (values.token_nonce && 'token_nonce' in vars) {
                vars.token_nonce = values.token_nonce;
            }
            if (values.file_delete_nonce && 'file_delete_nonce' in vars) {
                vars.file_delete_nonce = values.file_delete_nonce;
            }
            if (values.vars_nonce && 'nonce' in vars) {
                vars.nonce = values.vars_nonce;
            }
        }
        var fields = document.querySelectorAll('form.frm-fluent-form input[type="hidden"][name$="_fluentformnonce"]');
        for (var i = 0; i < fields.length; i++) {
            if (/^_fluentform_\d+_fluentformnonce$/.test(fields[i].name)) {
                fields[i].value = values.submit_nonce;
            }
        }
    }

    function replaceParam(data, name, value) {
        var pattern = new RegExp('((?:^|&)' + name + '=)[^&]*');
        return pattern.test(data) ? data.replace(pattern, '$1' + encodeURIComponent(value)) : data;
    }

    /* Rewrite the nonces in an urlencoded request body; anything else stays as sent. */
    function rewrite(data) {
        var values = state.values;
        if (!values || typeof data !== 'string') {
            return data;
        }
        var match = ACTIONS.exec(data);
        if (!match) {
            return data;
        }
        if (match[1] === 'fluentform_generate_protection_token' && values.token_nonce) {
            return replaceParam(data, 'nonce', values.token_nonce);
        }
        if (match[1] === 'fluentform_delete_uploaded_file' && values.file_delete_nonce) {
            return replaceParam(data, '_fluentform_file_delete_nonce', values.file_delete_nonce);
        }
        if ((match[1] === 'fluentform_step_form_get_data' || match[1] === 'fluentform_step_form_save_data') && values.vars_nonce) {
            return replaceParam(data, 'nonce', values.vars_nonce);
        }
        if (match[1] === 'fluentform_submit') {
            // The form fields travel urlencoded inside "data": there "=" is %3D and "&" is %26.
            return data.replace(/(_fluentform_\d+_fluentformnonce(?:=|%3D))[^&%]*/gi, function (all, prefix) {
                return prefix + values.submit_nonce;
            });
        }
        return data;
    }

    function wanted(options) {
        return typeof options.data === 'string' && ACTIONS.test(options.data)
            && String(options.url || '').indexOf('admin-ajax.php') !== -1;
    }

    function install() {
        var $ = window.jQuery;
        if (!$ || !$.ajaxPrefilter || !$.ajaxTransport || $.staticBridgeFluentForms) {
            return !!($ && $.staticBridgeFluentForms);
        }
        $.staticBridgeFluentForms = true;
        $.ajaxPrefilter(function (options) {
            if (!wanted(options)) {
                return;
            }
            if (fresh()) {
                options.data = rewrite(options.data);
            } else {
                options.staticBridgeWait = true;
                refresh();
            }
        });
        // Fluent Forms asks for its token on the same first focus that starts
        // the refresh: hold that request until current nonces have arrived.
        $.ajaxTransport('+*', function (options) {
            if (!options.staticBridgeWait) {
                return undefined;
            }
            var xhr = null;
            var aborted = false;
            return {
                send: function (headers, complete) {
                    refresh().then(function () {
                        if (aborted) {
                            return;
                        }
                        xhr = options.xhr();
                        xhr.open(options.type, options.url, options.async, options.username, options.password);
                        if (options.xhrFields) {
                            for (var field in options.xhrFields) {
                                xhr[field] = options.xhrFields[field];
                            }
                        }
                        if (options.mimeType && xhr.overrideMimeType) {
                            xhr.overrideMimeType(options.mimeType);
                        }
                        if (!options.crossDomain && !headers['X-Requested-With']) {
                            headers['X-Requested-With'] = 'XMLHttpRequest';
                        }
                        for (var name in headers) {
                            xhr.setRequestHeader(name, headers[name]);
                        }
                        xhr.onload = function () {
                            complete(xhr.status, xhr.statusText, { text: xhr.responseText }, xhr.getAllResponseHeaders());
                        };
                        xhr.onerror = xhr.ontimeout = function () {
                            complete(0, 'error');
                        };
                        xhr.send(options.hasContent && rewrite(options.data) || null);
                    });
                },
                abort: function () {
                    aborted = true;
                    if (xhr) {
                        xhr.abort();
                    }
                }
            };
        });
        return true;
    }

    function onInteraction(event) {
        var target = event.target;
        if (target && target.closest && target.closest('form.frm-fluent-form')) {
            refresh();
        }
    }

    state.refresh = refresh;
    state.rewrite = rewrite;
    if (!install()) {
        document.addEventListener('DOMContentLoaded', install);
        window.addEventListener('load', install);
    }
    // Capture phase: runs before Fluent Forms' own first-interaction handlers.
    document.addEventListener('focusin', onInteraction, true);
    document.addEventListener('pointerdown', onInteraction, true);
    document.addEventListener('touchstart', onInteraction, { capture: true, passive: true });
}(window, document));
