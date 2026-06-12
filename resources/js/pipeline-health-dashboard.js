import { apiUrl } from './playground/urls.js';

const root = document.querySelector('[data-pipeline-health-dashboard]');

if (root) {
    const els = {
        refresh: document.getElementById('pipeline-health-refresh'),
        status: document.getElementById('pipeline-health-status'),
        state: document.getElementById('pipeline-health-state'),
        updated: document.getElementById('pipeline-health-updated'),
        metrics: document.getElementById('pipeline-health-metrics'),
        warnings: document.getElementById('pipeline-health-warnings'),
        queueCount: document.getElementById('pipeline-health-queue-count'),
        queues: document.getElementById('pipeline-health-queues'),
        retryCount: document.getElementById('pipeline-health-retry-count'),
        retryQueues: document.getElementById('pipeline-health-retry-queues'),
    };

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function requestJson(path, options = {}) {
        const response = await fetch(apiUrl(path), {
            ...options,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                ...(options.headers || {}),
            },
        });
        const text = await response.text();
        const data = text ? JSON.parse(text) : {};

        if (!response.ok || data.success === false) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }

        return data;
    }

    function setStatus(message, tone = 'neutral') {
        if (!els.status) return;
        els.status.textContent = message;
        els.status.dataset.tone = tone;
    }

    function setText(el, value) {
        if (el) el.textContent = value;
    }

    function valueOrDash(value) {
        return value === undefined || value === null || value === '' ? '-' : String(value);
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);

        return date.toLocaleString([], {
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function statusClass(status) {
        const value = String(status || 'unknown').toLowerCase();
        if (value === 'ok') return 'is-ok';
        if (value === 'warn') return 'is-warn';
        if (value === 'fail') return 'is-fail';

        return 'is-idle';
    }

    function statusPill(status) {
        const pill = document.createElement('span');
        pill.className = `status-pill ${statusClass(status)}`;
        pill.textContent = valueOrDash(status);

        return pill;
    }

    function renderMonitor(payload) {
        const checks = Array.isArray(payload.checks) ? payload.checks : [];
        const status = checks.some((check) => check.status === 'fail')
            ? 'fail'
            : checks.some((check) => check.status === 'warn')
                ? 'warn'
                : 'ok';

        els.state.className = `status-pill ${statusClass(status)}`;
        setText(els.state, status);
        setText(els.updated, `Checked ${formatDate(payload.checkedAt)} from Laravel health checks.`);
        setStatus(
            status === 'ok' ? 'Ingestion services look healthy.' : 'One or more ingestion services need attention.',
            status === 'ok' ? 'success' : status === 'fail' ? 'error' : 'warning',
        );

        renderMetrics(checks);
        renderWarnings(checks);
        renderQueues(checks);
        renderRetryQueues(checks);
    }

    function renderMetrics(checks) {
        els.metrics.innerHTML = '';
        const counts = checks.reduce((summary, check) => {
            const status = String(check.status || 'unknown').toLowerCase();
            summary.total += 1;
            if (status === 'ok') summary.ok += 1;
            if (status === 'warn') summary.warn += 1;
            if (status === 'fail') summary.fail += 1;
            return summary;
        }, { total: 0, ok: 0, warn: 0, fail: 0 });

        [
            ['Checks', counts.total, 'Configured health checks'],
            ['Healthy', counts.ok, 'Services reporting ok'],
            ['Warnings', counts.warn, 'Services with warnings'],
            ['Failures', counts.fail, 'Services needing action'],
            ['Task queues', 4, 'Workflow, scraper, converter, ingestion'],
        ].forEach(([label, value, caption]) => {
            const item = document.createElement('div');
            item.className = 'metric-item';
            const strong = document.createElement('strong');
            strong.textContent = valueOrDash(value);
            const span = document.createElement('span');
            span.textContent = label;
            const small = document.createElement('small');
            small.textContent = caption;
            item.append(strong, span, small);
            els.metrics.appendChild(item);
        });
    }

    function renderWarnings(checks) {
        els.warnings.innerHTML = '';
        const items = checks
            .filter((check) => check.status !== 'ok' || check.fix)
            .map((check) => `${check.name}: ${check.fix || check.detail || check.status}`);

        if (items.length === 0) {
            const ok = document.createElement('div');
            ok.className = 'warning-item is-ok';
            ok.textContent = 'No health warnings.';
            els.warnings.appendChild(ok);
            return;
        }

        items.forEach((warning) => {
            const item = document.createElement('div');
            item.className = 'warning-item';
            item.textContent = warning;
            els.warnings.appendChild(item);
        });
    }

    function renderQueues(checks) {
        setText(els.queueCount, `${checks.length} check${checks.length === 1 ? '' : 's'}`);
        renderTable(
            els.queues,
            ['Service', 'Status', 'Detail', 'Fix'],
            checks,
            (check) => [
                check.name,
                statusPill(check.status),
                check.detail,
                check.fix,
            ],
        );
    }

    function renderRetryQueues(checks) {
        const rows = checks
            .filter((check) => check.fix || check.status !== 'ok')
            .map((check) => ({
                service: check.name,
                status: check.status,
                note: check.fix || check.detail,
            }));
        setText(els.retryCount, `${rows.length} note${rows.length === 1 ? '' : 's'}`);

        renderTable(
            els.retryQueues,
            ['Service', 'Status', 'Note'],
            rows,
            (row) => [
                row.service,
                statusPill(row.status),
                row.note,
            ],
        );
    }

    function renderTable(container, headers, rows, mapper) {
        container.innerHTML = '';
        if (!rows.length) {
            renderEmpty(container, 'Nothing recorded yet.');
            return;
        }

        const table = document.createElement('table');
        table.className = 'data-table';
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        headers.forEach((header) => {
            const th = document.createElement('th');
            th.textContent = header;
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((row) => {
            const bodyRow = document.createElement('tr');
            mapper(row).forEach((value) => {
                const td = document.createElement('td');
                if (value instanceof HTMLElement) {
                    td.appendChild(value);
                } else {
                    td.textContent = valueOrDash(value);
                }
                bodyRow.appendChild(td);
            });
            tbody.appendChild(bodyRow);
        });
        table.appendChild(tbody);
        container.appendChild(table);
    }

    function renderEmpty(container, message) {
        const empty = document.createElement('div');
        empty.className = 'empty-state';
        empty.textContent = message;
        container.appendChild(empty);
    }

    async function loadQueues() {
        setStatus('Loading ingestion health...');
        const data = await requestJson('api/pipeline/health');
        renderMonitor(data || {});
    }

    els.refresh?.addEventListener('click', async () => {
        try {
            await loadQueues();
        } catch (error) {
            setStatus(error.message || 'Could not refresh ingestion health.', 'error');
        }
    });

    loadQueues().catch((error) => {
        setStatus(error.message || 'Could not load ingestion health.', 'error');
    });
}
