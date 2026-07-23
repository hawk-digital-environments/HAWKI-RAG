<!--
  @component Global HAWKI-RAG troubleshooting panel that checks system health states.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import type {HealthCheck, HealthRepairAction, SystemGatePayload} from '../types/health.js';

    const DIAGNOSTIC_STEP_DELAY_MS = 180;

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Absolute URL for the system-gate diagnostic endpoint. */
        endpoint: string;
        /** Converts backend repair-action paths into browser URLs. */
        resolvePageUrl?: (path: string) => string;
    }

    const {
        endpoint,
        resolvePageUrl = (path: string): string => path,
        ...restProps
    }: Props = $props();

    let panelOpen = $state(false);
    let busy = $state(false);
    let runId = $state(0);
    let progress = $state(0);
    let progressLabel = $state('Idle');
    let summary = $state('Ready to check system states.');
    let checks = $state<HealthCheck[]>([]);
    let repairActions = $state<HealthRepairAction[]>([]);

    const progressText = $derived(`${Math.round(progress)}%`);

    function isCurrentPage(path: string): boolean {
        const target = new URL(resolvePageUrl(path), window.location.origin);

        return window.location.pathname === target.pathname;
    }

    function delay(ms: number): Promise<void> {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function diagnosticStatus(check: HealthCheck): string {
        if (check.status === 'ok') return 'ok';
        if (check.status === 'fail' && check.required === false) return 'optional';
        if (check.status === 'fail') return 'fail';
        if (check.status === 'checking') return 'checking';
        return 'unknown';
    }

    function diagnosticLabel(check: HealthCheck): string {
        const status = diagnosticStatus(check);
        if (status === 'ok') return 'Ready';
        if (status === 'optional') return 'Not required';
        if (status === 'fail') return 'Repair';
        if (status === 'checking') return 'Checking';
        return 'Unknown';
    }

    function setProgress(value: number, label: string): void {
        progress = Math.max(0, Math.min(100, Math.round(value)));
        progressLabel = label;
    }

    function pageHref(item: HealthRepairAction): string {
        return resolvePageUrl(item.href || '/pipeline-health');
    }

    function summaryText(payload: SystemGatePayload): string {
        const currentChecks = payload.checks || [];
        const required = currentChecks.filter((check) => check.required !== false);
        const blocking = payload.blocking || [];
        const readyCount = currentChecks.filter((check) => check.status === 'ok').length;

        if (payload.status === 'ready') {
            return `${readyCount}/${currentChecks.length} states are smooth. Required services are ready.`;
        }

        if (blocking.length > 0) {
            return `${blocking.length}/${required.length} required states need repair.`;
        }

        return `${readyCount}/${currentChecks.length} states checked.`;
    }

    async function animateChecks(nextChecks: HealthCheck[], currentRunId: number): Promise<void> {
        const total = Math.max(1, nextChecks.length);
        checks = [];

        for (let index = 0; index < nextChecks.length; index += 1) {
            if (currentRunId !== runId) return;

            const check = nextChecks[index];
            setProgress((index / total) * 100, `Checking ${check.title || check.key || 'system state'}`);
            checks = [
                ...checks,
                {
                    ...check,
                    status: 'checking',
                    detail: 'Checking this state now.',
                },
            ];

            await delay(DIAGNOSTIC_STEP_DELAY_MS);
            if (currentRunId !== runId) return;

            checks = checks.map((item, itemIndex) => (itemIndex === index ? check : item));
            setProgress(((index + 1) / total) * 100, `${index + 1}/${nextChecks.length} states checked`);
        }
    }

    async function runTroubleshooter(): Promise<void> {
        if (busy) return;

        const currentRunId = runId + 1;
        runId = currentRunId;
        busy = true;
        summary = 'Checking HAWKI-RAG system states...';
        checks = [];
        repairActions = [];
        setProgress(8, 'Contacting Health/Repair');

        try {
            const response = await fetch(endpoint, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
            });
            const payload = (await response.json().catch(() => ({}))) as SystemGatePayload;
            if (response.status === 401 || response.status === 403) {
                summary = 'Admin access is required to run detailed health diagnostics.';
                checks = [];
                repairActions = [];
                setProgress(100, 'Authorization required');
                return;
            }
            if (!response.ok || payload.success === false) {
                throw new Error(payload.message || `System gate failed (${response.status})`);
            }

            const nextChecks = Array.isArray(payload.checks) ? payload.checks : [];
            await animateChecks(nextChecks, currentRunId);
            if (currentRunId !== runId) return;

            summary = summaryText(payload);
            repairActions = payload.repairActions || [];
            setProgress(100, payload.status === 'ready' ? 'Ready' : 'Finished with repair notes');
        } catch (error) {
            if (currentRunId !== runId) return;

            summary = 'Troubleshoot could not complete.';
            checks = [{
                title: 'HAWKI-RAG Health/Repair Gate',
                status: 'fail',
                required: true,
                detail: error instanceof Error ? error.message : 'System gate request failed.',
                fix: 'Open Pipeline Health and inspect Laravel or container logs.',
            }];
            repairActions = [{label: 'Open Pipeline Health', href: '/pipeline-health', kind: 'health'}];
            setProgress(100, 'Failed');
        } finally {
            if (currentRunId === runId) {
                busy = false;
            }
        }
    }

    function openPanel(): void {
        panelOpen = true;
        void runTroubleshooter();
    }

    function closePanel(): void {
        panelOpen = false;
    }
