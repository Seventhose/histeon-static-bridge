'use strict';

// Unit tests for plugin/assets/fluent-forms-static.js with a minimal jQuery
// stand-in that follows jQuery's prefilter/transport contract. The real
// Fluent Forms + jQuery + browser path is covered by the E2E runner.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../plugin/assets/fluent-forms-static.js'), 'utf8');
const ENDPOINT = '/wp-admin/admin-ajax.php';

function setup(options = {}) {
    const sent = [];
    const listeners = {};
    const fields = [
        { name: '_fluentform_3_fluentformnonce', value: 'stale00000', closest: () => null },
        { name: '_fluentform_8_fluentformnonce', value: 'stale00000', closest: () => null },
    ];
    const formElement = { closest: (selector) => (selector === 'form.frm-fluent-form' ? {} : null) };

    class FakeXHR {
        constructor() {
            this.headers = {};
            this.status = 0;
            this.responseText = '';
        }
        open(method, url) {
            this.method = method;
            this.url = url;
        }
        setRequestHeader(name, value) {
            this.headers[name] = value;
        }
        overrideMimeType() {}
        getAllResponseHeaders() {
            return 'content-type: application/json; charset=UTF-8\r\n';
        }
        send(body) {
            this.body = body;
            sent.push(this);
        }
        respond(status, data) {
            this.status = status;
            this.responseText = typeof data === 'string' ? data : JSON.stringify(data);
            this.onload();
        }
    }

    const prefilters = [];
    const transports = [];
    const jQuery = {
        ajaxPrefilter(callback) {
            prefilters.push(callback);
        },
        ajaxTransport(dataType, factory) {
            transports.push(factory);
        },
        // Minimal jQuery.ajax: data already urlencoded, prefilters, then the first transport that answers.
        post(url, data) {
            const settings = { type: 'POST', url, data, hasContent: true, async: true, xhr: () => new FakeXHR() };
            prefilters.forEach((prefilter) => prefilter(settings));
            const transport = transports.map((factory) => factory(settings)).find(Boolean);
            return new Promise((resolve) => {
                if (transport) {
                    transport.send({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, (status, statusText, responses) => resolve({ status, body: settings.data, deferred: true, responses }));
                    return;
                }
                const xhr = new FakeXHR();
                xhr.open('POST', url);
                xhr.send(settings.data);
                resolve({ status: 'sent', body: settings.data, deferred: false, xhr });
            });
        },
    };

    const window = {
        fluentFormVars: { ajaxUrl: ENDPOINT, token_nonce: 'stale11111', file_delete_nonce: 'stale22222', nonce: 'stale33333' },
        jQuery: options.noJQuery ? undefined : jQuery,
        addEventListener() {},
    };
    const document = {
        currentScript: { getAttribute: (name) => (name === 'data-endpoint' ? ENDPOINT : null) },
        querySelectorAll: () => fields,
        addEventListener(type, callback) {
            listeners[type] = callback;
        },
    };
    const context = { window, document, XMLHttpRequest: FakeXHR, Promise, Date, Math, JSON, RegExp, encodeURIComponent, String };
    vm.runInNewContext(source, context);
    return { window, document, jQuery, sent, listeners, fields, formElement, state: window.staticBridgeFluentForms };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
const freshValues = { success: true, data: { token_nonce: 'abcdef0123', submit_nonce: '0123abcdef', file_delete_nonce: 'fedcba9876', vars_nonce: '9876fedcba', ttl: 43200 } };

async function testTokenRequestWaitsForFreshNonce() {
    const t = setup();
    // First focus inside a form: the capture listener starts the refresh ...
    t.listeners.focusin({ target: t.formElement });
    assert.equal(t.sent.length, 1, 'focus inside a Fluent Forms form must start one refresh request');
    assert.equal(t.sent[0].url, ENDPOINT);
    assert.equal(t.sent[0].body, 'action=static_bridge_public_form_nonces');
    // ... and Fluent Forms asks for its token on the same focus, before the answer.
    const tokenRequest = t.jQuery.post(`${ENDPOINT}?t=1`, 'action=fluentform_generate_protection_token&form_id=3&nonce=stale11111');
    await flush();
    assert.equal(t.sent.length, 1, 'the token request left before current nonces were known');
    t.sent[0].respond(200, freshValues);
    await flush();
    assert.equal(t.sent.length, 2, 'the held token request was not sent after the refresh');
    assert.equal(t.sent[1].body, 'action=fluentform_generate_protection_token&form_id=3&nonce=abcdef0123');
    t.sent[1].respond(200, { success: true, data: { token: 'TOKEN' } });
    const result = await tokenRequest;
    assert.equal(result.status, 200);
    assert.equal(result.responses.text, '{"success":true,"data":{"token":"TOKEN"}}');
    assert.equal(t.window.fluentFormVars.token_nonce, 'abcdef0123', 'fluentFormVars.token_nonce was not refreshed');
    assert.equal(t.window.fluentFormVars.file_delete_nonce, 'fedcba9876');
    assert.deepEqual(t.fields.map((field) => field.value), ['0123abcdef', '0123abcdef'], 'submit nonce fields were not refreshed');
}

async function testSubmitIsRewrittenSynchronouslyOnceFresh() {
    const t = setup();
    t.state.refresh();
    t.sent[0].respond(200, freshValues);
    await flush();
    const inner = 'email=a%40b.it&__fluent_form_embded_post_id=41&_fluentform_3_fluentformnonce=stale00000&_wp_http_referer=%2Fchi-siamo%2F';
    const outer = `data=${encodeURIComponent(inner)}&action=fluentform_submit&form_id=3`;
    const result = await t.jQuery.post(ENDPOINT, outer);
    assert.equal(result.deferred, false, 'a request with fresh nonces must use the normal transport');
    const params = new URLSearchParams(result.body);
    const fields = new URLSearchParams(params.get('data'));
    assert.equal(fields.get('_fluentform_3_fluentformnonce'), '0123abcdef');
    assert.equal(fields.get('__fluent_form_embded_post_id'), '41', 'per-page fields must stay as rendered');
    assert.equal(fields.get('_wp_http_referer'), '/chi-siamo/');
    assert.equal(fields.get('email'), 'a@b.it');
    // Unrelated admin-ajax actions are never touched.
    const other = await t.jQuery.post(ENDPOINT, 'action=other_plugin&nonce=keepme');
    assert.equal(other.body, 'action=other_plugin&nonce=keepme');
}

async function testFailedRefreshLeavesRequestsUnchanged() {
    const t = setup();
    const tokenRequest = t.jQuery.post(ENDPOINT, 'action=fluentform_generate_protection_token&form_id=3&nonce=stale11111');
    await flush();
    t.sent[0].respond(404, { success: false, data: null });
    await flush();
    assert.equal(t.sent[1].body, 'action=fluentform_generate_protection_token&form_id=3&nonce=stale11111');
    t.sent[1].respond(200, { success: false });
    await tokenRequest;
    assert.equal(t.window.fluentFormVars.token_nonce, 'stale11111');
}

async function testOneRefreshServesConcurrentForms() {
    const t = setup();
    const first = t.jQuery.post(ENDPOINT, 'action=fluentform_generate_protection_token&form_id=3&nonce=x');
    const second = t.jQuery.post(ENDPOINT, 'action=fluentform_generate_protection_token&form_id=8&nonce=x');
    await flush();
    assert.equal(t.state.requests, 1, 'two forms must share one refresh request');
    t.sent[0].respond(200, freshValues);
    await flush();
    t.sent[1].respond(200, {});
    t.sent[2].respond(200, {});
    await Promise.all([first, second]);
    assert.match(t.sent[1].body, /form_id=3&nonce=abcdef0123$/);
    assert.match(t.sent[2].body, /form_id=8&nonce=abcdef0123$/);
}

// 2.4.1: fluentFormVars.nonce (no action, -1) is renewed too, and the step-form progress requests carry it.
async function testStepFormNonceIsRenewed() {
    const t = setup();
    const save = t.jQuery.post(ENDPOINT, 'step=1&data=x&form_id=3&action=fluentform_step_form_save_data&nonce=stale33333');
    await flush();
    assert.equal(t.sent.length, 1, 'the step-form request left before current nonces were known');
    t.sent[0].respond(200, freshValues);
    await flush();
    assert.equal(t.sent[1].body, 'step=1&data=x&form_id=3&action=fluentform_step_form_save_data&nonce=9876fedcba');
    t.sent[1].respond(200, {});
    await save;
    assert.equal(t.window.fluentFormVars.nonce, '9876fedcba', 'fluentFormVars.nonce was not refreshed');
    const load = await t.jQuery.post(ENDPOINT, 'form_id=3&action=fluentform_step_form_get_data&nonce=stale33333&hash=h');
    assert.equal(load.body, 'form_id=3&action=fluentform_step_form_get_data&nonce=9876fedcba&hash=h');
}

async function testNoInteractionNoRequest() {
    const t = setup();
    t.listeners.focusin({ target: { closest: () => null } });
    assert.equal(t.sent.length, 0, 'the refresher must not call WordPress on pages where no form is used');
}

(async () => {
    await testTokenRequestWaitsForFreshNonce();
    await testSubmitIsRewrittenSynchronouslyOnceFresh();
    await testFailedRefreshLeavesRequestsUnchanged();
    await testOneRefreshServesConcurrentForms();
    await testStepFormNonceIsRenewed();
    await testNoInteractionNoRequest();
    process.stdout.write('6 Fluent Forms static nonce refresher tests passed.\n');
})().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exitCode = 1;
});
