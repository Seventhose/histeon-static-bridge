'use strict';
// Real-browser WooCommerce visits on the disposable wc-* stacks (headless Google Chrome via CDP, Node 22
// built-in WebSocket). Every run is a new anonymous visitor (empty profile). Loopback stack URLs only.
//
// Usage: node wc-browser.js <base> <scenario>
//   blocks    static /catalogo-blocchi/ (All Products block): "Add to cart" through the Store API with the page's
//             nonces, which may have expired: assets/woocommerce-static.js must hand fresh ones to the request.
//   shop      static /shop/ (block theme product button, Interactivity API): "Add to cart" through the Store API.
//   product   static simple product page: quantity 2, "Add to cart" (form POST), then the shop is no longer static.
//   variable  static variable product page: colour Blu, "Add to cart" (form POST).
//   ajax      static /vetrina/: AJAX "add to cart" (?wc-ajax=add_to_cart) and the block mini-cart.
//   checkout  static product page, add to cart, block checkout with "Cash on delivery", order received.
// Prints one JSON object.
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const [base, scenario, email] = process.argv.slice(2);
const SCENARIOS = ['blocks', 'shop', 'product', 'variable', 'ajax', 'checkout'];
if (!/^http:\/\/127\.0\.0\.1:187(41|43)$/.test(base || '') || !SCENARIOS.includes(scenario)) {
    process.stderr.write('Usage: node wc-browser.js <http://127.0.0.1:18741|18743> <' + SCENARIOS.join('|') + '> [email]\n');
    process.exit(2);
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const lower = (headers) => Object.fromEntries(Object.entries(headers || {}).map(([k, v]) => [k.toLowerCase(), v]));
const isStatic = (headers) => 'static' === String(headers['x-static-bridge-route'] || '').toLowerCase() || 'static-bridge' === String(headers['x-sbqa-apache-file'] || '').toLowerCase();

async function main() {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'sbqa-chrome-'));
    const chrome = spawn('google-chrome', ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--window-size=1280,1600',
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
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails).slice(0, 600));
            return result.result.value;
        };

        // Network log: documents, REST/Store API calls, wc-ajax calls, the nonce refresh.
        const requests = new Map();
        const log = [];
        const documents = [];
        const pageErrors = [];
        listeners.push((event) => {
            if (event.method === 'Network.requestWillBeSent') {
                requests.set(event.params.requestId, { request: event.params.request, type: event.params.type });
            } else if (event.method === 'Network.responseReceived') {
                const entry = requests.get(event.params.requestId) || { request: { url: event.params.response.url, method: 'GET', headers: {} } };
                const response = event.params.response;
                if (event.params.type === 'Document') {
                    documents.push({ url: response.url, status: response.status, headers: lower(response.headers), method: entry.request.method });
                }
                const url = entry.request.url;
                if (/\/wp-json\/|rest_route=|wc-ajax=|admin-ajax\.php/.test(url)) {
                    const sent = lower(entry.request.headers);
                    log.push({ requestId: event.params.requestId, method: entry.request.method, url: url.replace(base, ''), status: response.status,
                        nonce: sent.nonce || null, wpNonce: sent['x-wp-nonce'] || null, body: (entry.request.postData || '').slice(0, 1200),
                        responseNonce: lower(response.headers).nonce || null });
                }
            } else if (event.method === 'Runtime.exceptionThrown') {
                pageErrors.push(String(event.params.exceptionDetails.exception && event.params.exceptionDetails.exception.description || event.params.exceptionDetails.text).slice(0, 300));
            }
        });
        await send('Network.enable');
        await send('Network.setCacheDisabled', { cacheDisabled: true });
        await send('Page.enable');
        await send('Runtime.enable');
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });

        const waitFor = async (expression, timeout = 15000) => {
            const until = Date.now() + timeout;
            while (Date.now() < until) {
                const value = await evaluate(expression).catch(() => null);
                if (value) return value;
                await sleep(150);
            }
            return null;
        };
        const navigate = async (url) => {
            const before = documents.length;
            const loaded = new Promise((resolve) => listeners.push((event) => event.method === 'Page.loadEventFired' && resolve()));
            await send('Page.navigate', { url: url.startsWith('http') ? url : base + url });
            await loaded;
            await sleep(500);
            return documents[before] || documents[documents.length - 1] || null;
        };
        const clickAt = async (selector) => {
            const box = await waitFor(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; el.scrollIntoView({ block: 'center' });
                const r = el.getBoundingClientRect(); return r.width && r.height ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null; })()`);
            if (!box) throw new Error('nothing to click: ' + selector);
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });
        };
        // The first element of the selector whose text matches: marks it so that clickAt() finds it.
        const mark = async (selector, pattern) => evaluate(`(() => { const el = Array.from(document.querySelectorAll(${JSON.stringify(selector)}))
            .find((node) => new RegExp(${JSON.stringify(pattern)}, 'i').test(node.textContent)); if (!el) return false; el.setAttribute('data-sbqa-target', '1'); return true; })()`);
        const type = async (selector, text) => {
            await clickAt(selector);
            await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.focus(); el.select && el.select(); return true; })()`);
            await send('Input.insertText', { text });
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        };
        const cookies = async () => (await send('Network.getCookies', { urls: [base + '/'] })).cookies.map((c) => c.name);
        const storeCalls = (pattern) => log.filter((entry) => pattern.test(entry.url));
        const refresher = () => evaluate(`(() => { const s = document.getElementById('static-bridge-woocommerce'); let stale = null; try { stale = s ? JSON.parse(s.getAttribute('data-stale')) : null; } catch (e) {}
            return { script: !!s, stale, state: window.staticBridgeWooCommerce ? { requests: window.staticBridgeWooCommerce.requests, rewritten: window.staticBridgeWooCommerce.rewritten } : null }; })()`);
        const addFormProduct = async (url, prepare) => {
            const first = await navigate(url);
            if (prepare) {
                await prepare();
                // The variation form slides its price and button in: click once it has settled.
                await sleep(1000);
            }
            const before = documents.length;
            const navigated = new Promise((resolve) => listeners.push((event) => event.method === 'Page.loadEventFired' && resolve()));
            await clickAt('form.cart button[type="submit"]');
            await Promise.race([navigated, sleep(15000)]);
            await sleep(500);
            const after = documents.slice(before).pop() || null;
            const notice = await evaluate(`(() => { const n = document.querySelector('.woocommerce-message, .wc-block-components-notice-banner.is-success'); return n ? n.textContent.replace(/\\s+/g, ' ').trim().slice(0, 200) : ''; })()`);
            return { first, after, notice };
        };

        const result = { scenario };
        if ('blocks' === scenario) {
            const doc = await navigate(process.env.SBQA_BLOCKS_PATH || '/catalogo-blocchi/');
            result.document = { status: doc.status, static: isStatic(doc.headers) };
            result.page = await refresher();
            await waitFor(`document.querySelectorAll('.wc-block-all-products .wc-block-components-product-button__button').length > 0`, 20000);
            // The block renders its products twice (first page, then the full query): click once it is stable.
            await sleep(3000);
            await mark('.wc-block-all-products .wc-block-components-product-button__button', '^\\s*add to cart\\s*$');
            const callsBefore = log.length;
            await clickAt('[data-sbqa-target="1"]');
            await waitFor(`(() => /in cart|nel carrello/i.test((document.querySelector('[data-sbqa-target="1"]') || {}).textContent || ''))()`, 15000);
            await sleep(1500);
            const adds = log.slice(callsBefore).filter((entry) => 'POST' === entry.method && /wc\/store\/v1\/(?:batch|cart\/add-item)/.test(entry.url));
            // A batch answers 207: each request inside has its own status.
            for (const entry of adds) {
                try {
                    const answer = JSON.parse((await send('Network.getResponseBody', { requestId: entry.requestId })).body || '{}');
                    entry.inner = (answer.responses || []).map((inner) => inner.status);
                } catch (error) {
                    entry.inner = null;
                }
            }
            result.adds = adds.map(({ method, url, status, nonce, body, inner }) => ({ method, url, status, inner, nonce, bodyNonce: (body.match(/"Nonce":"([a-f0-9]+)"/) || [])[1] || null }));
            result.refresh = log.filter((entry) => entry.body.includes('static_bridge_public_woocommerce_nonces')).map(({ status }) => ({ status }));
            result.after = await refresher();
            result.cookies = await cookies();
            if (process.env.SBQA_DEBUG) result.log = log.map(({ method, url, status, nonce, wpNonce }) => ({ method, url, status, nonce, wpNonce }));
            if (process.env.SBQA_DEBUG) result.buttons = await evaluate(`Array.from(document.querySelectorAll('.wc-block-all-products button, .wc-block-all-products a')).map((b) => b.className + ' | ' + b.textContent.trim()).slice(0, 30)`);
        } else if ('shop' === scenario) {
            const doc = await navigate('/shop/');
            result.document = { status: doc.status, static: isStatic(doc.headers) };
            result.page = await refresher();
            const callsBefore = log.length;
            await clickAt('.wp-block-woocommerce-product-button button[data-wp-on--click], .wp-block-woocommerce-product-button button');
            await waitFor(`(() => Array.from(document.querySelectorAll('.wp-block-woocommerce-product-button')).some((b) => /in cart|nel carrello|added/i.test(b.textContent)))()`, 15000);
            await sleep(1500);
            result.adds = log.slice(callsBefore).filter((entry) => 'POST' === entry.method && /wc\/store\/v1\//.test(entry.url)).map(({ url, status, nonce }) => ({ url, status, nonce }));
            result.buttonText = await evaluate(`Array.from(document.querySelectorAll('.wp-block-woocommerce-product-button')).map((b) => b.textContent.replace(/\\s+/g, ' ').trim()).filter((t) => /cart|carrello/i.test(t)).slice(0, 3)`);
            result.cookies = await cookies();
            const next = await navigate('/product-category/caffe/');
            result.nextDocument = { status: next.status, static: isStatic(next.headers) };
        } else if ('product' === scenario) {
            const added = await addFormProduct('/product/miscela-arabica-qa/', async () => {
                await evaluate(`(() => { const q = document.querySelector('form.cart input.qty'); q.value = '2'; q.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
            });
            result.document = { status: added.first.status, static: isStatic(added.first.headers) };
            result.page = await refresher();
            result.post = added.after ? { method: added.after.method, status: added.after.status, static: isStatic(added.after.headers) } : null;
            result.notice = added.notice;
            result.cookies = await cookies();
            const next = await navigate('/shop/');
            result.nextDocument = { status: next.status, static: isStatic(next.headers) };
            result.miniCount = await evaluate(`(() => { const b = document.querySelector('.wc-block-mini-cart__badge'); return b ? b.textContent.trim() : null; })()`);
        } else if ('variable' === scenario) {
            const added = await addFormProduct('/product/tazza-qa/', async () => {
                await evaluate(`(() => { const s = document.querySelector('form.variations_form select'); s.value = 'Blu'; s.dispatchEvent(new Event('change', { bubbles: true }));
                    if (window.jQuery) window.jQuery(s).trigger('change'); return true; })()`);
                result.variationPrice = await waitFor(`(() => { const p = document.querySelector('.woocommerce-variation-price'); return p && p.textContent.trim() ? p.textContent.replace(/\\s+/g, ' ').trim() : null; })()`, 8000);
                result.variationStock = await evaluate(`(() => { const p = document.querySelector('.woocommerce-variation-availability'); return p ? p.textContent.replace(/\\s+/g, ' ').trim() : null; })()`);
            });
            result.document = { status: added.first.status, static: isStatic(added.first.headers) };
            result.post = added.after ? { method: added.after.method, status: added.after.status, static: isStatic(added.after.headers) } : null;
            result.notice = added.notice;
            result.cookies = await cookies();
        } else if ('ajax' === scenario) {
            const doc = await navigate('/vetrina/');
            result.document = { status: doc.status, static: isStatic(doc.headers) };
            const callsBefore = log.length;
            await clickAt('a.ajax_add_to_cart');
            await waitFor(`!!document.querySelector('a.ajax_add_to_cart.added')`, 15000);
            await sleep(1500);
            result.adds = log.slice(callsBefore).filter((entry) => /wc-ajax=/.test(entry.url)).map(({ url, status }) => ({ url, status }));
            result.miniCount = await evaluate(`(() => { const b = document.querySelector('.wc-block-mini-cart__badge'); return b ? b.textContent.trim() : null; })()`);
            result.cookies = await cookies();
        } else if ('checkout' === scenario) {
            const added = await addFormProduct('/product/miscela-arabica-qa/');
            result.document = { status: added.first.status, static: isStatic(added.first.headers) };
            result.notice = added.notice;
            const doc = await navigate('/checkout/');
            result.checkoutDocument = { status: doc.status, static: isStatic(doc.headers), url: doc.url.replace(base, '') };
            await waitFor(`!!document.querySelector('#email')`, 20000);
            await type('#email', email || 'checkout@example.invalid');
            await type('#billing-first_name', 'Prova');
            await type('#billing-last_name', 'Static Bridge');
            await type('#billing-address_1', 'Via Esempio 1');
            await type('#billing-city', 'Milano');
            await type('#billing-postcode', '20100');
            result.state = await evaluate(`(() => { const s = document.querySelector('#billing-state'); return s ? s.value : null; })()`);
            if (!result.state) {
                await evaluate(`(() => { const s = document.querySelector('#billing-state'); if (s) { s.value = 'MI'; s.dispatchEvent(new Event('change', { bubbles: true })); } return true; })()`);
            }
            await clickAt('#radio-control-wc-payment-method-options-cod');
            await sleep(800);
            const before = documents.length;
            const navigated = new Promise((resolve) => listeners.push((event) => event.method === 'Page.loadEventFired' && resolve()));
            await clickAt('.wc-block-components-checkout-place-order-button');
            await Promise.race([navigated, sleep(25000)]);
            await sleep(1000);
            const received = documents.slice(before).pop() || null;
            result.received = received ? { status: received.status, static: isStatic(received.headers), url: received.url.replace(base, '') } : null;
            result.receivedText = await evaluate(`(() => { const n = document.querySelector('.wc-block-order-confirmation-status, .woocommerce-thankyou-order-received, .woocommerce-notice--success'); return n ? n.textContent.replace(/\\s+/g, ' ').trim().slice(0, 200) : ''; })()`);
            result.errors = await evaluate(`Array.from(document.querySelectorAll('.wc-block-components-validation-error, .wc-block-store-notice, .woocommerce-error')).map((n) => n.textContent.replace(/\\s+/g, ' ').trim()).filter(Boolean).slice(0, 5)`);
            result.checkoutCalls = storeCalls(/wc\/store\/v1\/checkout/).map(({ method, status }) => ({ method, status }));
        }
        result.pageErrors = pageErrors.slice(0, 5);
        ws.close();
        return result;
    } finally {
        chrome.kill('SIGKILL');
        await sleep(300);
        fs.rmSync(profile, { recursive: true, force: true });
    }
}

main().then((result) => {
    process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}).catch((error) => {
    process.stdout.write(JSON.stringify({ scenario, error: String(error && error.stack || error) }) + '\n');
    process.exitCode = 1;
});
