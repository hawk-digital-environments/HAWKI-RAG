<!--
  @component Immersive HAWKI-RAG graph walkway that turns Neo4j entities into explorable path stops.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import type {GraphEdge, GraphNode, GraphPayload, GraphSearchPayload, WalkwayStation} from '../types/graph.js';

    const WALK_POINTS: Array<{x: number; y: number}> = [
        {x: 8, y: 78},
        {x: 18, y: 60},
        {x: 30, y: 72},
        {x: 42, y: 48},
        {x: 55, y: 58},
        {x: 66, y: 34},
        {x: 78, y: 45},
        {x: 90, y: 22},
        {x: 82, y: 12},
        {x: 66, y: 18},
        {x: 52, y: 10},
        {x: 38, y: 26},
    ];

    interface RailPulse {
        id: number;
        path: string;
        dash: number;
        durationMs: number;
        delayMs: number;
        hue: number;
        width: number;
        glowWidth: number;
        coreWidth: number;
    }

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Endpoint returning Neo4j graph overview nodes and edges. */
        overviewEndpoint: string;
        /** Endpoint for entity keyword search. */
        searchEndpoint: string;
        /** Endpoint for semantic graph search. */
        semanticSearchEndpoint: string;
        /** Endpoint for loading one node neighborhood. */
        nodeEndpoint: string;
    }

    const {
        overviewEndpoint,
        searchEndpoint,
        semanticSearchEndpoint,
        nodeEndpoint,
        class: className = '',
        ...restProps
    }: Props = $props();

    let busy = $state(false);
    let mode = $state<'overview' | 'search' | 'semantic'>('overview');
    let query = $state('');
    let status = $state('Loading graph world...');
    let nodes = $state<GraphNode[]>([]);
    let edges = $state<GraphEdge[]>([]);
    let searchResults = $state<GraphNode[]>([]);
    let selectedId = $state('');
    let warnings = $state<string[]>([]);
    let electricPulses = $state<RailPulse[]>([]);
    let pulseId = 0;
    let pulseTimer: number | null = null;
    let cleanupTimers: number[] = [];

    const activeNodes = $derived(searchResults.length > 0 ? searchResults : nodes);
    const visibleNodes = $derived(activeNodes.slice(0, WALK_POINTS.length));
    const selectedNode = $derived(activeNodes.find((node) => node.id === selectedId) ?? nodes.find((node) => node.id === selectedId) ?? visibleNodes[0] ?? null);
    const stations = $derived(visibleNodes.map((node, index) => stationFor(node, index)));
    const pathData = $derived(stations.map((station, index) => `${index === 0 ? 'M' : 'L'} ${station.x} ${station.y}`).join(' '));
    const selectedStation = $derived(stations.find((station) => station.node.id === selectedId) ?? stations[0] ?? null);
    const sourceCount = $derived(new Set(nodes.flatMap((node) => node.source_document_ids || [])).size);
    const selectedSourceDocuments = $derived(selectedNode?.source_documents?.slice(0, 3) || []);

    onMount(() => {
        loadOverview().catch((error: unknown) => {
            setError(error);
        });
        scheduleElectricPulse();

        return () => {
            if (pulseTimer !== null) {
                window.clearTimeout(pulseTimer);
            }
            cleanupTimers.forEach((timer) => window.clearTimeout(timer));
        };
    });

    $effect(() => {
        if (!selectedId && visibleNodes.length > 0) {
            selectedId = visibleNodes[0].id;
        }
    });

    function stationFor(node: GraphNode, index: number): WalkwayStation {
        const point = WALK_POINTS[index % WALK_POINTS.length];

        return {
            node,
            index,
            x: point.x,
            y: point.y,
            tone: index % 6,
            relationCount: relationCount(node.id),
        };
    }

    function relationCount(nodeId: string): number {
        return edges.filter((edge) => edge.source === nodeId || edge.target === nodeId).length;
    }

    function endpointWithQuery(endpoint: string, values: Record<string, string | number>): string {
        const url = new URL(endpoint, window.location.origin);
        Object.entries(values).forEach(([key, value]) => {
            url.searchParams.set(key, String(value));
        });

        return url.toString();
    }

    async function requestJson<T extends GraphPayload | GraphSearchPayload>(endpoint: string): Promise<T> {
        const response = await fetch(endpoint, {
            cache: 'no-store',
            headers: {Accept: 'application/json'},
        });
        const payload = await response.json().catch(() => ({})) as T;

        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || payload.error || `Graph request failed (${response.status})`);
        }

        return payload;
    }

    function setError(error: unknown): void {
        status = error instanceof Error ? error.message : 'Graph walkway is not reachable.';
        busy = false;
    }

    function mergePayload(payload: GraphPayload): void {
        const knownNodes = new Map(nodes.map((node) => [node.id, node]));
        const knownEdges = new Map(edges.map((edge) => [edge.id, edge]));

        (payload.nodes || []).forEach((node) => knownNodes.set(node.id, node));
        (payload.edges || []).forEach((edge) => knownEdges.set(edge.id, edge));

        nodes = Array.from(knownNodes.values());
        edges = Array.from(knownEdges.values());
        warnings = payload.warnings || [];
    }

    async function loadOverview(): Promise<void> {
        busy = true;
        mode = 'overview';
        searchResults = [];
        status = 'Opening graph walkway...';

        try {
            const payload = await requestJson<GraphPayload>(overviewEndpoint);
            nodes = payload.nodes || [];
            edges = payload.edges || [];
            warnings = payload.warnings || [];
            selectedId = nodes[0]?.id || '';
            status = `${nodes.length} stops connected by ${edges.length} paths`;
        } catch (error) {
            setError(error);
        } finally {
            busy = false;
        }
    }

    async function runSearch(kind: 'search' | 'semantic'): Promise<void> {
        const text = query.trim();
        if (!text) {
            await loadOverview();
            return;
        }

        busy = true;
        mode = kind;
        status = kind === 'semantic' ? 'Tracing semantic route...' : 'Finding route...';

        try {
            const endpoint = endpointWithQuery(kind === 'semantic' ? semanticSearchEndpoint : searchEndpoint, {
                q: text,
                limit: kind === 'semantic' ? 8 : 12,
            });
            const payload = await requestJson<GraphSearchPayload>(endpoint);
            searchResults = payload.results || [];
            warnings = payload.warnings || [];
            selectedId = searchResults[0]?.id || '';
            status = `${searchResults.length} matching stops`;
        } catch (error) {
            setError(error);
        } finally {
            busy = false;
        }
    }

    async function loadNodeNeighborhood(node: GraphNode): Promise<void> {
        selectedId = node.id;
        busy = true;
        status = `Expanding ${labelFor(node)}...`;

        try {
            const endpoint = endpointWithQuery(nodeEndpoint, {
                node_id: node.id,
                limit: 120,
            });
            const payload = await requestJson<GraphPayload>(endpoint);
            mergePayload(payload);
            status = `${labelFor(node)} connected to ${relationCount(node.id)} paths`;
        } catch (error) {
            setError(error);
        } finally {
            busy = false;
        }
    }

    function labelFor(node: GraphNode | null): string {
        return String(node?.label || node?.properties?.name || node?.id || 'Unknown stop');
    }

    function typeFor(node: GraphNode | null): string {
        return String(node?.type || 'Entity');
    }

    function sourceLabel(node: GraphNode | null): string {
        const count = node?.source_document_ids?.length || 0;
        if (count === 1) return '1 source';
        return `${count} sources`;
    }

    function scoreLabel(node: GraphNode): string {
        if (typeof node.score !== 'number') return typeFor(node);
        return `${typeFor(node)} score ${node.score.toFixed(2)}`;
    }

    function formatProperty(value: unknown): string {
        if (Array.isArray(value)) return value.slice(0, 4).map((item) => String(item)).join(', ');
        if (value && typeof value === 'object') return JSON.stringify(value);
        return String(value ?? '');
    }

    function selectedProperties(node: GraphNode | null): Array<[string, unknown]> {
        return Object.entries(node?.properties || {}).slice(0, 5);
    }

    function randomBetween(min: number, max: number): number {
        return min + Math.random() * (max - min);
    }

    function pulseSegments(): string[] {
        const points = stations.length > 1 ? stations.map((station) => ({x: station.x, y: station.y})) : WALK_POINTS;

        return points.slice(1).map((point, index) => {
            const previous = points[index];

            return `M ${previous.x} ${previous.y} L ${point.x} ${point.y}`;
        });
    }

    function emitElectricPulse(): void {
        const segments = pulseSegments();
        if (segments.length === 0) return;

        const pulse: RailPulse = {
            id: pulseId,
            path: segments[Math.floor(Math.random() * segments.length)],
            dash: Math.round(randomBetween(12, 24)),
            durationMs: Math.round(randomBetween(720, 1450)),
            delayMs: Math.round(randomBetween(0, 180)),
            hue: [48, 164, 188, 204, 265][Math.floor(Math.random() * 5)],
            width: Number(randomBetween(1.75, 3.2).toFixed(2)),
            glowWidth: 0,
            coreWidth: 0,
        };
        pulse.glowWidth = Number((pulse.width * 3.5).toFixed(2));
        pulse.coreWidth = Number((pulse.width * 0.62).toFixed(2));
        pulseId += 1;

        electricPulses = [...electricPulses.slice(-6), pulse];

        const cleanupTimer = window.setTimeout(() => {
            electricPulses = electricPulses.filter((item) => item.id !== pulse.id);
            cleanupTimers = cleanupTimers.filter((timer) => timer !== cleanupTimer);
        }, pulse.durationMs + pulse.delayMs + 180);
        cleanupTimers = [...cleanupTimers, cleanupTimer];
    }

    function scheduleElectricPulse(): void {
        pulseTimer = window.setTimeout(() => {
            emitElectricPulse();
            scheduleElectricPulse();
        }, randomBetween(420, 2400));
    }
