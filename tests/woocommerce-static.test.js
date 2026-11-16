'use strict';

// Unit tests for plugin/assets/woocommerce-static.js with a fake window.fetch. The real WooCommerce + browser
// path (All Products block, Store API batch after the nonce expired) is covered by tests/e2e/wc-e2e.sh.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../plugin/assets/woocommerce-static.js'), 'utf8');
const ORIGIN = 'https://shop.example';
const ENDPOINT = '/wp-admin/admin-ajax.php';
const STALE = { wc_store_api: 'aaaaaaaaaa', wp_rest: 'bbbbbbbbbb' };
const FRESH = { store_api: 'cccccccccc', rest: 'dddddddddd', ttl: 43200 };

function setup(options = {}) {
    const calls = [];
    let answer = options.answer || (() => ({ ok: true, json: () => Promise.resolve({ success: true, data: FRESH }) }));
    const nativeFetch = (input, init) => {
        calls.push({ input, init: init || {} });
        if (input === ENDPOINT) {
            return Promise.resolve(answer());
        }
        return Promise.resolve({ ok: true, status: 200 });
    };
    const apiFetch = { nonceMiddleware: { nonce: STALE.wp_rest }, setNonce(headers) { this.storeNonce = headers.Nonce; } };
    const script = {
        getAttribute: (name) => (name === 'data-endpoint' ? ENDPOINT : (name === 'data-stale' ? JSON.stringify(options.stale || STALE) : null)),
    };
    const window = {
        fetch: nativeFetch,
        location: { href: ORIGIN + '/catalogo/', origin: ORIGIN },
        wp: { apiFetch },
        wcBlocksMiddlewareConfig: { storeApiNonce: STALE.wc_store_api },
    };
    const context = { window, document: { currentScript: script }, URL, Headers, Promise, JSON, Math, Date, Object, Array, String };
    vm.runInNewContext(source, context);
    return { window, calls, apiFetch, setAnswer: (fn) => { answer = fn; } };
}

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

test('other requests, other sites and REST requests without a stale value go untouched', async () => {
    const { window, calls } = setup();
    await window.fetch(ORIGIN + '/shop/?orderby=price');
    await window.fetch('https://elsewhere.example/wp-json/wc/store/v1/cart', { headers: { Nonce: STALE.wc_store_api } });
    await window.fetch(ORIGIN + '/wp-json/wc/store/v1/batch', { method: 'POST', headers: { Nonce: 'eeeeeeeeee' }, body: '{"requests":[]}' });
    assert.equal(calls.length, 3);
    assert.ok(calls.every((call) => call.input !== ENDPOINT), 'the refresh was asked without need');
    assert.equal(window.staticBridgeWooCommerce.requests, 0);
});

test('a Store API batch with the stale nonce in its header and body waits and leaves with the fresh one', async () => {
    const { window, calls, apiFetch } = setup();
    const body = JSON.stringify({ requests: [{ method: 'POST', path: '/wc/store/v1/cart/add-item', headers: { Nonce: STALE.wc_store_api }, body: { id: 30 } }] });
    await window.fetch(ORIGIN + '/wp-json/wc/store/v1/batch?_locale=site', { method: 'POST', credentials: 'same-origin', headers: { Nonce: STALE.wc_store_api, 'X-WP-Nonce': STALE.wp_rest, 'Content-Type': 'application/json' }, body });
    assert.equal(calls.length, 2);
    assert.equal(calls[0].input, ENDPOINT);
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.cache, 'no-store');
    assert.equal(calls[0].init.body, 'action=static_bridge_public_woocommerce_nonces');
    const sent = calls[1];
    assert.equal(sent.init.headers.Nonce, FRESH.store_api);
    assert.equal(sent.init.headers['X-WP-Nonce'], FRESH.rest);
    assert.equal(sent.init.headers['Content-Type'], 'application/json');
    assert.equal(sent.init.credentials, 'same-origin');
    assert.ok(sent.init.body.includes(FRESH.store_api) && !sent.init.body.includes(STALE.wc_store_api), 'the nonce inside the batch body was not renewed');
    assert.equal(apiFetch.storeNonce, FRESH.store_api, 'WooCommerce middleware was not given the fresh nonce');
    assert.equal(apiFetch.nonceMiddleware.nonce, FRESH.rest, 'the REST nonce middleware was not updated');
    assert.equal(window.wcBlocksMiddlewareConfig.storeApiNonce, FRESH.store_api);
});

test('one refresh serves concurrent requests and is reused while valid; ?rest_route= counts as REST', async () => {
    const { window, calls } = setup();
    await Promise.all([
        window.fetch(ORIGIN + '/wp-json/wc/store/v1/cart/add-item', { method: 'POST', headers: { Nonce: STALE.wc_store_api } }),
        window.fetch('/?rest_route=/wc/store/v1/cart/add-item', { method: 'POST', headers: [['Nonce', STALE.wc_store_api]] }),
    ]);
    await window.fetch(ORIGIN + '/wp-json/wp/v2/pages', { headers: new Headers({ 'X-WP-Nonce': STALE.wp_rest }) });
    assert.equal(calls.filter((call) => call.input === ENDPOINT).length, 1);
    assert.equal(window.staticBridgeWooCommerce.rewritten, 3);
    assert.ok(calls.slice(1).every((call) => !JSON.stringify(call.init.headers).includes(STALE.wc_store_api) && !JSON.stringify(call.init.headers).includes(STALE.wp_rest)));
});

test('a failed or malformed refresh leaves the request unchanged', async () => {
    for (const answer of [() => ({ ok: false, json: () => Promise.resolve(null) }), () => ({ ok: true, json: () => Promise.resolve({ success: true, data: { store_api: '<script>', rest: 'x' } }) }), () => { throw new Error('network'); }]) {
        const { window, calls } = setup({ answer });
        await window.fetch(ORIGIN + '/wp-json/wc/store/v1/cart/add-item', { method: 'POST', headers: { Nonce: STALE.wc_store_api } });
        const sent = calls[calls.length - 1];
        assert.equal(sent.init.headers.Nonce, STALE.wc_store_api);
    }
});

test('without valid stale values in the page the script does nothing', () => {
    const { window } = setup({ stale: { wc_store_api: 'not-a-nonce' } });
    assert.equal(window.staticBridgeWooCommerce, undefined);
});

(async () => {
    for (const { name, fn } of tests) {
        try {
            await fn();
        } catch (error) {
            console.error('FAIL ' + name + ': ' + error.message);
            process.exit(1);
        }
    }
    console.log(tests.length + ' WooCommerce static nonce refresher tests passed.');
})();
