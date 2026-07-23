<!--
  @component Svelte-owned shell for the pipeline health dashboard.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Whether the current browser request is allowed to use operator health APIs. */
        operatorAuthorized?: boolean;
        /** Called once the health dashboard DOM is available for the current runtime. */
        onready?: () => void;
    }

    const {operatorAuthorized = false, onready, class: className = '', ...restProps}: Props = $props();

    onMount(() => {
        if (!operatorAuthorized) {
            return;
        }

        onready?.();
    });

</script>

<main {...restProps} class={['pipeline-health-dashboard', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG health"
        title="Pipeline Health"
        copy="Check Temporal, PostgreSQL, adapters, shared storage, Qdrant, and Neo4j from Laravel."
        active="health"
    />

    {#if operatorAuthorized}
        <section class="health-status" id="pipeline-health-status">Loading ingestion health...</section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Health summary</h2>
                    <p id="pipeline-health-updated">No health status loaded.</p>
                </div>
                <span class="status-pill" id="pipeline-health-state">loading</span>
            </div>
            <div class="metric-grid" id="pipeline-health-metrics"></div>
            <div class="warning-list" id="pipeline-health-warnings"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Worker queues</h2>
                    <p id="pipeline-health-queue-count">0 checks</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-queues"></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Retry queues</h2>
                    <p id="pipeline-health-retry-count">0 notes</p>
                </div>
            </div>
            <div class="table-wrap" id="pipeline-health-retry-queues"></div>
        </section>
    {:else}
        <section class="health-auth-panel" aria-labelledby="health-auth-required-title">
            <span class="health-auth-kicker">Operator access required</span>
            <h2 id="health-auth-required-title">Detailed health checks are locked.</h2>
            <p>Sign in with an operator account or enable the explicit local bypass before loading service, worker, and queue diagnostics.</p>
        </section>
    {/if}
</main>

<style>
    .health-auth-panel {
        display: grid;
        gap: 10px;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 12px;
        padding: 20px;
        background: rgba(15, 23, 42, 0.72);
        color: #e2e8f0;
        box-shadow: 0 18px 48px rgba(15, 23, 42, 0.2);
    }

    .health-auth-panel h2,
    .health-auth-panel p {
        margin: 0;
    }

    .health-auth-kicker {
        color: #7dd3fc;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
</style>
