'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../plugin/assets/admin.js'), 'utf8');
const postPushScript = fs.readFileSync(path.join(__dirname, '../plugin/assets/post-push.js'), 'utf8');

class FakeElement {
    constructor(id) {
        this.id = id;
        this.hidden = false;
        this.disabled = false;
        this.textContent = '';
        this.style = {};
        this.attributes = {};
        this.children = [];
        this.listeners = {};
        this.classList = { toggle() {} };
    }

    addEventListener(type, callback) {
        this.listeners[type] = callback;
    }

    querySelector(selector) {
        if (selector === '.sb-button-label') return this.label;
        if (selector === '.sb-diagnostic-button-label') return this.label;
        if (selector === '[role="progressbar"]') return this.track;
        return null;
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    replaceChildren(...children) {
        this.children = children;
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    append(...children) {
        this.children.push(...children);
    }

    click() {
        return this.listeners.click();
    }

    get childElementCount() {
        return this.children.length;
    }
}

function runningJob(overrides = {}) {
    return {
        status: 'running',
        processed: 1,
        total: 10,
        saved: 1,
        pending: 9,
        skipped: 0,
        progress: 10,
        currentUrl: 'https://test.local/example/',
        discoveryComplete: false,
        retryCount: 0,
        issues: [],
        ...overrides,
    };
}

async function flushPromises() {
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setImmediate(resolve));
}

function createPage(responses) {
    const elements = Object.fromEntries([
        'sb-generate', 'sb-progress-panel', 'sb-cancel', 'sb-progress-title',
        'sb-progress-count', 'sb-progress-bar', 'sb-progress-current',
        'sb-progress-message', 'sb-issues-card', 'sb-issues-list',
    ].map((id) => [id, new FakeElement(id)]));
    const track = new FakeElement('progress-track');
    elements['sb-progress-panel'].track = track;
    elements['sb-generate'].label = new FakeElement('button-label');

    const timers = [];
    const actions = [];
    const document = {
        querySelector() { return null; },
        querySelectorAll() { return []; },
        getElementById(id) {
            return elements[id] || null;
        },
        createElement(tag) {
            return new FakeElement(tag);
        },
        createTextNode(text) {
            const node = new FakeElement('#text');
            node.textContent = text;
            return node;
        },
    };
    const window = {
        StaticBridgeAdmin: {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            nonce: 'test-nonce',
            isRunning: 0,
            isResumable: 0,
            isDiagnosticRunning: 0,
            retryLimit: 2,
            strings: {
                starting: 'starting',
                running: 'running',
                resume: 'resume',
                start: 'start',
                retrying: 'retrying',
                paused: 'paused',
                pausedMessage: 'legacy queue resumes by skipping its failed URL',
                complete: 'complete',
                completeWithErrors: 'complete with %d URL errors',
                failed: 'failed',
                connectionLost: 'unknown-state',
                connectionRetrying: 'rechecking',
                connectionLostMessage: 'server state unknown; resume to recheck',
                workerBusy: 'worker busy; checking again shortly',
                cancelled: 'cancelled',
                cancelledMessage: 'partial stage retained',
                requestError: 'request failed',
                scanning: 'scanning',
            },
        },
        setTimeout(callback, delay) {
            timers.push({ callback, delay });
            return timers.length;
        },
        clearTimeout(id) {
            const index = id - 1;
            if (timers[index]) timers[index].callback = null;
        },
        location: { reload() {} },
    };
    const fetch = async (_url, options) => {
        const action = new URLSearchParams(options.body).get('action');
        actions.push(action);
        const next = responses.shift();
        assert.ok(next, `Unexpected request: ${action}`);
        assert.equal(next.action, action);
        if (next.error) throw next.error;
        return {
            ok: true,
            json: async () => ({ success: true, data: next.data }),
        };
    };

    vm.runInNewContext(script, { window, document, fetch, URLSearchParams });
    return { elements, window, timers, actions };
}

function createDiagnosticPage(response) {
    const ids = [
        'sb-run-diagnostics', 'sb-diagnostic-progress', 'sb-diagnostic-results',
        'sb-diagnostic-target-url', 'sb-diagnostic-title', 'sb-diagnostic-count',
        'sb-diagnostic-bar', 'sb-diagnostic-current',
    ];
    const elements = Object.fromEntries(ids.map((id) => [id, new FakeElement(id)]));
    const track = new FakeElement('diagnostic-progress-track');
    elements['sb-diagnostic-progress'].track = track;
    elements['sb-run-diagnostics'].label = new FakeElement('diagnostic-button-label');
    elements['sb-diagnostic-target-url'].value = 'https://staging.example/una-pagina/';
    elements['sb-diagnostic-target-url'].disabled = false;
    const sentBodies = [];
    const document = {
        querySelector() { return null; },
        querySelectorAll() { return []; },
        getElementById(id) { return elements[id] || null; },
        createElement(tag) { return new FakeElement(tag); },
        createDocumentFragment() { return new FakeElement('fragment'); },
        createTextNode(text) { const node = new FakeElement('#text'); node.textContent = text; return node; },
    };
    const window = {
        StaticBridgeAdmin: {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            nonce: 'test-nonce',
            isRunning: 0,
            isResumable: 0,
            isDiagnosticRunning: 0,
            urls: {},
            strings: {
                diagnosticRunning: 'running',
                diagnosticPassed: 'passed',
                diagnosticFailed: 'failed',
                diagnosticWarning: 'warning',
                diagnosticStarting: 'starting',
                diagnosticStart: 'start',
                diagnosticResume: 'resume',
                failed: 'request failed',
                requestError: 'request failed',
            },
        },
        setTimeout() { return 1; },
        clearTimeout() {},
    };
    const fetch = async (_url, options) => {
        sentBodies.push(new URLSearchParams(options.body));
        return { ok: true, json: async () => ({ success: true, data: response }) };
    };
    vm.runInNewContext(script, { window, document, fetch, URLSearchParams });
    return { elements, sentBodies };
}

async function testTransientTickFailureKeepsRunningAndRetries() {
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
        { action: 'static_bridge_tick', data: runningJob({ processed: 2, saved: 2, pending: 8, progress: 20 }) },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    assert.equal(page.elements['sb-progress-message'].textContent, 'rechecking');
    assert.equal(page.timers.length, 1);
    assert.equal(page.timers[0].delay, 1000);

    const retry = page.timers.shift();
    await retry.callback();
    await flushPromises();

    assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    assert.match(page.elements['sb-progress-message'].textContent, /2 pagine salvate/);
    assert.ok(page.actions.includes('static_bridge_status'));
}

async function testTransportFailuresDoNotStopAutomaticRecoveryAfterThreeAttempts() {
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
        { action: 'static_bridge_tick', data: runningJob({ processed: 2, saved: 2, pending: 8, progress: 20 }) },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    for (const delay of [1000, 2000, 4000, 8000]) {
        const retry = page.timers.shift();
        assert.ok(retry && typeof retry.callback === 'function', `Recovery polling stopped before the ${delay} ms retry.`);
        assert.equal(retry.delay, delay);
        await retry.callback();
        await flushPromises();
        assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    }

    assert.equal(page.elements['sb-progress-message'].textContent, '2 pagine salvate · 8 in coda');
    assert.equal(page.timers.length, 1);
    assert.equal(page.timers[0].delay, 350);
}

async function testUnknownServerStatusKeepsRetryingAndLeavesCancelAvailable() {
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();
    assert.equal(page.timers.length, 1);
    assert.equal(page.elements['sb-cancel'].hidden, false);

    const retry = page.timers.shift();
    assert.equal(retry.delay, 1000);
    await retry.callback();
    await flushPromises();

    assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    assert.equal(page.elements['sb-progress-message'].textContent, 'rechecking');
    assert.equal(page.timers.length, 1);
    assert.equal(page.elements['sb-cancel'].hidden, false);
}

async function testBusyWorkerDoesNotCauseRapidPolling() {
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob() },
        { action: 'static_bridge_tick', data: runningJob({ workerBusy: true }) },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.equal(page.elements['sb-progress-message'].textContent, 'worker busy; checking again shortly');
    assert.equal(page.timers.length, 1);
    assert.equal(page.timers[0].delay, 5000);
}

