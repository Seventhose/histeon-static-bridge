(function () {
    'use strict';

    const cfg = window.StaticBridgeAdmin;
    if (!cfg) return;
    // Translations from wp.i18n when WordPress loads it; the Italian source text otherwise.
    const i18n = window.wp && window.wp.i18n;
    const __ = i18n && i18n.__ ? i18n.__ : function (text) { return text; };
    const sprintf = i18n && i18n.sprintf ? i18n.sprintf : function (format) {
        const values = Array.prototype.slice.call(arguments, 1);
        let next = 0;
        return String(format).replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            return String(values[position ? Number(position) - 1 : next++]);
        });
    };
    const _n = i18n && i18n._n ? i18n._n : function (single, plural, number) { return Number(number) === 1 ? single : plural; };
    // The pieces of the progress line, each with its own singular and plural.
    const savedPages = (n) => sprintf(_n('%d pagina salvata', '%d pagine salvate', n, 'static-bridge'), n);
    const queued = (n) => sprintf(__('%d in coda', 'static-bridge'), n);
    let exportIsRunning = String(cfg.isRunning) === '1';
    let exportCanResume = String(cfg.isResumable) === '1';
    const diagnosticIsRunning = String(cfg.isDiagnosticRunning) === '1';

    async function request(action, parameters) {
        const body = new URLSearchParams({ action, nonce: cfg.nonce });
        if (parameters && typeof parameters === 'object') {
            Object.keys(parameters).forEach(function (key) {
                if (key !== 'action' && key !== 'nonce') body.set(key, String(parameters[key]));
            });
        }
        const response = await fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.data && result.data.message ? result.data.message : cfg.strings.requestError);
        }
        return result.data;
    }

    const button = document.getElementById('sb-generate');
    const panel = document.getElementById('sb-progress-panel');
    const cancelButton = document.getElementById('sb-cancel');
    if (button && panel) {
        const title = document.getElementById('sb-progress-title');
        const count = document.getElementById('sb-progress-count');
        const bar = document.getElementById('sb-progress-bar');
        const track = panel.querySelector('[role="progressbar"]');
        const current = document.getElementById('sb-progress-current');
        const message = document.getElementById('sb-progress-message');
        const issuesCard = document.getElementById('sb-issues-card');
        const issuesList = document.getElementById('sb-issues-list');
        const label = button.querySelector('.sb-button-label');
        let timer = null;
        let tickInFlight = false;
        let cancelRequested = false;
        let communicationFailures = 0;

        function setBusy(busy, text) {
            button.disabled = busy;
            if (label && text) label.textContent = text;
            button.classList.toggle('is-busy', busy);
        }

        function scheduleRecoveryPoll() {
            const exponent = Math.min(4, Math.max(0, communicationFailures - 1));
            const delay = Math.min(15000, 1000 * (2 ** exponent));
            timer = window.setTimeout(poll, delay);
        }

        function showProgress(data) {
            panel.hidden = false;
            if (issuesCard && issuesList) {
                const issues = Array.isArray(data.issues) ? data.issues.slice(-8) : [];
                issuesList.replaceChildren();
                issues.forEach(function (issue) {
                    if (!issue || typeof issue !== 'object') return;
                    const item = document.createElement('li');
                    const url = document.createElement('code');
                    url.textContent = typeof issue.url === 'string' ? issue.url : '';
                    item.appendChild(url);
                    item.appendChild(document.createTextNode(` — ${typeof issue.message === 'string' ? issue.message : ''}`));
                    issuesList.appendChild(item);
                });
                issuesCard.hidden = issuesList.childElementCount === 0;
            }
            const percentage = Number(data.progress || 0);
            bar.style.width = percentage + '%';
            track.setAttribute('aria-valuenow', String(percentage));
            title.textContent = data.status === 'complete'
                ? (Number(data.routeErrors || 0) > 0
                    ? cfg.strings.completeWithErrors.replace('%d', String(Number(data.routeErrors || 0)))
                    : cfg.strings.complete)
                : (data.status === 'cancelled' ? cfg.strings.cancelled : (data.status === 'paused' ? cfg.strings.paused : (data.status === 'failed' ? cfg.strings.failed : cfg.strings.running)));
            const fetchMode = data.fetchMode === 'parallel'
                ? cfg.strings.parallelMode.replace('%d', String(Math.max(2, Number(data.fetchConcurrency) || 2)))
                : (data.fetchMode === 'sequential' ? cfg.strings.sequentialMode : '');
            count.textContent = String(data.processed || 0) + ' / ' + String(data.total || 0)
                + (fetchMode ? ' · ' + fetchMode : '')
                + (data.status === 'running' && data.discoveryComplete === false ? ' · ' + cfg.strings.scanning : '');
            current.textContent = data.currentUrl || '';
            message.textContent = data.status === 'complete'
                ? [
                    sprintf(_n('%d pagina pubblicata', '%d pagine pubblicate', data.saved || 0, 'static-bridge'), data.saved || 0),
                    sprintf(_n('%d copia precedente mantenuta', '%d copie precedenti mantenute', data.preserved || 0, 'static-bridge'), data.preserved || 0),
                    sprintf(_n('%d URL non aggiornata', '%d URL non aggiornate', data.skipped || 0, 'static-bridge'), data.skipped || 0),
                ].join(' · ')
                : (data.status === 'cancelled'
                    ? cfg.strings.cancelledMessage
                    : (data.status === 'paused'
                    ? `${cfg.strings.pausedMessage}${data.issues && data.issues.length ? ` ${data.issues[data.issues.length - 1].message}` : ''}`
                    : (data.status === 'failed'
                    ? ((data.issues && data.issues.length && data.issues[data.issues.length - 1].message) || cfg.strings.requestError)
                    : (Number(data.retryCount || 0) > 0
                        ? [
                            /* translators: 1: "Retrying" label, 2: current attempt, 3: maximum attempts. */
                            sprintf(__('%1$s %2$s/%3$s…', 'static-bridge'), cfg.strings.retrying, data.retryCount, cfg.retryLimit),
                            savedPages(data.saved || 0),
                            queued(data.pending || 0),
                        ].join(' · ')
                    : savedPages(data.saved || 0) + ' · ' + queued(data.pending || 0)))));
            if (data.workerBusy) message.textContent = cfg.strings.workerBusy;
            if (data.status === 'running' && Number(data.waiting || 0) > 0) {
                /* translators: %d: seconds before the export fetches again. */
                message.textContent = sprintf(__('Il server ha chiesto di rallentare (HTTP 429 o 503): l’esportazione riprende fra %d s, anche a pagina chiusa.', 'static-bridge'), Number(data.waiting))
                    + (data.recoveryRound ? ' ' + __('Giro di recupero delle pagine rallentate.', 'static-bridge') : '');
            }
            if (cancelButton) cancelButton.hidden = !['running', 'paused'].includes(data.status);
            if (data.status === 'running') {
                exportIsRunning = true;
                exportCanResume = false;
            } else if (data.status === 'paused') {
                exportIsRunning = false;
                exportCanResume = true;
            } else if (['complete', 'failed', 'cancelled'].includes(data.status)) {
                exportIsRunning = false;
                exportCanResume = false;
            }
        }

        async function cancelExport() {
            cancelRequested = false;
            try {
                const data = await request('static_bridge_cancel');
                showProgress(data);
                setBusy(false, cfg.strings.start);
                if (cancelButton) cancelButton.disabled = false;
            } catch (error) {
                message.textContent = error.message;
                if (cancelButton) cancelButton.disabled = false;
                if (!timer) timer = window.setTimeout(poll, 350);
            }
        }

        async function poll() {
            timer = null;
            tickInFlight = true;
            let data;
            try {
                data = await request('static_bridge_tick');
            } catch (error) {
                tickInFlight = false;
                communicationFailures++;
                let status = null;
                try {
                    status = await request('static_bridge_status');
                } catch (statusError) {
                    // A dropped tick and an unavailable status endpoint leave the job outcome unknown.
                }

                if (status && status.status === 'running') {
                    showProgress(status);
                    title.textContent = cfg.strings.running;
                    message.textContent = cfg.strings.connectionRetrying;
                    setBusy(false, cfg.strings.resume);
                    if (cancelButton) cancelButton.disabled = false;
                    scheduleRecoveryPoll();
                    return;
                }

                if (status && ['complete', 'failed', 'cancelled', 'paused'].includes(status.status)) {
                    communicationFailures = 0;
                    showProgress(status);
                    setBusy(false, exportCanResume ? cfg.strings.resume : cfg.strings.start);
                    if (cancelButton) cancelButton.disabled = false;
                    if (status.status === 'complete') {
                        window.setTimeout(function () { window.location.reload(); }, 800);
                    }
                    return;
                }

                if (status && status.status === 'idle') {
                    communicationFailures = 0;
                    exportIsRunning = false;
                    exportCanResume = false;
                    title.textContent = cfg.strings.failed;
                    message.textContent = error.message;
                    setBusy(false, cfg.strings.start);
                    if (cancelButton) {
                        cancelButton.hidden = true;
                        cancelButton.disabled = false;
                    }
                    return;
                }

                title.textContent = cfg.strings.connectionLost;
                message.textContent = cfg.strings.connectionLostMessage;
                setBusy(false, exportIsRunning ? cfg.strings.resume : cfg.strings.start);
                if (cancelButton) {
                    cancelButton.hidden = !exportIsRunning;
                    cancelButton.disabled = false;
                }
                scheduleRecoveryPoll();
                return;
            }
            tickInFlight = false;
            communicationFailures = 0;
            showProgress(data);
            if (cancelRequested) {
                if (data.status === 'running') await cancelExport();
                else {
                    cancelRequested = false;
                    setBusy(false, cfg.strings.start);
                    if (cancelButton) cancelButton.disabled = false;
                }
                return;
            }
            if (data.status === 'running') {
                const waiting = Number(data.waiting || 0);
                timer = window.setTimeout(poll, waiting > 0 ? Math.min(15000, waiting * 1000) : (data.workerBusy ? 5000 : 350));
                return;
            }
            setBusy(false, exportCanResume ? cfg.strings.resume : cfg.strings.start);
            if (cancelButton) cancelButton.disabled = false;
            if (data.status === 'complete') {
                window.setTimeout(function () { window.location.reload(); }, 800);
            }
        }

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                cancelRequested = true;
                if (timer) window.clearTimeout(timer);
                timer = null;
                cancelButton.disabled = true;
                if (!tickInFlight) cancelExport();
            });
        }

        button.addEventListener('click', async function () {
            if (timer) window.clearTimeout(timer);
            timer = null;
            cancelRequested = false;
            communicationFailures = 0;
            panel.hidden = false;
            title.textContent = cfg.strings.starting;
            message.textContent = '';
            setBusy(true, cfg.strings.running);
            try {
                let data = await request('static_bridge_status');
                if (['idle', 'failed', 'cancelled', 'complete'].includes(data.status)) data = await request('static_bridge_start');
                else if (data.status === 'paused') data = await request('static_bridge_resume');
                showProgress(data);
                if (data.status === 'running') poll();
                else {
                    setBusy(false, exportCanResume ? cfg.strings.resume : cfg.strings.start);
                    if (data.status === 'complete') window.location.reload();
                }
            } catch (error) {
                let status = null;
                try {
                    status = await request('static_bridge_status');
                } catch (statusError) {
                    // Preserve an unknown state; a failed start response may have been lost after server-side work began.
                }
                if (status && status.status === 'running') {
                    showProgress(status);
                    message.textContent = cfg.strings.connectionRetrying;
                    timer = window.setTimeout(poll, 1000);
                } else if (status && ['complete', 'failed', 'cancelled', 'paused'].includes(status.status)) {
                    showProgress(status);
                    if (status.status === 'paused' && error && error.message) message.textContent = error.message;
                    setBusy(false, exportCanResume ? cfg.strings.resume : cfg.strings.start);
                    if (status.status === 'complete') window.setTimeout(function () { window.location.reload(); }, 800);
                } else if (status && status.status === 'idle') {
                    title.textContent = cfg.strings.failed;
                    message.textContent = error.message;
                    setBusy(false, cfg.strings.start);
                } else {
                    communicationFailures++;
                    title.textContent = cfg.strings.connectionLost;
                    message.textContent = cfg.strings.connectionLostMessage;
                    setBusy(false, exportIsRunning ? cfg.strings.resume : cfg.strings.start);
                    if (cancelButton) cancelButton.hidden = !exportIsRunning;
                    scheduleRecoveryPoll();
                }
            }
        });

        if (exportIsRunning || exportCanResume) {
            button.disabled = false;
            label.textContent = cfg.strings.resume;
        }
    }

    const diagnosticButton = document.getElementById('sb-run-diagnostics');
    const diagnosticPanel = document.getElementById('sb-diagnostic-progress');
    const diagnosticResults = document.getElementById('sb-diagnostic-results');
    const diagnosticTarget = document.getElementById('sb-diagnostic-target-url');
    if (diagnosticButton && diagnosticPanel && diagnosticResults) {
        const diagnosticTitle = document.getElementById('sb-diagnostic-title');
        const diagnosticCount = document.getElementById('sb-diagnostic-count');
        const diagnosticBar = document.getElementById('sb-diagnostic-bar');
        const diagnosticTrack = diagnosticPanel.querySelector('[role="progressbar"]');
        const diagnosticCurrent = document.getElementById('sb-diagnostic-current');
        const diagnosticLabel = diagnosticButton.querySelector('.sb-diagnostic-button-label');
        const statusLabels = {
            pass: __( 'Superato', 'static-bridge' ),
            warning: __( 'Avviso', 'static-bridge' ),
            fail: __( 'Errore', 'static-bridge' ),
            skip: __( 'Saltato', 'static-bridge' )
        };
        const statusIcons = { pass: 'dashicons-yes-alt', warning: 'dashicons-warning', fail: 'dashicons-dismiss', skip: 'dashicons-minus' };
        let diagnosticTimer = null;

        function appendAction(parent, action) {
            if (!cfg.urls || !cfg.urls[action]) return;
            const link = document.createElement('a');
            link.href = cfg.urls[action];
                link.textContent = action === 'forms'
                    ? __( 'Apri Moduli →', 'static-bridge' )
                    : (action === 'settings' ? __( 'Apri Impostazioni →', 'static-bridge' ) : __( 'Apri Genera →', 'static-bridge' ));
            parent.appendChild(document.createTextNode(' '));
            parent.appendChild(link);
        }

        function renderResults(results) {
            diagnosticResults.replaceChildren();
            if (!Array.isArray(results) || results.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'sb-empty-state';
                const text = document.createElement('p');
                text.textContent = __( 'In attesa dei risultati del test…', 'static-bridge' );
                empty.appendChild(text);
                diagnosticResults.appendChild(empty);
                return;
            }

            // What needs attention comes first; each group keeps the order of the checks.
            const severity = function (result) {
                const status = Object.prototype.hasOwnProperty.call(statusLabels, result.status) ? result.status : 'warning';
                return 'fail' === status ? 0 : ('warning' === status ? 1 : 2);
            };
            const fragment = document.createDocumentFragment();
            results.slice().sort(function (a, b) {
                return severity(a) - severity(b);
            }).forEach(function (result) {
                const status = Object.prototype.hasOwnProperty.call(statusLabels, result.status) ? result.status : 'warning';
                const article = document.createElement('article');
                article.className = `sb-diagnostic-result is-${status}`;
                const icon = document.createElement('span');
                icon.className = `sb-diagnostic-icon dashicons ${statusIcons[status]}`;
                icon.setAttribute('aria-hidden', 'true');
                const content = document.createElement('div');
                content.className = 'sb-diagnostic-result-content';
                const heading = document.createElement('div');
                heading.className = 'sb-diagnostic-result-heading';
                const name = document.createElement('strong');
                name.textContent = result.label || __( 'Controllo', 'static-bridge' );
                const state = document.createElement('span');
                state.className = 'sb-diagnostic-status';
                state.textContent = result.status_label || statusLabels[status];
                heading.append(name, state);
                content.appendChild(heading);

                const summary = document.createElement('p');
                summary.textContent = result.summary || '';
                content.appendChild(summary);
                if (result.url) {
                    const url = document.createElement('code');
                    url.className = 'sb-diagnostic-url';
                    url.textContent = result.url;
                    content.appendChild(url);
                }
                if (result.remediation) {
                    const remediation = document.createElement('p');
                    remediation.className = 'sb-diagnostic-remediation';
                    remediation.textContent = result.remediation;
                    if (result.action === 'forms' || result.action === 'settings' || result.action === 'generate') appendAction(remediation, result.action);
                    content.appendChild(remediation);
                }
                article.append(icon, content);
                fragment.appendChild(article);
            });
            diagnosticResults.appendChild(fragment);
        }

        function showDiagnostic(data) {
            diagnosticPanel.hidden = false;
            const percentage = Number(data.progress || 0);
            diagnosticBar.style.width = percentage + '%';
            diagnosticTrack.setAttribute('aria-valuenow', String(percentage));
            diagnosticCount.textContent = `${data.completed || 0} / ${data.total || 0}`;
            diagnosticCurrent.textContent = data.current || '';
            renderResults(data.results);

            if (data.status === 'running') {
                diagnosticTitle.textContent = cfg.strings.diagnosticRunning;
                diagnosticLabel.textContent = cfg.strings.diagnosticRunning;
                diagnosticButton.disabled = true;
                if (diagnosticTarget) diagnosticTarget.disabled = true;
            } else {
                diagnosticButton.disabled = false;
                if (diagnosticTarget) diagnosticTarget.disabled = false;
                diagnosticLabel.textContent = cfg.strings.diagnosticStart;
                diagnosticTitle.textContent = data.status === 'passed'
                    ? cfg.strings.diagnosticPassed
                    : (data.status === 'failed' ? cfg.strings.diagnosticFailed : cfg.strings.diagnosticWarning);
                if (data.summary && data.summary.label) diagnosticCurrent.textContent = data.summary.label;
            }
        }

        async function pollDiagnostics() {
            try {
                const data = await request('static_bridge_diagnostic_tick');
                showDiagnostic(data);
                if (data.status === 'running') diagnosticTimer = window.setTimeout(pollDiagnostics, 500);
            } catch (error) {
                diagnosticButton.disabled = false;
                diagnosticLabel.textContent = cfg.strings.diagnosticStart;
                diagnosticTitle.textContent = cfg.strings.failed;
                diagnosticCurrent.textContent = error.message;
            }
        }

        diagnosticButton.addEventListener('click', async function () {
            if (diagnosticTimer) window.clearTimeout(diagnosticTimer);
            const targetUrl = diagnosticTarget && !diagnosticTarget.disabled ? diagnosticTarget.value.trim() : '';
            const startParameters = targetUrl ? { target_url: targetUrl } : null;
            diagnosticButton.disabled = true;
            if (diagnosticTarget) diagnosticTarget.disabled = true;
            diagnosticPanel.hidden = false;
            diagnosticTitle.textContent = cfg.strings.diagnosticStarting;
            diagnosticCurrent.textContent = '';
            try {
                let data;
                if (diagnosticIsRunning) {
                    data = await request('static_bridge_diagnostic_status');
                    if (data.status !== 'running') {
                        data = await request('static_bridge_diagnostic_start', startParameters);
                    }
                } else {
                    data = await request('static_bridge_diagnostic_start', startParameters);
                }
                showDiagnostic(data);
                if (data.status === 'running') pollDiagnostics();
            } catch (error) {
                diagnosticButton.disabled = false;
                if (diagnosticTarget) diagnosticTarget.disabled = false;
                diagnosticLabel.textContent = cfg.strings.diagnosticStart;
                diagnosticTitle.textContent = cfg.strings.failed;
                diagnosticCurrent.textContent = error.message;
            }
        });

        if (diagnosticIsRunning) {
            diagnosticLabel.textContent = cfg.strings.diagnosticResume;
            diagnosticButton.disabled = false;
        }
    }

    // Server tab: one request per check, each result shown as soon as it arrives, in the order of the checks.
    const serverButton = document.getElementById('sb-run-server-check');
    const serverResults = document.getElementById('sb-server-check-results');
    if (serverButton && serverResults) {
        const serverLabel = serverButton.querySelector('.sb-server-check-button-label');
        const serverNote = document.getElementById('sb-server-check-note');
        const serverStatusLabels = {
            pass: __( 'A posto', 'static-bridge' ),
            tip: __( 'Consigliato', 'static-bridge' ),
            warning: __( 'Da sistemare', 'static-bridge' ),
            skip: __( 'Non verificabile', 'static-bridge' )
        };
        const serverIcons = { pass: 'dashicons-yes-alt', tip: 'dashicons-lightbulb', warning: 'dashicons-warning', skip: 'dashicons-minus' };

        function serverArticle(label, status, statusText, summaryText, remediationText) {
            const article = document.createElement('article');
            article.className = `sb-diagnostic-result is-${status}`;
            const icon = document.createElement('span');
            icon.className = `sb-diagnostic-icon dashicons ${serverIcons[status] || 'dashicons-update'}`;
            icon.setAttribute('aria-hidden', 'true');
            const content = document.createElement('div');
            content.className = 'sb-diagnostic-result-content';
            const heading = document.createElement('div');
            heading.className = 'sb-diagnostic-result-heading';
            const name = document.createElement('strong');
            name.textContent = label;
            const state = document.createElement('span');
            state.className = 'sb-diagnostic-status';
            state.textContent = statusText;
            heading.append(name, state);
            content.appendChild(heading);
            if (summaryText) {
                const summary = document.createElement('p');
                summary.textContent = summaryText;
                content.appendChild(summary);
            }
            if (remediationText) {
                const remediation = document.createElement('p');
                remediation.className = 'sb-diagnostic-remediation';
                remediation.textContent = remediationText;
                content.appendChild(remediation);
            }
            article.append(icon, content);
            return article;
        }

        function resultArticle(result, fallbackLabel) {
            const status = Object.prototype.hasOwnProperty.call(serverStatusLabels, result.status) ? result.status : 'skip';
            return serverArticle(result.label || fallbackLabel, status, serverStatusLabels[status], result.summary || '', result.remediation || '');
        }

        // The whole list is drawn again after each answer: checks still waiting keep their place.
        function renderServerSlots(slots) {
            serverResults.replaceChildren(...slots.map(function (slot) {
                if (slot.result) return resultArticle(slot.result, slot.label);
                return serverArticle(slot.label, 'pending', slot.running ? cfg.strings.serverCheckRunning : __( 'In attesa', 'static-bridge' ), '', '');
            }));
        }

        async function runServerCheck() {
            serverButton.disabled = true;
            serverLabel.textContent = cfg.strings.serverCheckRunning;
            try {
                const start = await request('static_bridge_server_check_start');
                const slots = (Array.isArray(start.checks) ? start.checks : []).map(function (check) {
                    return { id: check.id, label: check.label, running: false, result: null };
                });
                for (const slot of slots) {
                    slot.running = true;
                    renderServerSlots(slots);
                    try {
                        const data = await request('static_bridge_server_check_step', { check: slot.id });
                        slot.result = data.result || { status: 'skip' };
                        if (data.done && serverNote) serverNote.textContent = data.note || '';
                    } catch (error) {
                        slot.result = { status: 'skip', summary: error.message };
                    }
                    slot.running = false;
                }
                renderServerSlots(slots);
            } catch (error) {
                if (serverNote) serverNote.textContent = error.message;
            } finally {
                serverButton.disabled = false;
                serverLabel.textContent = cfg.strings.serverCheckStart;
            }
        }

        serverButton.addEventListener('click', runServerCheck);
        if (String(cfg.serverCheckAutoRun) === '1') runServerCheck();
    }

    // The settings index can point to a closed section.
    const revealSection = function (hash) {
        const section = 0 === String(hash).indexOf('#sb-section-') ? document.getElementById(String(hash).slice(1)) : null;
        const details = section ? section.querySelector('details') : null;
        if (details) details.open = true;
    };
    // Versioni (2.4.0): a deletion asks first; the message comes translated from the page.
    document.querySelectorAll('form[data-sb-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-sb-confirm'))) event.preventDefault();
        });
    });
    const settingsIndex = document.querySelector('.sb-settings-index');
    if (settingsIndex) {
        settingsIndex.addEventListener('click', function (event) {
            const link = event.target.closest('a');
            if (link) revealSection(link.hash);
        });
        revealSection(window.location.hash);
    }
}());
