<!--
  @component Svelte-owned shell for the dataset and document browser dashboard.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Called once the static dashboard DOM is available for the current data browser runtime. */
        onready?: () => void;
    }

    const {onready, class: className = '', ...restProps}: Props = $props();

    onMount(() => {
        onready?.();
    });

</script>

<main {...restProps} class={['datasets-dashboard', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG data"
        title="Data Browser"
        copy="Dataset-scoped documents, processing status, and graph storage."
        active="datasets"
    />

    <div class="dashboard-grid">
        <aside class="dataset-sidebar" aria-label="Datasets">
            <div class="section-head">
                <div>
                    <h2>Dataset list</h2>
                    <p id="datasets-count">Loading datasets...</p>
                </div>
            </div>
            <div class="dataset-list" id="datasets-list" aria-live="polite"></div>
        </aside>

        <section class="dataset-detail" aria-live="polite">
            <div class="detail-status" id="datasets-status">Loading datasets...</div>

            <section class="panel overview-panel">
                <div class="section-head">
                    <div>
                        <h2>Selected data pool</h2>
                        <p id="datasets-updated">No dataset loaded.</p>
                    </div>
                </div>
                <div class="overview-grid">
                    <div class="overview-block">
                        <h3>Dataset</h3>
                        <dl class="dataset-info-grid compact-info-grid" id="datasets-info"></dl>
                    </div>
                    <div class="overview-block">
                        <h3>Retrieval evidence</h3>
                        <div class="metric-grid compact-metric-grid" id="datasets-metrics"></div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Documents</h2>
                        <p id="datasets-document-count">0 documents</p>
                    </div>
                    <form class="document-search" id="datasets-document-search-form">
                        <input id="datasets-document-search" type="search" placeholder="Search documents" autocomplete="off" />
                        <button type="submit" class="secondary-button">Search</button>
                    </form>
                </div>
                <div class="table-wrap" id="datasets-documents"></div>
            </section>

        </section>
    </div>
</main>
