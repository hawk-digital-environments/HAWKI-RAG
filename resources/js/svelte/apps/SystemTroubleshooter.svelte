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
        <a class="homeLink" href={resolvePageUrl('/admin')}>Home</a>
        <button
            type="button"
            class="launcher"
            aria-controls="hawki-troubleshooter-panel"
            aria-expanded={panelOpen}
            onclick={openPanel}
        >
            Troubleshoot
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
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 100000;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .homeLink,
    .launcher {
        min-height: 44px;
        border: 1px solid var(--hawki-trouble-interactive-border);
        border-radius: 8px;
        padding: 0 16px;
        background: var(--hawki-trouble-interactive);
        color: var(--hawki-trouble-text-strong);
        box-shadow: var(--hawki-trouble-shadow);
        cursor: pointer;
        font: inherit;
        font-size: 0.9rem;
        font-weight: 800;
        letter-spacing: 0;
        text-decoration: none;
    }

    .homeLink {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--hawki-trouble-control);
    }

    .panel {
        position: fixed;
        right: 18px;
        bottom: 76px;
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
            bottom: 70px;
        }
    }
</style>