</script>

<section {...restProps} class={['walkway', className].filter(Boolean).join(' ')}>
    <div class="walkway__header">
        <div>
            <p class="walkway__eyebrow">Current route</p>
            <h2>Connection Path</h2>
        </div>
        <div class="walkway__metrics" aria-label="Graph metrics">
            <span><strong>{nodes.length}</strong> stops</span>
            <span><strong>{edges.length}</strong> paths</span>
            <span><strong>{sourceCount}</strong> sources</span>
        </div>
    </div>

    <div class="walkway__controls">
        <form
            class="walkway__search"
            onsubmit={(event) => {
                event.preventDefault();
                runSearch('search');
            }}
        >
            <label for="walkway-search">Search</label>
            <input id="walkway-search" bind:value={query} type="search" autocomplete="off" placeholder="Topic, person, document" />
            <button type="submit" disabled={busy}>Find</button>
            <button type="button" disabled={busy} onclick={() => runSearch('semantic')}>Deep</button>
            <button type="button" disabled={busy} onclick={loadOverview}>Reset</button>
        </form>
        <div class="walkway__status" data-mode={mode}>{busy ? 'Loading...' : status}</div>
    </div>

    <div class="walkway__scene" aria-live="polite">
        <div class="walkway__floor" aria-hidden="true"></div>
        <svg class="walkway__rail" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <path d={pathData} />
            {#each electricPulses as pulse (pulse.id)}
                <path
                    class="walkway__rail-electric walkway__rail-electric--glow"
                    d={pulse.path}
                    pathLength="100"
                    stroke-dasharray={`${pulse.dash} ${Math.max(1, 100 - pulse.dash)}`}
                    style={`--pulse-duration: ${pulse.durationMs}ms; --pulse-delay: ${pulse.delayMs}ms; --pulse-hue: ${pulse.hue}; --pulse-width: ${pulse.width}px; --pulse-glow-width: ${pulse.glowWidth}px; --pulse-core-width: ${pulse.coreWidth}px;`}
                />
                <path
                    class="walkway__rail-electric walkway__rail-electric--core"
                    d={pulse.path}
                    pathLength="100"
                    stroke-dasharray={`${Math.max(4, Math.round(pulse.dash * 0.44))} ${Math.max(1, 100 - Math.round(pulse.dash * 0.44))}`}
                    style={`--pulse-duration: ${pulse.durationMs}ms; --pulse-delay: ${pulse.delayMs}ms; --pulse-hue: ${pulse.hue}; --pulse-width: ${pulse.width}px; --pulse-glow-width: ${pulse.glowWidth}px; --pulse-core-width: ${pulse.coreWidth}px;`}
                />
            {/each}
        </svg>

        {#if selectedStation}
            <div
                class="walkway__traveler"
                style={`--x: ${selectedStation.x}%; --y: ${selectedStation.y}%;`}
                aria-hidden="true"
            ></div>
        {/if}

        {#each stations as station (station.node.id)}
            <button
                type="button"
                class="walkway__station"
                class:walkway__station--active={station.node.id === selectedId}
                data-tone={station.tone}
                style={`--x: ${station.x}%; --y: ${station.y}%;`}
                onclick={() => loadNodeNeighborhood(station.node)}
                aria-pressed={station.node.id === selectedId}
            >
                <span class="walkway__station-index">{String(station.index + 1).padStart(2, '0')}</span>
                <span class="walkway__station-label">{labelFor(station.node)}</span>
                <small>{station.relationCount} links</small>
            </button>
        {/each}

        {#if stations.length === 0}
            <div class="walkway__empty">No graph stops found.</div>
        {/if}
    </div>

    <div class="walkway__lower">
        <article class="walkway__detail">
            <div>
                <p>{typeFor(selectedNode)} / {sourceLabel(selectedNode)}</p>
                <h3>{labelFor(selectedNode)}</h3>
            </div>

            {#if selectedProperties(selectedNode).length}
                <dl>
                    {#each selectedProperties(selectedNode) as [key, value]}
                        <div>
                            <dt>{key}</dt>
                            <dd>{formatProperty(value)}</dd>
                        </div>
                    {/each}
                </dl>
            {/if}
        </article>

        <aside class="walkway__sources" aria-label="Selected sources">
            <h3>Source Signals</h3>
            {#if selectedSourceDocuments.length}
                {#each selectedSourceDocuments as source}
                    <div class="walkway__source">
                        <strong>{source.label || source.title || source.originalFilename || source.docId}</strong>
                        <span>{source.missing ? 'metadata only' : 'linked source'}</span>
                    </div>
                {/each}
            {:else}
                <div class="walkway__source">
                    <strong>{scoreLabel(selectedNode || {id: 'empty'})}</strong>
                    <span>{warnings[0] || 'overview route'}</span>
                </div>
            {/if}
        </aside>
    </div>
</section>

<style>
    .walkway {
        --walk-bg: #07111f;
        --walk-surface: rgba(8, 17, 31, 0.92);
        --walk-surface-strong: rgba(15, 23, 42, 0.96);
        --walk-ink: #f8fafc;
        --walk-muted: #b7c6d8;
        --walk-soft: #dbeafe;
        --walk-line: rgba(226, 232, 240, 0.66);
        --walk-border: rgba(148, 163, 184, 0.24);
        --walk-cyan: #38bdf8;
        --walk-green: #34d399;
        --walk-amber: #fbbf24;
        --walk-red: #fb7185;
        --walk-violet: #a78bfa;
        --walk-sand: #f4d35e;
        --walk-shadow: 0 26px 80px rgba(0, 0, 0, 0.42);

        display: grid;
        gap: 16px;
        margin-top: 18px;
        color: var(--walk-ink);
    }

    .walkway__header,
    .walkway__controls,
    .walkway__lower {
        display: flex;
        align-items: stretch;
        justify-content: space-between;
        gap: 14px;
    }

    .walkway__header {
        align-items: flex-end;
    }

    .walkway__eyebrow,
    .walkway__detail p {
        margin: 0 0 5px;
        color: var(--walk-green);
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    .walkway h2,
    .walkway h3 {
        margin: 0;
        letter-spacing: 0;
    }

    .walkway h2 {
        font-size: clamp(1.15rem, 2vw, 1.7rem);
        line-height: 1.08;
    }

    .walkway__metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(86px, 1fr));
        gap: 8px;
        min-width: min(100%, 360px);
    }

    .walkway__metrics span,
    .walkway__status,
    .walkway__detail,
    .walkway__sources {
        border: 1px solid var(--walk-border);
        border-radius: 8px;
        background: var(--walk-surface);
        box-shadow: var(--walk-shadow);
    }

    .walkway__metrics span {
        padding: 12px;
        color: var(--walk-muted);
        font-size: 0.78rem;
        text-transform: uppercase;
    }

    .walkway__metrics strong {
        display: block;
        color: var(--walk-ink);
        font-size: 1.45rem;
    }

    .walkway__controls {
        align-items: center;
    }

    .walkway__search {
        display: grid;
        grid-template-columns: auto minmax(220px, 1fr) repeat(3, minmax(68px, auto));
        align-items: center;
        gap: 8px;
        flex: 1;
        border: 1px solid var(--walk-border);
        border-radius: 8px;
        padding: 10px;
        background: var(--walk-surface-strong);
    }

    .walkway__search label {
        color: var(--walk-muted);
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .walkway__search input,
    .walkway__search button {
        min-height: 40px;
        border-radius: 7px;
        font: inherit;
    }

    .walkway__search input {
        min-width: 0;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(2, 6, 23, 0.72);
        color: var(--walk-ink);
        padding: 0 12px;
    }

    .walkway__search button {
        border: 0;
        background: #0e7490;
        color: #f8fafc;
        font-weight: 800;
        cursor: pointer;
    }

    .walkway__search button:nth-of-type(2) {
        background: #7c3aed;
    }

    .walkway__search button:nth-of-type(3) {
        background: #475569;
    }

    .walkway__search button:disabled {
        cursor: wait;
        opacity: 0.62;
    }

    .walkway__status {
        min-width: 230px;
        padding: 14px;
        color: var(--walk-soft);
        font-size: 0.9rem;
    }

    .walkway__scene {
        position: relative;
        min-height: 640px;
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 8px;
        background:
            linear-gradient(180deg, rgba(14, 116, 144, 0.16), transparent 38%),
            linear-gradient(135deg, #07111f 0%, #101827 48%, #172033 100%);
        box-shadow: var(--walk-shadow);
        isolation: isolate;
    }

    .walkway__floor {
        position: absolute;
        inset: 30% -12% -28%;
        transform: perspective(780px) rotateX(58deg);
        transform-origin: center top;
        background:
            repeating-linear-gradient(90deg, rgba(244, 211, 94, 0.24) 0 1px, transparent 1px 78px),
            repeating-linear-gradient(0deg, rgba(56, 189, 248, 0.18) 0 1px, transparent 1px 46px),
            rgba(8, 17, 31, 0.64);
        border-top: 1px solid rgba(244, 211, 94, 0.32);
        z-index: 0;
    }

    .walkway__rail {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        z-index: 1;
        filter: drop-shadow(0 10px 22px rgba(56, 189, 248, 0.22));
        pointer-events: none;
    }

    .walkway__rail path {
        fill: none;
        stroke: var(--walk-line);
        stroke-dasharray: 7 7;
        stroke-linecap: round;
        stroke-width: 1.35;
        vector-effect: non-scaling-stroke;
    }

    .walkway__rail-electric {
        fill: none;
        stroke: hsl(var(--pulse-hue) 100% 70%);
        stroke-dashoffset: 112;
        stroke-linecap: round;
        stroke-width: var(--pulse-width);
        opacity: 0;
        vector-effect: non-scaling-stroke;
        animation: rail-electric-pass var(--pulse-duration) cubic-bezier(0.16, 0.78, 0.18, 1) var(--pulse-delay) both;
        mix-blend-mode: screen;
    }

    .walkway__rail-electric--glow {
        stroke: hsl(var(--pulse-hue) 100% 64% / 0.76);
        stroke-width: var(--pulse-glow-width);
        filter:
            drop-shadow(0 0 7px hsl(var(--pulse-hue) 100% 60% / 0.82))
            drop-shadow(0 0 18px hsl(var(--pulse-hue) 100% 58% / 0.46));
    }

    .walkway__rail-electric--core {
        stroke: color-mix(in srgb, hsl(var(--pulse-hue) 100% 72%) 68%, white);
        stroke-width: var(--pulse-core-width);
        filter: drop-shadow(0 0 5px hsl(var(--pulse-hue) 100% 72% / 0.78));
    }

    .walkway__traveler,
    .walkway__station {
        position: absolute;
        left: var(--x);
        top: var(--y);
        transform: translate(-50%, -50%);
        z-index: 3;
    }

    .walkway__traveler {
        width: 84px;
        height: 84px;
        border: 1px solid rgba(248, 250, 252, 0.78);
        border-radius: 999px;
        pointer-events: none;
        animation: traveler-pulse 1.8s ease-in-out infinite;
    }

    .walkway__station {
        --station-color: var(--walk-cyan);
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        grid-template-rows: auto auto;
        column-gap: 9px;
        width: clamp(150px, 17vw, 230px);
        min-height: 72px;
        border: 1px solid color-mix(in srgb, var(--station-color) 58%, transparent);
        border-radius: 8px;
        padding: 10px;
        background: rgba(8, 17, 31, 0.9);
        color: var(--walk-ink);
        text-align: left;
        box-shadow: 0 16px 32px rgba(0, 0, 0, 0.34);
        cursor: pointer;
    }

    .walkway__station[data-tone="1"] {
        --station-color: var(--walk-green);
    }

    .walkway__station[data-tone="2"] {
        --station-color: var(--walk-amber);
    }

    .walkway__station[data-tone="3"] {
        --station-color: var(--walk-red);
    }

    .walkway__station[data-tone="4"] {
        --station-color: var(--walk-violet);
    }

    .walkway__station[data-tone="5"] {
        --station-color: var(--walk-sand);
    }

    .walkway__station--active {
        background: color-mix(in srgb, var(--station-color) 18%, rgba(8, 17, 31, 0.94));
        border-color: var(--station-color);
    }

    .walkway__station:focus-visible {
        outline: 2px solid var(--station-color);
        outline-offset: 3px;
    }

    .walkway__station-index {
        grid-row: 1 / span 2;
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 999px;
        background: var(--station-color);
        color: #06111f;
        font-weight: 900;
    }

    .walkway__station-label {
        overflow: hidden;
        color: var(--walk-ink);
        font-size: 0.95rem;
        font-weight: 850;
        line-height: 1.2;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .walkway__station small {
        color: var(--walk-muted);
        font-size: 0.76rem;
    }

    .walkway__empty {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        z-index: 2;
        color: var(--walk-muted);
    }

    .walkway__lower {
        align-items: stretch;
    }

    .walkway__detail {
        flex: 1.5;
        padding: 18px;
    }

    .walkway__detail h3 {
        font-size: clamp(1.35rem, 3vw, 2.2rem);
    }

    .walkway__detail dl {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 18px 0 0;
    }

    .walkway__detail dl div,
    .walkway__source {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 8px;
        padding: 10px;
        background: rgba(2, 6, 23, 0.44);
    }

    .walkway__detail dt {
        color: var(--walk-green);
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .walkway__detail dd {
        margin: 5px 0 0;
        overflow-wrap: anywhere;
        color: var(--walk-soft);
        line-height: 1.35;
    }

    .walkway__sources {
        flex: 1;
        display: grid;
        align-content: start;
        gap: 10px;
        padding: 18px;
    }

    .walkway__sources h3 {
        font-size: 1rem;
    }

    .walkway__source {
        display: grid;
        gap: 4px;
    }

    .walkway__source strong {
        overflow-wrap: anywhere;
    }

    .walkway__source span {
        color: var(--walk-muted);
        font-size: 0.82rem;
    }

    @keyframes traveler-pulse {
        0%,
        100% {
            opacity: 0.72;
            transform: translate(-50%, -50%) scale(0.92);
        }
        50% {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.08);
        }
    }

    @keyframes rail-electric-pass {
        0% {
            opacity: 0;
            stroke-dashoffset: 116;
        }
        9% {
            opacity: 0.32;
        }
        18%,
        62% {
            opacity: 1;
        }
        100% {
            opacity: 0;
            stroke-dashoffset: -34;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .walkway__rail-electric,
        .walkway__traveler {
            animation: none;
        }
    }

    @media (max-width: 980px) {
        .walkway__header,
        .walkway__controls,
        .walkway__lower {
            flex-direction: column;
        }

        .walkway__search {
            grid-template-columns: 1fr;
        }

        .walkway__metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            width: 100%;
        }

        .walkway__scene {
            min-height: 760px;
        }

        .walkway__station {
            width: min(210px, 42vw);
        }

        .walkway__detail dl {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 620px) {
        .walkway__metrics {
            grid-template-columns: 1fr;
        }

        .walkway__scene {
            min-height: 880px;
        }

        .walkway__station {
            width: min(230px, 76vw);
        }
    }
</style>
