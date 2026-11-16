(function () {
    'use strict';

    const cfg = window.StaticBridgePostPush;
    const notice = document.getElementById('static-bridge-post-push-status');
    if (!cfg || !notice) return;

    let delay = 2500;
    let stopped = false;
    let pollTimer = null;

    function schedule(nextDelay) {
        if (pollTimer !== null) window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(function () {
            pollTimer = null;
            poll();
        }, nextDelay);
    }

    function setNotice(state, message) {
        notice.classList.remove('notice-info', 'notice-success', 'notice-error');
        notice.classList.add(state === 'completed' ? 'notice-success' : (state === 'failed' ? 'notice-error' : 'notice-info'));
        notice.dataset.state = state;
        const paragraph = notice.querySelector('p');
        if (paragraph) paragraph.textContent = message;
    }

    async function poll() {
        if (stopped || document.visibilityState === 'hidden') {
            if (!stopped) schedule(10000);
            return;
        }

        const body = new URLSearchParams({
            action: 'static_bridge_post_push_status',
            token: cfg.token,
            _wpnonce: cfg.nonce
        });

        try {
            const response = await fetch(cfg.statusUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            const result = await response.json();
            if (!response.ok || !result.success || !result.data) {
                throw new Error('status-unavailable');
            }

            const data = result.data;
            if (data.status === 'completed') {
                stopped = true;
                setNotice('completed', cfg.strings.completed);
                return;
            }
            if (data.status === 'failed') {
                stopped = true;
                setNotice('failed', cfg.strings.failed + (data.lastError || cfg.strings.statusError));
                return;
            }

            if (data.status === 'retrying') {
                const detail = data.lastError ? ' ' + data.lastError : '';
                setNotice('retrying', cfg.strings.retrying + detail);
                delay = Math.min(15000, Math.max(5000, delay * 1.5));
            } else {
                setNotice('queued', cfg.strings.queued);
                delay = Math.min(10000, Math.max(3000, delay * 1.25));
            }
        } catch (error) {
            setNotice('queued', cfg.strings.statusError);
            delay = Math.min(30000, Math.max(10000, delay * 2));
        }

        if (!stopped) schedule(delay);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !stopped) {
            schedule(250);
        }
    });

    poll();
}());
