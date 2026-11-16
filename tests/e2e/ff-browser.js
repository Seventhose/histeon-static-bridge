'use strict';
// Real-browser Fluent Forms submission on the disposable stack (headless Google Chrome via CDP,
// Node 22 built-in WebSocket). Loads the page as an anonymous visitor, focuses the form like a
// person (real focus events), types, clicks submit and waits for Fluent Forms' own success or
// error message. Records every admin-ajax POST (action, body, status, response) and the
// document's response headers. Loopback stack URLs only.
//
// Usage: node ff-browser.js <url> <form-selector> <email> <subject>
// Prints one JSON object.
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const [url, formSelector, email, subject] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:187(21|23)\//.test(url || '') || !formSelector || !email) {
    process.stderr.write('Usage: node ff-browser.js <http://127.0.0.1:18721|18723/...> <form-selector> <email> <subject>\n');
    process.exit(2);
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'sbqa-chrome-'));
    const chrome = spawn('google-chrome', ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', 'about:blank'], { stdio: 'ignore' });
    try {
        let port = '';
        for (let i = 0; i < 100 && !port; i++) {
            await sleep(100);
            try { port = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]; } catch (error) { port = ''; }
        }
        if (!port) throw new Error('Chrome did not open its DevTools port');
        const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
        const ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
        let nextId = 0;
        const pending = new Map();
        const listeners = [];
        ws.onmessage = (message) => {
            const data = JSON.parse(message.data);
            if (data.id && pending.has(data.id)) {
                const { resolve, reject } = pending.get(data.id);
                pending.delete(data.id);
                data.error ? reject(new Error(JSON.stringify(data.error))) : resolve(data.result);
            } else if (data.method) {
                listeners.forEach((listener) => listener(data));
            }
        };
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++nextId;
            pending.set(id, { resolve, reject });
            ws.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async (expression) => {
            const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };

        const requests = new Map();
        const ajax = [];
        const consoleErrors = [];
        let documentHeaders = null;
        let documentStatus = 0;
        listeners.push((event) => {
            if (event.method === 'Network.requestWillBeSent') {
                const request = event.params.request;
                requests.set(event.params.requestId, request);
            } else if (event.method === 'Network.responseReceived') {
                const request = requests.get(event.params.requestId);
                if (event.params.type === 'Document' && !documentHeaders) {
                    documentHeaders = event.params.response.headers;
                    documentStatus = event.params.response.status;
                }
                if (request && request.method === 'POST' && request.url.includes('admin-ajax.php')) {
                    ajax.push({ requestId: event.params.requestId, url: request.url, body: request.postData || '', status: event.params.response.status, at: Date.now() });
                }
            } else if (event.method === 'Runtime.consoleAPICalled' && event.params.type === 'error') {
                consoleErrors.push(event.params.args.map((arg) => arg.value || arg.description || '').join(' '));
            }
        });
        await send('Network.enable');
        await send('Network.setCacheDisabled', { cacheDisabled: true });
        await send('Page.enable');
        await send('Runtime.enable');
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });
        const loaded = new Promise((resolve) => listeners.push((event) => event.method === 'Page.loadEventFired' && resolve()));
        await send('Page.navigate', { url });
        await loaded;
        await sleep(400);

        const pageState = await evaluate(`(() => {
            const form = document.querySelector(${JSON.stringify(formSelector)});
            const refresher = document.getElementById('static-bridge-fluent-forms');
            const nonceField = form && form.querySelector('input[name$="_fluentformnonce"]');
            return {
                formFound: !!form,
                refresherScript: !!refresher,
                refresherState: !!window.staticBridgeFluentForms,
                staticTokenNonce: window.fluentFormVars ? window.fluentFormVars.token_nonce || null : null,
                staticSubmitNonce: nonceField ? nonceField.value : null,
                embedPostId: form && form.querySelector('[name="__fluent_form_embded_post_id"]') ? form.querySelector('[name="__fluent_form_embded_post_id"]').value : null,
                referer: form && form.querySelector('[name="_wp_http_referer"]') ? form.querySelector('[name="_wp_http_referer"]').value : null,
                refresherRequestsBeforeInteraction: window.staticBridgeFluentForms ? window.staticBridgeFluentForms.requests : null,
            };
        })()`);
        if (!pageState.formFound) throw new Error('form not found: ' + formSelector);

        // Focus the email field like a person: a real focus event, then typed text.
        const focusAt = await evaluate(`(() => {
            const field = document.querySelector(${JSON.stringify(formSelector)} + ' input[name="email"]');
            field.scrollIntoView({ block: 'center' });
            const box = field.getBoundingClientRect();
            return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
        })()`);
        await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: focusAt.x, y: focusAt.y, button: 'left', clickCount: 1 });
        await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: focusAt.x, y: focusAt.y, button: 'left', clickCount: 1 });
        await send('Input.insertText', { text: email });
        await evaluate(`(() => {
            const form = document.querySelector(${JSON.stringify(formSelector)});
            const set = (name, value) => { const field = form.querySelector('[name="' + name + '"]'); if (field) { field.value = value; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); } };
            set('names[first_name]', 'QA');
            set('names[last_name]', 'Static Bridge');
            set('subject', ${JSON.stringify(subject)});
            set('message', 'Messaggio sintetico QA (disposable stack, mail sink).');
            return true;
        })()`);
        // Wait until Fluent Forms has its anti-spam token (or gave up), then press submit.
        for (let i = 0; i < 50; i++) {
            const tokenState = await evaluate(`(() => { const f = document.querySelector(${JSON.stringify(formSelector)}); const t = f.querySelector('.fluent-form-token-field'); return { hasField: !!t, value: t ? t.value : '', tokenized: f.classList.contains('ff_tokenized'), tokenizing: f.classList.contains('ff_tokenizing') }; })()`);
            if (!tokenState.hasField || tokenState.tokenized || (!tokenState.tokenizing && i > 10)) break;
            await sleep(200);
        }
        const submitAt = await evaluate(`(() => {
            const button = document.querySelector(${JSON.stringify(formSelector)} + ' button[type="submit"]');
            button.scrollIntoView({ block: 'center' });
            const box = button.getBoundingClientRect();
            return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
        })()`);
        await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: submitAt.x, y: submitAt.y, button: 'left', clickCount: 1 });
        await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: submitAt.x, y: submitAt.y, button: 'left', clickCount: 1 });

        let outcome = null;
        for (let i = 0; i < 75 && !outcome; i++) {
            await sleep(200);
            outcome = await evaluate(`(() => {
                const form = document.querySelector(${JSON.stringify(formSelector)});
                const success = (form && document.getElementById(form.id + '_success')) || null;
                const errors = Array.from(document.querySelectorAll(${JSON.stringify(formSelector)} + ' .error, ' + ${JSON.stringify(formSelector)} + ' .ff-el-is-error .text-danger, .ff-errors-in-stack .error')).map((node) => node.textContent.trim()).filter(Boolean);
                if (success) return { success: true, message: success.textContent.trim() };
                if (errors.length) return { success: false, message: errors.join(' | ') };
                return null;
            })()`);
        }
        for (const entry of ajax) {
            try {
                const body = await send('Network.getResponseBody', { requestId: entry.requestId });
                entry.response = (body.body || '').slice(0, 400);
            } catch (error) {
                entry.response = null;
            }
            const params = new URLSearchParams(entry.body);
            entry.action = params.get('action');
            delete entry.requestId;
        }
        const finalState = await evaluate(`({ refresherRequests: window.staticBridgeFluentForms ? window.staticBridgeFluentForms.requests : null, tokenNonceNow: window.fluentFormVars ? window.fluentFormVars.token_nonce || null : null })`);
        ws.close();
        return { url, documentStatus, documentHeaders, page: pageState, outcome: outcome || { success: false, message: 'timeout: no Fluent Forms message' }, ajax, final: finalState, consoleErrors };
    } finally {
        chrome.kill('SIGKILL');
        await sleep(300);
        fs.rmSync(profile, { recursive: true, force: true });
    }
}

main().then((result) => {
    process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}).catch((error) => {
    process.stdout.write(JSON.stringify({ url, error: String(error && error.stack || error) }) + '\n');
    process.exitCode = 1;
});