async function testServerConfirmedFailureIsShownAsTerminal() {
    const failed = {
        status: 'failed',
        processed: 1,
        total: 10,
        saved: 0,
        pending: 9,
        skipped: 0,
        progress: 10,
        issues: [{ url: 'https://test.local/example/', message: 'HTTP 500' }],
    };
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob() },
        { action: 'static_bridge_tick', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: failed },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.equal(page.elements['sb-progress-title'].textContent, 'failed');
    assert.equal(page.elements['sb-progress-message'].textContent, 'HTTP 500');
    assert.equal(page.elements['sb-generate'].disabled, false);
    assert.equal(page.timers.length, 0);
}

async function testLostStartResponseFindsServerJobWithoutStartingAnother() {
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', error: new TypeError('Failed to fetch') },
        { action: 'static_bridge_status', data: runningJob() },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.deepEqual(page.actions, [
        'static_bridge_status',
        'static_bridge_start',
        'static_bridge_status',
    ]);
    assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    assert.equal(page.elements['sb-progress-message'].textContent, 'rechecking');
    assert.equal(page.timers.length, 1);
}

async function testCompletedExportStartsANewRelease() {
    const completed = runningJob({
        status: 'complete',
        processed: 3,
        total: 3,
        saved: 3,
        pending: 0,
        progress: 100,
        discoveryComplete: true,
    });
    const page = createPage([
        { action: 'static_bridge_status', data: completed },
        { action: 'static_bridge_start', data: runningJob({ total: 3, pending: 3 }) },
        { action: 'static_bridge_tick', data: completed },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.deepEqual(page.actions, [
        'static_bridge_status',
        'static_bridge_start',
        'static_bridge_tick',
    ]);
    assert.equal(page.elements['sb-progress-title'].textContent, 'complete');
    assert.equal(page.elements['sb-generate'].disabled, false);
    assert.equal(page.elements['sb-progress-panel'].hidden, false);
}

async function testCompletedExportReportsRouteErrorsAndRetainedPages() {
    const completed = runningJob({
        status: 'complete',
        processed: 10,
        total: 10,
        saved: 10,
        skipped: 1,
        preserved: 1,
        routeErrors: 1,
        pending: 0,
        progress: 100,
        discoveryComplete: true,
        issues: [{ url: 'https://test.local/stale-route/', message: 'previous copy retained' }],
    });
    const page = createPage([
        { action: 'static_bridge_status', data: { status: 'idle' } },
        { action: 'static_bridge_start', data: runningJob({ total: 10, pending: 10 }) },
        { action: 'static_bridge_tick', data: completed },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.equal(page.elements['sb-progress-title'].textContent, 'complete with 1 URL errors');
    // Singular for one (it used to read «1 copie precedenti mantenute»).
    assert.match(page.elements['sb-progress-message'].textContent, /1 copia precedente mantenuta · 1 URL non aggiornata/);
    assert.equal(page.elements['sb-issues-card'].hidden, false);
    assert.equal(page.elements['sb-issues-list'].childElementCount, 1);
}

async function testPausedExportUsesResumeEndpointAndKeepsCancelAvailable() {
    const paused = {
        status: 'paused',
        processed: 8,
        total: 10,
        saved: 8,
        pending: 2,
        skipped: 0,
        progress: 80,
        currentUrl: 'https://test.local/article-with-timeout/',
        retryCount: 2,
        issues: [{ url: 'https://test.local/article-with-timeout/', message: 'cURL timeout' }],
    };
    const page = createPage([
        { action: 'static_bridge_status', data: paused },
        { action: 'static_bridge_resume', data: runningJob({ processed: 8, total: 10, saved: 8, pending: 2, progress: 80 }) },
        { action: 'static_bridge_tick', data: runningJob({ processed: 9, total: 10, saved: 9, pending: 1, progress: 90 }) },
    ]);

    await page.elements['sb-generate'].click();
    await flushPromises();

    assert.ok(page.actions.includes('static_bridge_resume'));
    assert.equal(page.elements['sb-progress-title'].textContent, 'running');
    assert.match(page.elements['sb-progress-message'].textContent, /9 pagine salvate/);
    assert.equal(page.elements['sb-cancel'].hidden, false);
}

async function testPausedExportCanBeCancelledWithoutStartingAReplacement() {
    const page = createPage([
        { action: 'static_bridge_cancel', data: { status: 'cancelled', issues: [], saved: 5, pending: 2 } },
    ]);
    page.elements['sb-cancel'].click();
    await flushPromises();

    assert.deepEqual(page.actions, ['static_bridge_cancel']);
    assert.equal(page.elements['sb-progress-title'].textContent, 'cancelled');
    assert.equal(page.elements['sb-generate'].label.textContent, 'start');
}

function createPostPushPage(response) {
    const paragraph = { textContent: '' };
    const classes = new Set(['notice', 'notice-info']);
    const notice = {
        dataset: { state: 'queued' },
        classList: {
            remove(...names) { names.forEach((name) => classes.delete(name)); },
            add(name) { classes.add(name); },
        },
        querySelector(selector) { return selector === 'p' ? paragraph : null; },
    };
    const timers = [];
    const listeners = {};
    const document = {
        visibilityState: 'visible',
        getElementById(id) { return id === 'static-bridge-post-push-status' ? notice : null; },
        addEventListener(type, callback) { listeners[type] = callback; },
    };
    const window = {
        StaticBridgePostPush: {
            statusUrl: '/wp-admin/admin-post.php',
            nonce: 'test-nonce',
            token: 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
            strings: {
                queued: 'queued; old static copy remains online',
                retrying: 'retrying',
                completed: 'static copy updated successfully',
                failed: 'push not completed: ',
                statusError: 'cannot verify; no success confirmed',
            },
        },
        setTimeout(callback, delay) { timers.push({ callback, delay }); return timers.length; },
        clearTimeout(id) { if (timers[id - 1]) timers[id - 1].callback = null; },
    };
    const fetch = async (_url, options) => {
        assert.equal(new URLSearchParams(options.body).get('action'), 'static_bridge_post_push_status');
        assert.equal(new URLSearchParams(options.body).get('_wpnonce'), 'test-nonce');
        if (response.error) throw response.error;
        return {
            ok: response.ok !== false,
            json: async () => response.body,
        };
    };
    vm.runInNewContext(postPushScript, { window, document, fetch, URLSearchParams });
    return { notice, paragraph, classes, timers, listeners };
}

async function testSinglePostPushShowsNoSuccessWhileQueued() {
    const page = createPostPushPage({ body: { success: true, data: { status: 'queued', lastError: '' } } });
    await flushPromises();

    assert.equal(page.notice.dataset.state, 'queued');
    assert.ok(page.classes.has('notice-info'));
    assert.match(page.paragraph.textContent, /queued/);
    assert.doesNotMatch(page.paragraph.textContent, /successfully/);
    assert.equal(page.timers.length, 1);
}

async function testSinglePostPushShowsSuccessOnlyAfterServerConfirmsCompletion() {
    const page = createPostPushPage({ body: { success: true, data: { status: 'completed', lastError: '' } } });
    await flushPromises();

    assert.equal(page.notice.dataset.state, 'completed');
    assert.ok(page.classes.has('notice-success'));
    assert.equal(page.paragraph.textContent, 'static copy updated successfully');
    assert.equal(page.timers.length, 0);
}

async function testSinglePostPushDoesNotInventSuccessWhenStatusCannotBeVerified() {
    const page = createPostPushPage({ error: new TypeError('Failed to fetch') });
    await flushPromises();

    assert.equal(page.notice.dataset.state, 'queued');
    assert.ok(page.classes.has('notice-info'));
    assert.match(page.paragraph.textContent, /no success confirmed/);
    assert.equal(page.timers.length, 1);
}

async function testSinglePostPushShowsFailureInsteadOfSuccess() {
    const page = createPostPushPage({ body: { success: true, data: { status: 'failed', lastError: 'HTTP 404' } } });
    await flushPromises();

    assert.equal(page.notice.dataset.state, 'failed');
    assert.ok(page.classes.has('notice-error'));
    assert.match(page.paragraph.textContent, /HTTP 404/);
    assert.equal(page.timers.length, 0);
}

async function testTargetedDiagnosticSendsTheSelectedSameSiteRoute() {
    const page = createDiagnosticPage({
        status: 'passed',
        progress: 100,
        completed: 1,
        total: 1,
        current: 'done',
        results: [],
        summary: { label: 'done' },
    });
    await page.elements['sb-run-diagnostics'].click();
    await flushPromises();

    assert.equal(page.sentBodies.length, 1);
    assert.equal(page.sentBodies[0].get('action'), 'static_bridge_diagnostic_start');
    assert.equal(page.sentBodies[0].get('nonce'), 'test-nonce');
    assert.equal(page.sentBodies[0].get('target_url'), 'https://staging.example/una-pagina/');
    assert.equal(page.elements['sb-diagnostic-target-url'].disabled, false);
}

async function testDiagnosticResultsShowProblemsFirst() {
    const page = createDiagnosticPage({
        status: 'passed',
        progress: 100,
        completed: 6,
        total: 6,
        current: 'done',
        results: [
            { label: 'ok-1', status: 'pass' },
            { label: 'avviso-1', status: 'warning' },
            { label: 'saltato', status: 'skip' },
            { label: 'errore', status: 'fail' },
            { label: 'sconosciuto', status: 'nuovo' },
            { label: 'ok-2', status: 'pass' },
        ],
        summary: { label: 'done' },
    });
    await page.elements['sb-run-diagnostics'].click();
    await flushPromises();

    const fragment = page.elements['sb-diagnostic-results'].children[0];
    const labels = fragment.children.map((article) => article.children[1].children[0].children[0].textContent);
    assert.deepEqual(labels, ['errore', 'avviso-1', 'sconosciuto', 'ok-1', 'saltato', 'ok-2']);
}

function createServerPage(steps, autoRun) {
    const ids = ['sb-run-server-check', 'sb-server-check-results', 'sb-server-check-note'];
    const elements = Object.fromEntries(ids.map((id) => [id, new FakeElement(id)]));
    elements['sb-run-server-check'].label = new FakeElement('server-button-label');
    elements['sb-run-server-check'].querySelector = function (selector) {
        return selector === '.sb-server-check-button-label' ? this.label : null;
    };
    const sentBodies = [];
    const snapshots = [];
    const document = {
        querySelector() { return null; },
        querySelectorAll() { return []; },
        getElementById(id) { return elements[id] || null; },
        createElement(tag) { return new FakeElement(tag); },
        createDocumentFragment() { return new FakeElement('fragment'); },
        createTextNode(text) { const node = new FakeElement('#text'); node.textContent = text; return node; },
    };
    const window = {
        StaticBridgeAdmin: {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            nonce: 'test-nonce',
            isRunning: 0,
            isResumable: 0,
            isDiagnosticRunning: 0,
            serverCheckAutoRun: autoRun ? 1 : 0,
            urls: {},
            strings: { serverCheckRunning: 'checking', serverCheckStart: 'check again', requestError: 'request failed' },
        },
        setTimeout() { return 1; },
        clearTimeout() {},
        location: { reload() {} },
    };
    const fetch = async (_url, options) => {
        const body = new URLSearchParams(options.body);
        sentBodies.push(body);
        // What the list showed while this request was in flight.
        snapshots.push(elements['sb-server-check-results'].children.map((article) => article.className));
        if (body.get('action') === 'static_bridge_server_check_start') {
            return { ok: true, json: async () => ({ success: true, data: { checks: [{ id: 'server', label: 'Server web' }, { id: 'php', label: 'PHP' }, { id: 'https', label: 'HTTPS' }] } }) };
        }
        const step = steps[body.get('check')];
        if (step instanceof Error) throw step;
        return { ok: true, json: async () => ({ success: true, data: step }) };
    };
    vm.runInNewContext(script, { window, document, fetch, URLSearchParams });
    return { elements, sentBodies, snapshots };
}

async function testServerTabRunsEachCheckInOrderAndKeepsGoingAfterAFailure() {
    const page = createServerPage({
        server: { result: { id: 'server', label: 'Server web', status: 'pass', summary: 'Apache' }, done: false },
        php: new Error('network down'),
        https: { result: { id: 'https', label: 'HTTPS', status: 'tip', summary: 'manca HSTS', remediation: 'chiedila' }, done: true, note: 'Ultimo controllo: ora' },
    }, true);
    for (let i = 0; i < 6; i++) await flushPromises();

    assert.deepEqual(page.sentBodies.map((body) => body.get('action') + ':' + (body.get('check') || '')), [
        'static_bridge_server_check_start:',
        'static_bridge_server_check_step:server',
        'static_bridge_server_check_step:php',
        'static_bridge_server_check_step:https',
    ]);
    assert.ok(page.sentBodies.every((body) => body.get('nonce') === 'test-nonce'));
    // While the second check runs, the first has its result and the third is still waiting in its place.
    assert.deepEqual(page.snapshots[2], ['sb-diagnostic-result is-pass', 'sb-diagnostic-result is-pending', 'sb-diagnostic-result is-pending']);
    const articles = page.elements['sb-server-check-results'].children;
    assert.deepEqual(articles.map((article) => article.className), ['sb-diagnostic-result is-pass', 'sb-diagnostic-result is-skip', 'sb-diagnostic-result is-tip']);
    assert.equal(articles[1].children[1].children[1].textContent, 'network down');
    assert.equal(articles[2].children[1].children[2].textContent, 'chiedila');
    assert.equal(page.elements['sb-server-check-note'].textContent, 'Ultimo controllo: ora');
    assert.equal(page.elements['sb-run-server-check'].disabled, false);
    assert.equal(page.elements['sb-run-server-check'].label.textContent, 'check again');
}

async function testServerTabWaitsForAClickAfterTheFirstCheck() {
    const page = createServerPage({}, false);
    await flushPromises();
    assert.equal(page.sentBodies.length, 0);
}

function testVersionDeletionsAskFirst() {
    // Versioni (2.4.0): every form with data-sb-confirm asks with the message the page wrote; «Annulla» sends nothing.
    const forms = ['Eliminare la versione 20261108T120000Z-abcde? Non si può annullare.', 'Eliminare 3 versioni che non servono? Non si può annullare.'].map((message) => {
        const listeners = {};
        return {
            message,
            listeners,
            getAttribute(name) { return 'data-sb-confirm' === name ? message : null; },
            addEventListener(type, callback) { listeners[type] = callback; },
        };
    });
    const asked = [];
    let answer = false;
    const document = {
        querySelector() { return null; },
        querySelectorAll(selector) { return 'form[data-sb-confirm]' === selector ? forms : []; },
        getElementById() { return null; },
    };
    const window = {
        StaticBridgeAdmin: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test-nonce', isRunning: 0, isResumable: 0, isDiagnosticRunning: 0, retryLimit: 2, strings: {} },
        confirm(message) { asked.push(message); return answer; },
        setTimeout() { return 1; },
        clearTimeout() {},
        location: { hash: '', reload() {} },
    };
    vm.runInNewContext(script, { window, document, fetch: async () => { throw new Error('No request expected'); }, URLSearchParams });
    for (const form of forms) {
        assert.equal(typeof form.listeners.submit, 'function', 'A deletion form has no confirmation.');
        let prevented = false;
        answer = false;
        form.listeners.submit({ preventDefault() { prevented = true; } });
        assert.ok(prevented, 'Cancelling the confirmation still submitted the deletion.');
        prevented = false;
        answer = true;
        form.listeners.submit({ preventDefault() { prevented = true; } });
        assert.ok(!prevented, 'Confirming did not submit the deletion.');
    }
    assert.deepEqual(asked, [forms[0].message, forms[0].message, forms[1].message, forms[1].message]);
}

function testAdminMarkupLeavesThePageStructureToWordPress() {
    const php = fs.readFileSync(path.join(__dirname, '../plugin/static-bridge.php'), 'utf8');
    const start = php.indexOf('public function render_admin_page()');
    const page = php.slice(start, php.indexOf('public function save_settings()', start));

    // #wpbody-content is already the page's main landmark: no second main, banner or contentinfo inside it.
    assert.equal(/<(main|header|footer)\b/.test(page), false);
    // Without this marker WordPress moves every notice right after the h1, inside the page heading.
    assert.ok(page.includes('<hr class="wp-header-end">'));
    // Genera, Moduli, Impostazioni, Server, Versioni, Diagnostica, Attività.
    assert.equal((page.match(/aria-current="page"'/g) || []).length, 7);
    // The Versioni blocks (2.4.0) add no landmark either.
    const versions = php.slice(php.indexOf('private function render_versions_tab('), php.indexOf('public function handle_delete_release()'));
    assert.ok(versions.includes('sb-versions-list'));
    assert.equal(/<(main|header|footer)\b/.test(versions), false);
    assert.equal(/class="dashicons dashicons-[a-z-]+"><\/span>/.test(page), false);
}

(async () => {
    await testTransientTickFailureKeepsRunningAndRetries();
    await testTransportFailuresDoNotStopAutomaticRecoveryAfterThreeAttempts();
    await testUnknownServerStatusKeepsRetryingAndLeavesCancelAvailable();
    await testBusyWorkerDoesNotCauseRapidPolling();
    await testServerConfirmedFailureIsShownAsTerminal();
    await testLostStartResponseFindsServerJobWithoutStartingAnother();
    await testCompletedExportStartsANewRelease();
    await testCompletedExportReportsRouteErrorsAndRetainedPages();
    await testPausedExportUsesResumeEndpointAndKeepsCancelAvailable();
    await testPausedExportCanBeCancelledWithoutStartingAReplacement();
    await testSinglePostPushShowsNoSuccessWhileQueued();
    await testSinglePostPushShowsSuccessOnlyAfterServerConfirmsCompletion();
    await testSinglePostPushDoesNotInventSuccessWhenStatusCannotBeVerified();
    await testSinglePostPushShowsFailureInsteadOfSuccess();
    await testTargetedDiagnosticSendsTheSelectedSameSiteRoute();
    await testDiagnosticResultsShowProblemsFirst();
    await testServerTabRunsEachCheckInOrderAndKeepsGoingAfterAFailure();
    await testServerTabWaitsForAClickAfterTheFirstCheck();
    testVersionDeletionsAskFirst();
    testAdminMarkupLeavesThePageStructureToWordPress();
    process.stdout.write('20 admin UI recovery, export-error, post-push, targeted-diagnostic, server-check and version-deletion tests passed.\n');
})().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exitCode = 1;
});
