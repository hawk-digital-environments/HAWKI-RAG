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

    function renderMonitor(monitor) {
        els.state.className = `status-pill ${statusClass(monitor.status)}`;
        setText(els.state, monitor.status || 'unknown');
        setText(els.updated, `Checked ${formatDate(monitor.checkedAt)} from ${monitor.managementUrl || 'RabbitMQ management API'}.`);
        setStatus(monitor.message || 'Queue status loaded.', monitor.status === 'ok' ? 'success' : monitor.status === 'fail' ? 'error' : 'warning');

        renderMetrics(monitor.totals || {});
        renderWarnings(monitor.warnings || [], monitor.error || monitor.fix || '');
        renderQueues(monitor.workers || []);
        renderRetryQueues(monitor.workers || [], monitor.failedQueue || {});
    }

    function renderMetrics(totals) {
        els.metrics.innerHTML = '';
        [
            ['Ready messages', totals.readyMessages ?? 0, 'Waiting in primary worker queues'],
            ['Unacked messages', totals.unackedMessages ?? 0, 'Reserved by workers'],
            ['Consumers', totals.consumers ?? 0, 'Attached pipeline workers'],
            ['Retry messages', totals.retryQueueCount ?? 0, 'Waiting in retry queues'],
            ['Failed messages', totals.failedQueueCount ?? 0, 'Waiting in failed queue'],
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

    function renderWarnings(warnings, fallback) {
        els.warnings.innerHTML = '';
        const items = warnings.length > 0 ? warnings : [];
        if (items.length === 0 && !fallback) {
            const ok = document.createElement('div');
            ok.className = 'warning-item is-ok';
            ok.textContent = 'No queue warnings.';
            els.warnings.appendChild(ok);
            return;
        }

        if (fallback && items.length === 0) {
            items.push(fallback);
        }

        items.forEach((warning) => {
            const item = document.createElement('div');
            item.className = 'warning-item';
            item.textContent = warning;
            els.warnings.appendChild(item);
        });
    }

    function renderQueues(workers) {
        setText(els.queueCount, `${workers.length} worker queue${workers.length === 1 ? '' : 's'}`);
        renderTable(
            els.queues,
            ['Worker', 'Queue name', 'Ready', 'Unacked', 'Consumers', 'Retry count', 'Status', 'Warning'],
            workers,
            (worker) => [
                worker.worker,
                worker.queueName,
                worker.readyMessages,
                worker.unackedMessages,
                worker.consumers,
                worker.retryQueueCount,
                statusPill(worker.status),
                (worker.warnings || []).join(' '),
            ],
        );
    }

    function renderRetryQueues(workers, failedQueue) {
        const rows = [];
        workers.forEach((worker) => {
            (worker.retryQueues || []).forEach((queue) => {
                rows.push({
                    worker: worker.worker,
                    type: 'retry',
                    name: queue.name,
                    readyMessages: queue.readyMessages,
                    unackedMessages: queue.unackedMessages,
                    consumers: queue.consumers,
                    state: queue.state,
                    exists: queue.exists,
                });
            });
        });
        rows.push({
            worker: 'all',
            type: 'failed',
            name: failedQueue.name,
            readyMessages: failedQueue.readyMessages,
            unackedMessages: failedQueue.unackedMessages,
            consumers: failedQueue.consumers,
            state: failedQueue.state,
            exists: failedQueue.exists,
        });

        const retryMessages = workers.reduce((sum, worker) => sum + Number(worker.retryQueueCount || 0), 0);
        setText(els.retryCount, `${retryMessages} retry message${retryMessages === 1 ? '' : 's'}; failed queue ${Number(failedQueue.readyMessages || 0) + Number(failedQueue.unackedMessages || 0)} message${Number(failedQueue.readyMessages || 0) + Number(failedQueue.unackedMessages || 0) === 1 ? '' : 's'}`);

        renderTable(
            els.retryQueues,
            ['Type', 'Worker', 'Queue name', 'Ready', 'Unacked', 'Consumers', 'State'],
            rows,
            (queue) => [
                queue.type,
                queue.worker,
                queue.name,
                queue.readyMessages,
                queue.unackedMessages,
                queue.consumers,
                queue.exists ? queue.state : 'missing',
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
        setStatus('Loading RabbitMQ queue status...');
        const data = await requestJson('api/pipeline/health/queues');
        renderMonitor(data.queueMonitor || {});
    }

    els.refresh?.addEventListener('click', async () => {
        try {
            await loadQueues();
        } catch (error) {
            setStatus(error.message || 'Could not refresh queue status.', 'error');
        }
    });

    loadQueues().catch((error) => {
        setStatus(error.message || 'Could not load queue status.', 'error');
    });
}
