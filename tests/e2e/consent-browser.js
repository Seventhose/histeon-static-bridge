'use strict';
// Real-browser consent flow on a static page (headless Google Chrome via CDP, Node 22 built-in WebSocket):
// an anonymous visitor, real mouse clicks on Complianz's own buttons. Expects the page to carry two probe
// scripts that Complianz must block, /wp-content/sbqa-stat.js (statistics) and /wp-content/sbqa-mkt.js
// (marketing), each counting its runs in window.__sbqa. Loopback URLs of the disposable stacks and clones only.
//
// Usage: node consent-browser.js <base-url> <page-path> <policy-path|-> [fresh|dnt|renew|all]
// Prints one JSON object.
const { execSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const [base, pagePath, policyPath, which = 'all'] = process.argv.slice(2);
if (!/^http:\/\/(127\.0\.0\.1:187\d\d|localhost:88\d\d)$/.test(base || '') || !pagePath) {
    process.stderr.write('Usage: node consent-browser.js <http://127.0.0.1:187xx|http://localhost:88xx> <page-path> <policy-path|-> [fresh|dnt|renew|all]\n');
    process.exit(2);
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const SETTLE = 1800;

async function withChrome(preferences, body) {
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'sbqa-chrome-'));
    if (preferences) {
        fs.mkdirSync(path.join(profile, 'Default'), { recursive: true });
        fs.writeFileSync(path.join(profile, 'Default', 'Preferences'), JSON.stringify(preferences));
    }
    const chrome = spawn('google-chrome', ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--window-size=1280,900',
        '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', 'about:blank'], { stdio: 'ignore' });
    let ws = null;
    try {
        let port = '';
        for (let i = 0; i < 100 && !port; i++) {
            await sleep(100);
            try { port = fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]; } catch (error) { port = ''; }
        }
        if (!port) throw new Error('Chrome did not open its DevTools port');
        const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
        ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
        let nextId = 0;
        const pending = new Map();
        const net = new Map();
        const console_errors = [];
        ws.onmessage = (message) => {
            const data = JSON.parse(message.data);
            if (data.id && pending.has(data.id)) {
                const { resolve, reject } = pending.get(data.id);
                pending.delete(data.id);
                data.error ? reject(new Error(JSON.stringify(data.error))) : resolve(data.result);
                return;
            }
            const p = data.params || {};
            if ('Network.requestWillBeSent' === data.method) {
                const known = net.get(p.requestId); // ExtraInfo may arrive first
                net.set(p.requestId, { url: p.request.url, method: p.request.method, postData: p.request.postData || '', status: 0, headers: {}, cookie: known ? known.cookie : '', type: p.type });
            } else if ('Network.requestWillBeSentExtraInfo' === data.method) {
                const cookie = Object.entries(p.headers || {}).find(([k]) => 'cookie' === k.toLowerCase());
                if (cookie) {
                    const row = net.get(p.requestId);
                    if (row) row.cookie = cookie[1]; else net.set(p.requestId, { url: '', method: '', postData: '', status: 0, headers: {}, cookie: cookie[1], type: '' });
                }
            } else if ('Network.responseReceived' === data.method) {
                const row = net.get(p.requestId);
                if (row) {
                    row.status = p.response.status;
                    row.headers = Object.fromEntries(Object.entries(p.response.headers || {}).map(([k, v]) => [k.toLowerCase(), v]));
                }
            } else if ('Network.loadingFailed' === data.method) {
                const row = net.get(p.requestId);
                if (row && p.canceled) row.canceled = true;
            } else if ('Runtime.exceptionThrown' === data.method) {
                console_errors.push(String((p.exceptionDetails.exception && p.exceptionDetails.exception.description) || p.exceptionDetails.text).slice(0, 200));
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
        await send('Network.enable');
        await send('Runtime.enable');
        await send('Page.enable');
        await send('Network.setCacheDisabled', { cacheDisabled: true });
        if (process.env.SBQA_LATENCY) await send('Network.emulateNetworkConditions', { offline: false, latency: Number(process.env.SBQA_LATENCY), downloadThroughput: -1, uploadThroughput: -1 }); // a slow visitor
        if (process.env.SBQA_CLIENT_IP) await send('Network.setExtraHTTPHeaders', { headers: { 'X-SBQA-IP': process.env.SBQA_CLIENT_IP } }); // GeoIP probe on a clone
        // Complianz reloads right after sending some requests. Chrome does not always report those as canceled, but a newer document means nothing will answer.
        const cut = (r, n, rows) => !!r.canceled || rows.slice(n + 1).some((later) => 'Document' === later.type);
        const goto = async (url) => {
            net.clear();
            await send('Page.navigate', { url });
            await sleep(SETTLE);
            for (let i = 0; i < 50 && 'complete' !== await evaluate('document.readyState').catch(() => ''); i++) await sleep(100);
            await sleep(SETTLE);
        };
        // A person's click: the mouse moves to the visible element, waits for hover effects, then presses it.
        const locate = (selector) => evaluate(`(() => { const all = [...document.querySelectorAll(${JSON.stringify(selector)})];
            const el = all.find((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && 'hidden' !== getComputedStyle(e).visibility; });
            if (!el) return null; if (!el.closest('#cmplz-manage-consent')) el.scrollIntoView({ block: 'center', behavior: 'instant' }); const r = el.getBoundingClientRect();
            return { x: r.left + r.width / 2, y: Math.min(r.top + r.height / 2, innerHeight - 3) }; })()`);
        const click = async (selector) => {
            let box = await locate(selector);
            if (!box) return false;
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
            await sleep(800); // the "manage consent" tab slides in on hover
            box = await locate(selector) || box;
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: box.x, y: box.y, button: 'left', clickCount: 1 });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: box.x, y: box.y, button: 'left', clickCount: 1 });
            await sleep(SETTLE);
            // A loaded machine answers late: a request still in flight is not a failed one, one that never answers stays at 0.
            for (let i = 0; i < 150 && [...net.values()].some((r, n, rows) => r.url.includes('/complianz/v1/') && 0 === r.status && !cut(r, n, rows)); i++) await sleep(100);
            return true;
        };
        const snapshot = async () => {
            const documents = [...net.values()].filter((r) => 'Document' === r.type && r.url.startsWith(base));
            const doc = documents[documents.length - 1] || { headers: {}, status: 0, cookie: '' };
            const page = await evaluate(`(() => {
                const banner = document.querySelector('.cmplz-cookiebanner');
                const shown = !!banner && banner.classList.contains('cmplz-show') && banner.getBoundingClientRect().height > 0 && 'hidden' !== getComputedStyle(banner).visibility;
                return { title: document.title, banner_in_html: !!banner, banner_visible: shown, runs: window.__sbqa || {},
                    held: [...document.querySelectorAll('script[type="text/plain"], script[data-cmplz-src]')].map((s) => (s.getAttribute('data-cmplz-src') || s.getAttribute('src') || '').replace(/^.*\\//, '')).filter((s) => s.startsWith('sbqa-')),
                    banner_version: window.complianz ? complianz.banner_version : null, policy_id: window.complianz ? complianz.current_policy_id : null,
                    store_consent: window.complianz ? complianz.store_consent : null, region: window.complianz ? complianz.region : null, consenttype: window.complianz ? complianz.consenttype : null, geoip: window.complianz ? complianz.geoip : null };
            })()`);
            const all = await send('Network.getAllCookies');
            return {
                ...page,
                status: doc.status,
                static: 'static' === (doc.headers['x-static-bridge-route'] || '').toLowerCase() || 'static-bridge' === (doc.headers['x-sbqa-apache-file'] || '').toLowerCase() || (!doc.headers['x-powered-by'] && !!doc.headers.etag),
                x_powered_by: doc.headers['x-powered-by'] || '',
                etag: doc.headers.etag || '',
                sent_cmplz_cookies: (doc.cookie.match(/cmplz_[a-z_-]+/g) || []),
                cookies: Object.fromEntries(all.cookies.filter((c) => c.name.startsWith('cmplz_')).map((c) => [c.name, c.value])),
                probe_requests: [...net.values()].filter((r) => /sbqa-(stat|mkt)\.js/.test(r.url)).map((r) => r.url.replace(/^.*\//, '').replace(/\?.*$/, '') + ':' + r.status),
                complianz_rest: [...net.values()].map((r, n, rows) => ({ ...r, canceled: 0 === r.status && cut(r, n, rows) })).filter((r) => r.url.includes('/complianz/v1/')).map((r) => ({ method: r.method, path: r.url.replace(/^.*complianz\/v1\//, ''), status: r.status, canceled: r.canceled, body: r.postData.slice(0, 200) })),
            };
        };
        return await body({ goto, click, snapshot, evaluate, send, console_errors, net, sleep });
    } finally {
        if (ws) ws.close();
        chrome.kill('SIGKILL');
        await sleep(200);
        fs.rmSync(profile, { recursive: true, force: true });
    }
}

async function fresh() {
    return withChrome(null, async (b) => {
        const out = {};
        await b.goto(base + pagePath);
        out.first_visit = await b.snapshot();
        out.accept_clicked = await b.click('.cmplz-cookiebanner.cmplz-show .cmplz-btn.cmplz-accept');
        out.after_accept = await b.snapshot();
        await b.goto(base + pagePath);
        out.return_visit = await b.snapshot();
        out.manage_clicked = await b.click('#cmplz-manage-consent button.cmplz-manage-consent');
        const reopened = await b.snapshot();
        out.banner_reopened = reopened.banner_visible;
        out.deny_clicked = await b.click('.cmplz-cookiebanner.cmplz-show .cmplz-btn.cmplz-deny');
        await b.sleep(1000);
        out.after_revoke_same_page = await b.snapshot();
        await b.goto(base + pagePath);
        out.visit_after_revoke = await b.snapshot();
        if ('-' !== policyPath) {
            await b.goto(base + policyPath);
            out.policy = await b.snapshot();
            out.policy.has_document = await b.evaluate(`!!document.querySelector('#cmplz-document, .cmplz-document')`);
            out.policy.manage_container_filled = await b.evaluate(`(() => { const c = document.querySelector('.cmplz-manage-consent-container'); return c ? c.querySelectorAll('input[type=checkbox]').length : -1; })()`);
            out.policy.toggle_clicked = await b.click('.cmplz-manage-consent-container input[data-category="cmplz_marketing"]');
            out.policy.cookie_after_toggle = await b.evaluate(`document.cookie`);
        }
        out.page_errors = b.console_errors;
        return out;
    });
}

// The visitor has consented, then the site owner changes something (SBQA_BETWEEN, a shell command run while the
// browser stays open: it must leave the page republished). The new static page has to reach that visitor.
async function renew() {
    return withChrome(null, async (b) => {
        const out = {};
        await b.goto(base + pagePath);
        out.first_visit = await b.snapshot();
        out.accept_clicked = await b.click('.cmplz-cookiebanner.cmplz-show .cmplz-btn.cmplz-accept');
        await b.goto(base + pagePath);
        out.consented = await b.snapshot();
        out.between = execSync(process.env.SBQA_BETWEEN || 'true', { encoding: 'utf8' }).trim().slice(-400);
        await b.goto(base + pagePath);
        out.after_change = await b.snapshot();
        out.page_errors = b.console_errors;
        return out;
    });
}

async function dnt() {
    return withChrome({ enable_do_not_track: true }, async (b) => {
        await b.goto(base + pagePath);
        const out = await b.snapshot();
        out.navigator_dnt = await b.evaluate('navigator.doNotTrack');
        out.page_errors = b.console_errors;
        return out;
    });
}

(async () => {
    const result = {};
    if ('renew' === which) {
        result.renew = await renew();
    } else {
        if ('dnt' !== which) result.fresh = await fresh();
        if ('fresh' !== which) result.dnt = await dnt();
    }
    process.stdout.write(JSON.stringify(result, null, 1) + '\n');
})().catch((error) => { process.stderr.write(String(error && error.stack || error) + '\n'); process.exit(1); });
