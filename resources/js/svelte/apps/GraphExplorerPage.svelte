<!--
  @component Full-page Svelte shell for the HAWKI-RAG Neo4j graph explorer.
-->
<script lang="ts">
    import GraphWalkway from './GraphWalkway.svelte';
    import type {HTMLAttributes} from 'svelte/elements';

    interface NavItem {
        key: string;
        label: string;
        href: string;
    }

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Endpoint returning Neo4j graph overview nodes and edges. */
        overviewEndpoint: string;
        /** Endpoint for entity keyword search. */
        searchEndpoint: string;
        /** Endpoint for semantic graph search. */
        semanticSearchEndpoint: string;
        /** Endpoint for loading one node neighborhood. */
        nodeEndpoint: string;
        /** Navigation links for the operator graph/pipeline area. */
        navItems: NavItem[];
        /** Opens and lazy-loads the technical Cytoscape graph engine. */
        onopenTechnicalGraph?: () => void;
    }

    const {
        overviewEndpoint,
        searchEndpoint,
        semanticSearchEndpoint,
        nodeEndpoint,
        navItems,
        onopenTechnicalGraph,
        class: className = '',
        ...restProps
    }: Props = $props();

    function refreshPage(): void {
        window.location.reload();
    }

    function handleTechnicalToggle(event: Event): void {
        const panel = event.currentTarget;
        if (panel instanceof HTMLDetailsElement && panel.open) {
            onopenTechnicalGraph?.();
        }
    }
</script>

<div {...restProps} class={['graph-page-shell', 'graph-dashboard', 'graph-page', className].filter(Boolean).join(' ')}>
    <header class="graph-page__top dashboard-header">
        <div class="graph-page__title">
            <p class="graph-page__eyebrow eyebrow">HAWKI RAG graph</p>
            <h1>Graph Explorer</h1>
            <p class="graph-page__copy header-copy">Explore connected entities, source links, and Neo4j retrieval paths.</p>
        </div>

        <div class="header-actions">
            <nav class="graph-page__nav" aria-label="HAWKI-RAG pages">
                <button type="button" onclick={refreshPage}>Refresh</button>
                {#each navItems as item}
                    <a class:item-active={item.key === 'graph'} href={item.href}>{item.label}</a>
                {/each}
            </nav>
        </div>
    </header>

    <GraphWalkway
        overviewEndpoint={overviewEndpoint}
        searchEndpoint={searchEndpoint}
        semanticSearchEndpoint={semanticSearchEndpoint}
        nodeEndpoint={nodeEndpoint}
    />

    <details class="graph-technical-drawer" data-technical-graph-panel ontoggle={handleTechnicalToggle}>
        <summary>
            <span>Technical Graph Engine</span>
            <small>Cytoscape / Neo4j</small>
        </summary>

        <section class="graph-visualization-section">
            <div class="graph-visualization-header">
                <div>
                    <h2>Graph Workspace</h2>
                    <p>Neo4j inspection surface.</p>
                </div>
                <div id="neo4j-graph-meta" class="badge">Open technical graph...</div>
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
                        <p class="muted">Select a node to inspect entity metadata and expand neighbors.</p>
                    </div>
                </aside>
            </div>
        </section>
    </details>
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
        background:
            linear-gradient(135deg, rgba(7, 17, 31, 0.98), rgba(16, 24, 39, 0.94)),
            repeating-linear-gradient(90deg, rgba(34, 211, 238, 0.05) 0 1px, transparent 1px 88px),
            repeating-linear-gradient(0deg, rgba(244, 211, 94, 0.04) 0 1px, transparent 1px 58px);
        color: var(--pg-text);
        font-family: Inter, ui-sans-serif, system-ui, sans-serif;
    }

    .graph-page__top,
    .graph-page :global(.walkway),
    .graph-technical-drawer {
        margin-right: auto;
        margin-left: auto;
        max-width: 1760px;
    }

    .graph-page__top {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: flex-end;
        gap: 20px;
        margin-bottom: 18px;
        padding: 0 0 18px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    }

    .graph-page__title {
        min-width: 0;
    }

    .graph-page__eyebrow {
        margin: 0 0 0.35rem;
        color: var(--pg-cyan);
        font-size: 0.78rem;
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .graph-page__title h1 {
        margin: 0;
        color: var(--pg-text);
        font-size: 1.4rem;
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: 0;
    }

    .graph-page__copy {
        max-width: 680px;
        margin: 8px 0 0;
        color: #bae6fd;
        font-size: 0.95rem;
        line-height: 1.55;
    }

    .header-actions {
        display: flex;
        flex-wrap: nowrap;
        justify-content: flex-end;
        gap: 0.6rem;
        min-width: 0;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scrollbar-width: thin;
    }

    .graph-page__nav {
        display: flex;
        flex-wrap: nowrap;
        justify-content: flex-end;
        gap: 0.6rem;
        min-width: 0;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scrollbar-width: thin;
    }

    .graph-page__nav a,
    .graph-page__nav button {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        height: 40px;
        border: 1px solid var(--pg-border);
        border-radius: 0.5rem;
        padding: 0 12px;
        background: rgba(15, 23, 42, 0.72);
        color: var(--pg-text);
        font: inherit;
        font-size: 0.88rem;
        font-weight: 800;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }

    .graph-page__nav .item-active {
        border-color: var(--pg-border-strong);
        background: rgba(20, 184, 166, 0.18);
        color: #99f6e4;
    }

    @media (max-width: 860px) {
        .graph-page__top {
            align-items: flex-start;
            grid-template-columns: 1fr;
        }

        .graph-page__nav {
            justify-content: flex-start;
        }
    }
</style>
