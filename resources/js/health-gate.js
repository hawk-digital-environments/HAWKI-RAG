import '../css/health-gate.css';
import { mount } from 'svelte';
import SystemTroubleshooter from './svelte/apps/SystemTroubleshooter.svelte';
import { apiUrl, pageUrl } from './playground/urls.js';

const POLL_INTERVAL_MS = 10000;
const BLOCKED_CLASS = 'hawki-health-gate-blocked';

let overlay = null;
let list = null;
let summary = null;
let checkedAt = null;
let actions = null;
let refreshButton = null;
let title = null;
let timer = null;
let busy = false;
let troubleshooter = null;

function createOverlay() {
    if (overlay) return overlay;

    overlay = document.createElement('section');
    overlay.className = 'hawki-health-gate';
    overlay.hidden = true;
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'hawki-health-gate-title');
    overlay.innerHTML = `
        <div class="hawki-health-gate-shell">
            <div class="hawki-health-gate-core" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>
            <div class="hawki-health-gate-copy">
                <p class="hawki-health-gate-kicker">HAWKI-RAG Health/Repair</p>
                <h2 id="hawki-health-gate-title">Checking system health</h2>
                <p class="hawki-health-gate-summary">Checking core services...</p>
            </div>
            <div class="hawki-health-gate-checks"></div>
            <div class="hawki-health-gate-actions"></div>
            <div class="hawki-health-gate-footer">
                <span class="hawki-health-gate-checked">Waiting for first check.</span>
                <button type="button" class="hawki-health-gate-refresh">Refresh checks</button>
            </div>
        </div>
    `;

    summary = overlay.querySelector('.hawki-health-gate-summary');
    title = overlay.querySelector('#hawki-health-gate-title');
    list = overlay.querySelector('.hawki-health-gate-checks');
    actions = overlay.querySelector('.hawki-health-gate-actions');
    checkedAt = overlay.querySelector('.hawki-health-gate-checked');
    refreshButton = overlay.querySelector('.hawki-health-gate-refresh');
    refreshButton?.addEventListener('click', () => refreshGate(true));

    document.body.appendChild(overlay);

    return overlay;
}

function mountTroubleshooter() {
    if (troubleshooter) return;

    const target = document.createElement('div');
    target.className = 'hawki-troubleshooter-root';
    document.body.appendChild(target);

    troubleshooter = mount(SystemTroubleshooter, {
        target,
        props: {
            endpoint: apiUrl('health/system-gate?timeout=3'),
            resolvePageUrl: (href) => pageUrl(href || '/pipeline-health'),
        },
    });
}

function setBlocked(blocked) {
    document.body.classList.toggle(BLOCKED_CLASS, blocked);
    if (overlay) overlay.hidden = !blocked;
}

function statusLabel(status) {
    if (status === 'ok') return 'Ready';
    if (status === 'fail') return 'Repair';
    return status || 'Unknown';
}

function renderActions(items = []) {
    if (!actions) return;
    actions.innerHTML = '';

    items.forEach((item) => {
        const link = document.createElement('a');
        link.href = pageUrl(item.href || '/pipeline-health');
        link.className = 'hawki-health-gate-action';
        link.textContent = item.label || 'Open repair view';
        actions.appendChild(link);
    });
}

function renderChecks(checks = []) {
    if (!list) return;
    list.innerHTML = '';

    checks.forEach((check) => {
        const row = document.createElement('article');
        row.className = 'hawki-health-gate-check';
        row.dataset.status = check.status || 'fail';

        const head = document.createElement('div');
        head.className = 'hawki-health-gate-check-head';

        const title = document.createElement('strong');
        title.textContent = check.title || check.key || 'Core service';

        const status = document.createElement('span');
        status.className = 'hawki-health-gate-status';
        status.textContent = statusLabel(check.status);

        const detail = document.createElement('p');
        detail.textContent = check.detail || 'No detail available.';

        head.append(title, status);
        row.append(head, detail);

        if (check.fix) {
            const fix = document.createElement('p');
            fix.className = 'hawki-health-gate-fix';
            fix.textContent = check.fix;
            row.appendChild(fix);
        }

        list.appendChild(row);
    });
}

function renderPayload(payload) {
    createOverlay();

    if (!payload?.enforce || payload.status !== 'blocked') {
        setBlocked(false);
        return;
    }

    const blocked = Array.isArray(payload.blocking) ? payload.blocking : [];
    const count = blocked.length;
    if (title) title.textContent = 'System repair required';
    if (summary) {
        summary.textContent = count === 1
            ? 'One required HAWKI-RAG service is not smooth yet. Repair it before continuing.'
            : `${count} required HAWKI-RAG services are not smooth yet. Repair them before continuing.`;
    }

    renderChecks(payload.checks || []);
    renderActions(payload.repairActions || []);

    if (checkedAt) {
        checkedAt.textContent = payload.checkedAt
            ? `Last check: ${new Date(payload.checkedAt).toLocaleString()}`
            : 'Last check finished.';
    }

    setBlocked(true);
}

function renderFailure(error) {
    createOverlay();
    setBlocked(true);

    if (title) title.textContent = 'Health/Repair unavailable';
    if (summary) {
        summary.textContent = 'The Health/Repair gate itself is unavailable. Repair Laravel routing or the app container first.';
    }

    renderChecks([{
        title: 'HAWKI-RAG Health/Repair Gate',
        status: 'fail',
        detail: error?.message || 'System gate request failed.',
        fix: 'Run health checks from the terminal and inspect Laravel logs.',
    }]);
    renderActions([{ label: 'Open Pipeline Health', href: '/pipeline-health' }]);

    if (checkedAt) checkedAt.textContent = 'Gate check failed.';
}

async function refreshGate(manual = false) {
    if (busy) return;
    busy = true;
    if (manual && refreshButton) refreshButton.disabled = true;

    try {
        const response = await fetch(apiUrl('health/system-gate?timeout=3'), {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.success === false) {
            throw new Error(payload.message || `System gate failed (${response.status})`);
        }
        renderPayload(payload);
    } catch (error) {
        renderFailure(error);
    } finally {
        busy = false;
        if (refreshButton) refreshButton.disabled = false;
    }
}

function boot() {
    if (!document.body || document.body.dataset.hawkiHealthGate === 'off') return;

    createOverlay();
    mountTroubleshooter();
    setBlocked(false);
    refreshGate();
    timer = window.setInterval(refreshGate, POLL_INTERVAL_MS);
    window.addEventListener('beforeunload', () => {
        if (timer) window.clearInterval(timer);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
