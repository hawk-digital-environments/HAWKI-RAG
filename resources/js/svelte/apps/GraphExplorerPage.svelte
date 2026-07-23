<!--
  @component Full-page Svelte shell for the HAWKI-RAG Neo4j graph workspace.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Whether the current browser request is allowed to use admin APIs. */
        adminAuthorized?: boolean;
        /** Opens and lazy-loads the Cytoscape graph engine. */
        onopenTechnicalGraph?: () => void;
    }

    const {
        adminAuthorized = false,
        onopenTechnicalGraph,
        class: className = '',
        ...restProps
    }: Props = $props();

    onMount(() => {
        if (!adminAuthorized) {
            return;
        }

        onopenTechnicalGraph?.();
    });
</script>

<div {...restProps} class={['graph-page-shell', 'graph-dashboard', 'graph-page', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG graph"
        title="Neo4j Graph Explorer"
        copy="Search entities, expand paths, and inspect graph evidence from Neo4j."
        active="graph"
    />

    {#if adminAuthorized}
    <section class="graph-visualization-section" aria-labelledby="graph-workspace-heading">
        <div class="graph-visualization-header">
            <div>
                <h2 id="graph-workspace-heading">Graph Workspace</h2>
                <p>Neo4j inspection surface.</p>
            </div>
            <div id="neo4j-graph-meta" class="badge">Open graph...</div>
        </div>

        <div class="graph-explorer-shell">
            <aside class="graph-toolbar" aria-label="Graph controls">
                <div class="graph-control-group">
                    <label for="graph-search-input">Entity search</label>
                    <input id="graph-search-input" type="search" placeholder="Search entities..." autocomplete="off" />
                    <div id="graph-search-results" class="graph-results"></div>
                </div>

                <div class="graph-control-group">
                    <label for="graph-semantic-input">Semantic search</label>
                    <input id="graph-semantic-input" type="search" placeholder="Ask for a concept..." autocomplete="off" />
                    <div id="graph-semantic-results" class="graph-results"></div>
                </div>

                <div class="graph-control-grid">
                    <div>
                        <label for="graph-layout-select">Layout</label>
                        <select id="graph-layout-select">
                            <option value="elk" selected>ELK layered</option>
                            <option value="cose-bilkent">CoSE Bilkent</option>
                        </select>
                    </div>
                    <div>
                        <label for="graph-grouping-select">Grouping</label>
                        <select id="graph-grouping-select">
                            <option value="none" selected>None</option>
                            <option value="type">Entity type</option>
                            <option value="source">Source document</option>
                            <option value="community">Community</option>
                        </select>
                    </div>
                    <div>
                        <label for="graph-depth-select">Depth</label>
                        <select id="graph-depth-select">
                            <option value="1" selected>1 hop</option>
                            <option value="2">2 hops</option>
                            <option value="3">3 hops</option>
                        </select>
                    </div>
                </div>

                <div class="graph-actions">
                    <button type="button" id="graph-overview-btn">Overview</button>
                    <button type="button" id="graph-relayout-btn">Layout</button>
                    <button type="button" id="graph-clear-view-btn">Clear view</button>
                </div>

                <div class="graph-control-group">
                    <label for="graph-snapshot-load">Snapshots</label>
                    <div class="graph-snapshot-row">
                        <select id="graph-snapshot-load">
                            <option value="">Load snapshot...</option>
                        </select>
                        <button type="button" id="graph-snapshot-save-btn">Save</button>
                        <button type="button" id="graph-snapshot-delete-btn">Delete</button>
                    </div>
                </div>

                <div id="graph-status" class="graph-status" role="status"></div>
            </aside>

            <main class="graph-stage">
                <div id="neo4j-graph-empty" class="graph-empty">Search for an entity or load the limited overview.</div>
                <div id="neo4j-graph-canvas" class="graph-canvas" role="img" aria-label="Neo4j graph visualization"></div>
            </main>

            <aside class="graph-detail" aria-label="Selected entity details">
                <h3>Entity Details</h3>
                <div id="graph-detail-panel">
                    <p class="muted">Select a node to inspect entity paths.</p>
                </div>
            </aside>
        </div>
    </section>
    {:else}
    <section class="graph-auth-panel" aria-labelledby="graph-auth-required-title">
        <span class="graph-auth-kicker">Admin access required</span>
        <h2 id="graph-auth-required-title">Graph controls are locked.</h2>
        <p>Sign in with an admin account or enable the explicit local bypass before searching entities, expanding neighborhoods, or saving graph snapshots.</p>
    </section>
    {/if}
</div>

<style>
    :global(body) {
        margin: 0;
        background: #07111f;
    }

    :global(*) {
        box-sizing: border-box;
    }

    .graph-page {
        --pg-bg: #07111f;
        --pg-bg-strong: #0b1320;
        --pg-surface: rgba(8, 18, 32, 0.88);
        --pg-surface-soft: rgba(15, 23, 42, 0.68);
        --pg-border: rgba(177, 195, 216, 0.2);
        --pg-border-strong: rgba(45, 212, 191, 0.5);
        --pg-text: #f8fafc;
        --pg-muted: #b6c7db;
        --pg-cyan: #22d3ee;
        --pg-green: #34d399;
        --pg-amber: #f4d35e;
        --pg-red: #fb7185;
        --pg-blue: #60a5fa;
        --pg-ink: #06111f;
        --pg-shadow: 0 26px 70px rgba(0, 0, 0, 0.34);

        min-height: 100vh;
        padding: clamp(14px, 2vw, 26px);
        color: var(--pg-text);
        font-family: Inter, ui-sans-serif, system-ui, sans-serif;
    }

    .graph-visualization-section {
        max-width: 1760px;
        margin: 0 auto;
    }

    .graph-auth-panel {
        display: grid;
        gap: 10px;
        max-width: 960px;
        margin: 0 auto;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 12px;
        padding: 20px;
        background: rgba(15, 23, 42, 0.72);
        color: #e2e8f0;
        box-shadow: 0 18px 48px rgba(15, 23, 42, 0.2);
    }

    .graph-auth-panel h2,
    .graph-auth-panel p {
        margin: 0;
    }

    .graph-auth-kicker {
        color: #7dd3fc;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

</style>
