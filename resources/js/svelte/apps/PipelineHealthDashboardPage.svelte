<!--
  @component Svelte-owned shell for the pipeline health dashboard.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Called once the health dashboard DOM is available for the current runtime. */
        onready?: () => void;
    }

    const {onready, class: className = '', ...restProps}: Props = $props();

    onMount(() => {
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
</main>