</script>

<div {...restProps}>
    <div class="launcherBar">
        <a
            class="launcherControl"
            href={resolvePageUrl('/admin')}
            aria-label="Home"
            aria-current={isCurrentPage('/admin') ? 'page' : undefined}
            title="Home"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3.75 11.4 12 4.25l8.25 7.15" />
                <path d="M5.75 10.2v8.05a1.5 1.5 0 0 0 1.5 1.5h9.5a1.5 1.5 0 0 0 1.5-1.5V10.2" />
                <path d="M9.75 19.75v-5.5h4.5v5.5" />
            </svg>
            <span class="srOnly">Home</span>
        </a>
        <a
            class="launcherControl"
            href={resolvePageUrl('/settings')}
            aria-label="Settings"
            aria-current={isCurrentPage('/settings') ? 'page' : undefined}
            title="Settings"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 8.15a3.85 3.85 0 1 1 0 7.7 3.85 3.85 0 0 1 0-7.7Z" />
                <path d="M19.15 12.95a7.84 7.84 0 0 0 .04-.95 7.84 7.84 0 0 0-.04-.95l2.02-1.58-1.92-3.32-2.38.96a7.46 7.46 0 0 0-1.64-.95L14.88 3.6h-3.76l-.35 2.56a7.46 7.46 0 0 0-1.64.95l-2.38-.96-1.92 3.32 2.02 1.58a7.84 7.84 0 0 0-.04.95c0 .32.01.64.04.95l-2.02 1.58 1.92 3.32 2.38-.96c.5.39 1.05.71 1.64.95l.35 2.56h3.76l.35-2.56a7.46 7.46 0 0 0 1.64-.95l2.38.96 1.92-3.32-2.02-1.58Z" />
            </svg>
            <span class="srOnly">Settings</span>
        </a>
        <button
            type="button"
            class="launcherControl"
            aria-label="Troubleshoot"
            aria-controls="hawki-troubleshooter-panel"
            aria-expanded={panelOpen}
            onclick={openPanel}
            title="Troubleshoot"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 3.75 4.25 7.15v5.7c0 4.1 3.05 6.8 7.75 7.4 4.7-.6 7.75-3.3 7.75-7.4v-5.7L12 3.75Z" />
                <path d="M12 8.15v4.4" />
                <path d="M12 16.15h.01" />
            </svg>
            <span class="srOnly">Troubleshoot</span>
        </button>
    </div>

    {#if panelOpen}
        <div
            id="hawki-troubleshooter-panel"
            class="panel"
            role="dialog"
            aria-modal="false"
            aria-labelledby="hawki-troubleshooter-title"
        >
            <div class="head">
                <div>
                    <p class="kicker">HAWKI-RAG Diagnostics</p>
                    <h2 id="hawki-troubleshooter-title">Troubleshoot</h2>
                </div>
                <button type="button" class="close" onclick={closePanel}>Close</button>
            </div>

            <p class="summary">{summary}</p>

            <div
                class="progress"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow={Math.round(progress)}
            >
                <span style={`width: ${progress}%`}></span>
            </div>
            <div class="progressMeta">
                <span>{progressLabel}</span>
                <span>{progressText}</span>
            </div>

            <div class="states">
                {#each checks as check, index (`${check.key || check.title || 'state'}-${index}`)}
                    {@const status = diagnosticStatus(check)}
                    <article class="state" data-status={status}>
                        <div class="stateHead">
                            <strong>{check.title || check.key || 'System state'}</strong>
                            <span class="stateStatus">{diagnosticLabel(check)}</span>
                        </div>
                        <p>{check.detail || 'No detail available.'}</p>
                        {#if check.required === false}
                            <p class="note">Optional for the current local experience.</p>
                        {/if}
                        {#if check.fix && status === 'fail'}
                            <p class="fix">{check.fix}</p>
                        {/if}
                    </article>
                {/each}
            </div>

            <div class="actions">
                <button type="button" class="run" disabled={busy} onclick={runTroubleshooter}>Run checks</button>
                {#each repairActions as item, index (`${item.href || item.label || 'action'}-${index}`)}
                    <a class="action" href={pageHref(item)}>{item.label || 'Open repair view'}</a>
                {/each}
            </div>
        </div>
    {/if}
</div>

<style>
    .launcherBar {
        --launcher-size: 40px;
        --launcher-icon-size: 18px;
        --launcher-radius: 999px;

        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 100000;
        display: grid;
        grid-template-columns: repeat(3, var(--launcher-size));
        align-items: center;
        gap: 0;
        padding: 4px;
        border: 1px solid rgba(125, 211, 252, 0.3);
        border-radius: var(--launcher-radius);
        background:
            linear-gradient(145deg, rgba(8, 18, 32, 0.96), rgba(15, 23, 42, 0.9)),
            rgba(8, 18, 32, 0.92);
        box-shadow: 0 14px 38px rgba(2, 8, 23, 0.34), 0 0 24px rgba(14, 165, 233, 0.14);
        backdrop-filter: blur(14px);
    }

    .launcherControl {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: var(--launcher-size);
        height: var(--launcher-size);
        min-width: var(--launcher-size);
        min-height: var(--launcher-size);
        border: 0;
        border-radius: var(--launcher-radius);
        padding: 0;
        background: transparent;
        color: var(--hawki-trouble-text-strong);
        cursor: pointer;
        font: inherit;
        text-decoration: none;
        transition:
            background 160ms ease,
            color 160ms ease,
            box-shadow 160ms ease;
    }

    .launcherControl + .launcherControl {
        box-shadow: inset 1px 0 0 rgba(148, 163, 184, 0.12);
    }

    .launcherControl:hover,
    .launcherControl:focus-visible,
    .launcherControl[aria-current="page"] {
        background: rgba(14, 165, 233, 0.18);
        color: #e0f2fe;
        box-shadow: inset 0 0 0 1px rgba(125, 211, 252, 0.42);
        outline: none;
    }

    .launcherControl[aria-expanded="true"] {
        background: rgba(6, 95, 70, 0.36);
        color: #d1fae5;
        box-shadow: inset 0 0 0 1px rgba(52, 211, 153, 0.5);
    }

    .launcherControl svg {
        width: var(--launcher-icon-size);
        height: var(--launcher-icon-size);
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
        vector-effect: non-scaling-stroke;
        fill: none;
    }

    .srOnly {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
    }

    .panel {
        position: fixed;
        right: 18px;
        bottom: 86px;
        z-index: 100001;
        width: min(480px, calc(100vw - 28px));
        max-height: min(760px, calc(100vh - 104px));
        overflow: auto;
        border: 1px solid var(--hawki-trouble-border-strong);
        border-radius: 8px;
        padding: 18px;
        background: var(--hawki-trouble-surface);
        color: var(--hawki-trouble-text);
        box-shadow: var(--hawki-trouble-panel-shadow);
    }

    .head,
    .stateHead,
    .progressMeta,
    .actions {
        display: flex;
        gap: 12px;
    }

    .head,
    .stateHead,
    .progressMeta {
        align-items: center;
        justify-content: space-between;
    }

    .head {
        align-items: flex-start;
        gap: 14px;
    }

    .kicker {
        margin: 0 0 6px;
        color: var(--hawki-trouble-accent);
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    h2 {
        margin: 0;
        color: var(--hawki-trouble-text-strong);
        font-size: 1.35rem;
        line-height: 1.1;
        letter-spacing: 0;
    }

    .close,
    .run,
    .action {
        border: 1px solid var(--hawki-trouble-border);
        border-radius: 8px;
        padding: 9px 12px;
        color: var(--hawki-trouble-text-strong);
        cursor: pointer;
        font: inherit;
        font-size: 0.84rem;
        font-weight: 800;
        text-decoration: none;
    }

    .close,
    .action {
        background: var(--hawki-trouble-control);
    }

    .summary {
        margin: 14px 0 16px;
        color: var(--hawki-trouble-text-soft);
        font-size: 0.95rem;
        line-height: 1.45;
    }

    .progress {
        height: 12px;
        overflow: hidden;
        border-radius: 999px;
        background: var(--hawki-trouble-track);
        box-shadow: inset 0 0 0 1px var(--hawki-trouble-border);
    }

    .progress span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: var(--hawki-trouble-progress);
        transition: width 180ms ease;
    }

    .progressMeta {
        margin-top: 8px;
        color: var(--hawki-trouble-progress-text);
        font-size: 0.82rem;
        font-weight: 700;
    }

    .states {
        display: grid;
        gap: 10px;
        margin-top: 16px;
    }

    .state {
        border: 1px solid var(--state-border, var(--hawki-trouble-border));
        border-radius: 8px;
        padding: 12px;
        background: var(--state-surface, var(--hawki-trouble-state-surface));
    }

    .state[data-status="checking"] {
        --state-border: var(--hawki-trouble-checking-border);
    }

    .state[data-status="ok"] {
        --state-border: var(--hawki-trouble-ok-border);
        --state-status-bg: var(--hawki-trouble-ok-bg);
        --state-status-text: var(--hawki-trouble-ok-text);
    }

    .state[data-status="optional"] {
        --state-border: var(--hawki-trouble-optional-border);
        --state-status-bg: var(--hawki-trouble-optional-bg);
        --state-status-text: var(--hawki-trouble-optional-text);
    }

    .state[data-status="fail"] {
        --state-border: var(--hawki-trouble-fail-border);
        --state-surface: var(--hawki-trouble-fail-surface);
        --state-status-bg: var(--hawki-trouble-fail-bg);
        --state-status-text: var(--hawki-trouble-fail-text);
    }

    .state strong {
        min-width: 0;
        color: var(--hawki-trouble-text-strong);
        font-size: 0.92rem;
    }

    .stateStatus {
        flex: 0 0 auto;
        border-radius: 999px;
        padding: 4px 9px;
        background: var(--state-status-bg, var(--hawki-trouble-checking-bg));
        color: var(--state-status-text, var(--hawki-trouble-checking-text));
        font-size: 0.72rem;
        font-weight: 800;
    }

    .state p {
        margin: 8px 0 0;
        color: var(--hawki-trouble-muted);
        font-size: 0.86rem;
        line-height: 1.42;
    }

    .note {
        color: var(--hawki-trouble-optional-text);
    }

    .fix {
        color: var(--hawki-trouble-fail-text);
    }

    .actions {
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    .run {
        border-color: var(--hawki-trouble-run-border);
        background: var(--hawki-trouble-run);
    }

    .run:disabled {
        cursor: wait;
        opacity: 0.65;
    }

    @media (max-width: 720px) {
        .stateHead {
            align-items: flex-start;
            flex-direction: column;
        }

        .launcherBar {
            right: 14px;
            bottom: 14px;
        }

        .panel {
            right: 14px;
            bottom: 82px;
        }
    }
</style>
