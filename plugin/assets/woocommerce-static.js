/*
 * Static Bridge: current WooCommerce nonces on static pages (2.2.13).
 *
 * A static copy keeps the Store API nonce (wc_store_api) and the REST nonce
 * (wp_rest) WordPress printed when the page was generated; they expire after
 * 12-24 hours, and the Store API then refuses "add to cart" with 403. The page
 * carries the stale values in data-stale. This script lets every other request
 * go untouched; a same-site REST request (/wp-json/ or ?rest_route=) whose
 * headers or body carry one of those stale values waits for current ones
 * (admin-ajax, POST, no-store, asked once and reused while valid) and leaves
 * with them: mini-cart, block cart buttons, Interactivity API stores and the
 * Store API batch, which repeats the nonce inside its JSON body. Other values
 * (a nonce WooCommerce already took from a response header) are not changed.
 * If the refresh fails, requests leave unchanged, exactly as without this script.
 */
(function (window, document) {
    'use strict';

    var script = document.currentScript;
    var endpoint = script ? script.getAttribute('data-endpoint') : '';
    if (!endpoint || window.staticBridgeWooCommerce || typeof window.fetch !== 'function') {
        return;
    }
    var stale = {};
    try {
        stale = JSON.parse(script.getAttribute('data-stale') || '{}') || {};
    } catch (error) {
        stale = {};
    }
    // Stale value => key of the fresh value in the endpoint's answer.
    var replace = {};
    if (/^[a-f0-9]{8,32}$/.test(stale.wc_store_api || '')) {
        replace[stale.wc_store_api] = 'store_api';
    }
    if (/^[a-f0-9]{8,32}$/.test(stale.wp_rest || '')) {
        replace[stale.wp_rest] = 'rest';
    }
    var staleValues = Object.keys(replace);
    if (!staleValues.length) {
        return;
    }

    var nativeFetch = window.fetch;
    var state = { values: null, fetchedAt: 0, maxAge: 0, pending: null, requests: 0, rewritten: 0 };
    window.staticBridgeWooCommerce = state;

    function fresh() {
        return !!state.values && Date.now() - state.fetchedAt < state.maxAge;
    }

    function share(values) {
        // Later requests built by WordPress and WooCommerce scripts carry the current values directly.
        var api = window.wp && window.wp.apiFetch;
        if (values.store_api) {
            if (window.wcBlocksMiddlewareConfig) {
                window.wcBlocksMiddlewareConfig.storeApiNonce = values.store_api;
            }
            if (api && typeof api.setNonce === 'function') {
                try {
                    api.setNonce({ Nonce: values.store_api });
                } catch (error) { /* an older WooCommerce: the rewrite below still applies */ }
            }
        }
        if (values.rest && api && api.nonceMiddleware) {
            api.nonceMiddleware.nonce = values.rest;
        }
    }

    function refresh() {
        if (fresh()) {
            return Promise.resolve(state.values);
        }
        if (state.pending) {
            return state.pending;
        }
        state.requests++;
        state.pending = Promise.resolve().then(function () {
            return nativeFetch.call(window, endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: 'action=static_bridge_public_woocommerce_nonces'
            });
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (json) {
            var values = json && json.success && json.data ? json.data : null;
            if (values && /^[a-f0-9]{8,32}$/.test(values.store_api || '') && /^[a-f0-9]{8,32}$/.test(values.rest || '')) {
                state.values = values;
                state.fetchedAt = Date.now();
                // Valid for at least ttl seconds; refresh well before that.
                state.maxAge = Math.max(30, Math.min(1800, Math.floor(values.ttl / 2) || 0)) * 1000;
                share(values);
            }
            return state.values;
        }).catch(function () {
            return state.values;
        }).then(function (values) {
            state.pending = null;
            return values;
        });
        return state.pending;
    }

    function sameSiteRest(url) {
        var parsed;
        try {
            parsed = new URL(url, window.location.href);
        } catch (error) {
            return false;
        }
        return parsed.origin === window.location.origin
            && (parsed.pathname.indexOf('/wp-json/') !== -1 || parsed.searchParams.has('rest_route'));
    }

    function carriesStale(text) {
        if (typeof text !== 'string' || !text) {
            return false;
        }
        for (var i = 0; i < staleValues.length; i++) {
            if (text.indexOf(staleValues[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    function headerList(headers) {
        var list = [];
        if (!headers) {
            return list;
        }
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            headers.forEach(function (value, name) {
                list.push([name, value]);
            });
        } else if (Array.isArray(headers)) {
            headers.forEach(function (pair) {
                list.push([pair[0], pair[1]]);
            });
        } else if (typeof headers === 'object') {
            Object.keys(headers).forEach(function (name) {
                list.push([name, headers[name]]);
            });
        }
        return list;
    }

    function swap(text, values) {
        staleValues.forEach(function (value) {
            text = text.split(value).join(values[replace[value]]);
        });
        return text;
    }

    window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && typeof input.href === 'string' ? input.href : '');
        // Request objects and other sites go untouched.
        if (!url || !sameSiteRest(url)) {
            return nativeFetch.apply(this, arguments);
        }
        var headers = headerList(init && init.headers);
        var stalePair = headers.some(function (pair) {
            return carriesStale(String(pair[1]));
        });
        var staleBody = init && carriesStale(init.body);
        if (!stalePair && !staleBody) {
            return nativeFetch.apply(this, arguments);
        }
        var self = this;
        var args = arguments;
        return refresh().then(function (values) {
            if (!values) {
                return nativeFetch.apply(self, args);
            }
            var options = {};
            Object.keys(init).forEach(function (key) {
                options[key] = init[key];
            });
            var rebuilt = {};
            headers.forEach(function (pair) {
                rebuilt[pair[0]] = swap(String(pair[1]), values);
            });
            options.headers = rebuilt;
            if (staleBody) {
                options.body = swap(init.body, values);
            }
            state.rewritten++;
            return nativeFetch.call(self, input, options);
        });
    };
}(window, document));
