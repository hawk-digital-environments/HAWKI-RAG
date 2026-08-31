<!--
  @component Full-page HAWKI-RAG playground for fast retrieval, source inspection, graph facts, and live system signals.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import {answerCitationLabel, parseAnswerCitations} from '../../playground/citations.js';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    type RetrievalMode = 'deep' | 'fast';
    type ResultTab = 'sources' | 'graph' | 'raw';
    type Tone = 'ready' | 'active' | 'warn' | 'fail';

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Browser URL for submitting HAWKI-RAG queries. */
        queryEndpoint: string;
        /** Browser URL for datasets that are ready for retrieval. */
        datasetsEndpoint: string;
        /** Browser URL for RAG runtime monitor data. */
        monitorEndpoint: string;
        /** Browser URL for Qdrant and Neo4j stats. */
        statsEndpoint: string;
        /** Browser URL prefix for deleting one Qdrant collection. */
        qdrantCollectionEndpointBase: string;
        /** Browser URL for clearing Neo4j graph data. */
        neo4jClearEndpoint: string;
        /** Browser URL for downloading original uploaded source files. */
        uploadDownloadEndpoint: string;
    }

    interface QueryHit {
        score: number | null;
        title: string;
        url: string;
        sourceUrl: string;
        snippet: string;
        kind: string;
        format: string;
        parent: string;
        contentHash: string;
    }

    interface KgFact {
        subject: string;
        relation: string;
        object: string;
    }

    interface DatasetOption {
        datasetId: string;
        name: string;
    }

    interface CollectionStat {
        name: string;
        count: number | null;
    }

    interface RelationshipStat {
        label: string;
        count: number;
    }

    interface StatsState {
        qdrantOk: boolean;
        collections: CollectionStat[];
        neo4jOk: boolean;
        entities: number;
        triplets: number;
        relationships: RelationshipStat[];
    }

    interface MonitorState {
        bridgeOk: boolean;
        latencyMs: number | null;
        graphEngine: string;
        graphModel: string;
        embeddingModel: string;
        latestDocument: string;
        latestDataset: string;
        graphTriplets: number | null;
        failures: string[];
    }

    interface ActivityItem {
        time: Date;
        source: string;
        message: string;
        tone: Tone;
    }

    interface SignalItem {
        label: string;
        value: string;
        tone: Tone;
    }

    type JsonRecord = Record<string, unknown>;

    class ApiRequestError extends Error {
        readonly code: string;
        readonly status: number;

        constructor(message: string, code: string, status: number) {
            super(message);
            this.name = 'ApiRequestError';
            this.code = code;
            this.status = status;
        }
    }

    const {
        queryEndpoint,
        datasetsEndpoint,
        monitorEndpoint,
        statsEndpoint,
        qdrantCollectionEndpointBase,
        neo4jClearEndpoint,
        uploadDownloadEndpoint,
        class: className = '',
        ...restProps
    }: Props = $props();

    const promptBank = [
        'Which documents explain the strongest connected topic?',
        'Find trusted sources about this dataset.',
        'Show the most relevant graph facts for my question.',
    ];

    let question = $state('');
    let datasets = $state<DatasetOption[]>([]);
    let selectedDatasetId = $state('');
    let topK = $state(5);
    let retrievalMode = $state<RetrievalMode>('deep');
    let includeAnswer = $state(false);
    let busy = $state(false);
    let status = $state('Ready for retrieval.');
    let resultTab = $state<ResultTab>('sources');
    let answer = $state('');
    let hits = $state<QueryHit[]>([]);
    let kgFacts = $state<KgFact[]>([]);
    let rawPayload = $state<JsonRecord | null>(null);
    let graphEnabled = $state<boolean | null>(null);
    let graphDisabledReason = $state('');
    let resultFastMode = $state(false);
    let elapsedMs = $state<number | null>(null);
    let errorMessage = $state('');
    let activity = $state<ActivityItem[]>([]);
    let stats = $state<StatsState>({
        qdrantOk: false,
        collections: [],
        neo4jOk: false,
        entities: 0,
        triplets: 0,
        relationships: [],
    });
    let monitor = $state<MonitorState>({
        bridgeOk: false,
        latencyMs: null,
        graphEngine: 'unknown',
        graphModel: 'unknown',
        embeddingModel: 'unknown',
        latestDocument: 'none',
        latestDataset: 'none',
        graphTriplets: null,
        failures: [],
    });
    let deletingCollection = $state('');
    let clearingGraph = $state(false);

    const hasResult = $derived(Boolean(rawPayload));
    const selectedHit = $derived(hits[0] ?? null);
    const qdrantPoints = $derived(stats.collections.reduce((sum, item) => sum + (item.count ?? 0), 0));
    const totalHitScore = $derived(hits.reduce((sum, hit) => sum + scoreWeight(hit.score), 0));
    const systemSignals = $derived<SignalItem[]>([
        {
            label: 'Bridge',
            value: monitor.bridgeOk ? `${monitor.latencyMs ?? 'n/a'} ms` : 'offline',
            tone: monitor.bridgeOk ? 'ready' : 'fail',
        },
        {
            label: 'Qdrant',
            value: stats.qdrantOk ? `${stats.collections.length} collections` : 'unavailable',
            tone: stats.qdrantOk ? 'ready' : 'warn',
        },
        {
            label: 'Neo4j',
            value: stats.neo4jOk ? `${stats.triplets} triplets` : 'unavailable',
            tone: stats.neo4jOk ? 'ready' : 'warn',
        },
        {
            label: 'Mode',
            value: retrievalMode === 'deep' ? 'deep vector' : 'fast vector',
            tone: retrievalMode === 'deep' ? 'active' : 'ready',
        },
    ]);
    const resultSummary = $derived(
        hasResult
            ? `${hits.length} sources - ${kgFacts.length} graph facts - ${elapsedMs ?? 0} ms`
            : 'No retrieval run yet.',
    );
    const rawJson = $derived(rawPayload ? JSON.stringify(rawPayload, null, 2) : '');
    const answerParts = $derived(parseAnswerCitations(answer));
    const graphEmptyTitle = $derived(
        graphEnabled === false
            ? 'Dataset graph retrieval is disabled.'
            : 'No scoped graph facts matched.',
    );
    const graphEmptyMessage = $derived.by(() => {
        if (graphEnabled === false) {
            return graphDisabledReason === 'dataset_scope_not_enforced'
                ? 'The server kept Neo4j retrieval off because this dataset does not yet have enforceable graph isolation.'
                : 'The server did not authorize graph retrieval for this dataset.';
        }

        if (resultFastMode) {
            return 'Fast vector mode skips graph retrieval. Run the query in Deep vector mode to include scoped graph facts.';
        }

        return 'Neo4j was searched only inside the selected dataset, but no matching facts were found.';
    });

    onMount(() => {
        void loadQueryReadyDatasets();
        void refreshSystem();
        const monitorTimer = window.setInterval(() => {
            void loadMonitor();
        }, 8000);
        const statsTimer = window.setInterval(() => {
            void loadStats(false);
        }, 5000);

        return () => {
            window.clearInterval(monitorTimer);
            window.clearInterval(statsTimer);
        };
    });

    function csrfToken(): string {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    }

    function isRecord(value: unknown): value is JsonRecord {
        return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
    }

    function asRecord(value: unknown): JsonRecord {
        return isRecord(value) ? value : {};
    }

    function asArray(value: unknown): unknown[] {
        return Array.isArray(value) ? value : [];
    }

    function textValue(value: unknown, fallback = ''): string {
        if (typeof value === 'string') return value;
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);
        if (Array.isArray(value)) return textValue(value[0], fallback);
        return fallback;
    }

    function numberValue(value: unknown, fallback = 0): number {
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        if (typeof value === 'string') {
            const parsed = Number(value);
            if (Number.isFinite(parsed)) return parsed;
        }
        return fallback;
    }

    function nullableNumber(value: unknown): number | null {
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        if (typeof value === 'string') {
            const parsed = Number(value);
            if (Number.isFinite(parsed)) return parsed;
        }
        return null;
    }

    function boolValue(value: unknown): boolean {
        return value === true || value === 'true' || value === 1;
    }

    function firstRecord(value: unknown): JsonRecord {
        const first = asArray(value)[0];
        return asRecord(first);
    }

    async function requestJson(endpoint: string, init: RequestInit = {}): Promise<JsonRecord> {
        const response = await fetch(endpoint, {
            cache: 'no-store',
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                ...(init.headers || {}),
            },
        });
        const payload = asRecord(await response.json().catch(() => ({})));

        if (!response.ok || payload.ok === false || payload.success === false) {
            const rawError = payload.error;
            const error = asRecord(rawError);
            const message = textValue(error.message, textValue(payload.message, `Request failed (${response.status})`));
            const code = textValue(error.code, typeof rawError === 'string' ? rawError : '');
            throw new ApiRequestError(message, code, response.status);
        }

        return payload;
    }

    function pushActivity(source: string, message: string, tone: Tone = 'ready'): void {
        activity = [
            {time: new Date(), source, message, tone},
            ...activity.filter((item) => !(item.source === source && item.message === message)),
        ].slice(0, 8);
    }

    function formatTime(date: Date): string {
        return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});
    }

    function choosePrompt(prompt: string): void {
        question = prompt;
    }

    function normalizeHit(value: unknown): QueryHit {
        const hit = asRecord(value);
        const payload = asRecord(hit.payload);
        const title = textValue(payload.title_text || payload.title || payload.document_title, 'Untitled source');
        const url = textValue(payload.page_url_text || payload.page_url || payload.url);
        const sourceUrl = textValue(payload.source_url || payload.local_path || payload.markdown_preview_path);
        const snippet = textValue(payload.snippet || payload.content || payload.text || payload.markdown_snippet).slice(0, 520);
        const kind = textValue(payload.component_type || payload.type, 'chunk');
        const format = textValue(payload.source_format || payload.format, 'source');
        const parent = textValue(payload.parent_url || payload.parent_page_url || payload.parent_node || payload.parent_id);
        const contentHash = textValue(payload.content_hash || payload.checksum_sha256 || payload.file_hash);

        return {
            score: nullableNumber(hit.score),
            title,
            url,
            sourceUrl,
            snippet,
            kind,
            format,
            parent,
            contentHash,
        };
    }

    function normalizeKgFact(value: unknown): KgFact {
        const fact = asRecord(value);

        return {
            subject: textValue(fact.subject, 'unknown'),
            relation: textValue(fact.relation, 'related_to'),
            object: textValue(fact.object, 'unknown'),
        };
    }

    function parseStats(payload: JsonRecord): StatsState {
        const qdrant = asRecord(payload.qdrant);
        const neo4j = asRecord(payload.neo4j);
        const collections = asArray(qdrant.collections).map((item): CollectionStat => {
            const collection = asRecord(item);

            return {
                name: textValue(collection.name, 'unnamed'),
                count: nullableNumber(collection.count),
            };
        });
        const relationships = asArray(neo4j.relationship_types).map((item): RelationshipStat => {
            const row = asRecord(item);

            return {
                label: textValue(row.type || row.rel_type || row.label, 'REL'),
                count: numberValue(row.count),
            };
        });

        return {
            qdrantOk: boolValue(qdrant.ok),
            collections,
            neo4jOk: boolValue(neo4j.ok),
            entities: numberValue(neo4j.entities),
            triplets: numberValue(neo4j.triplets),
            relationships,
        };
    }

    function parseMonitor(payload: JsonRecord): MonitorState {
        const bridge = asRecord(payload.bridge);
        const config = asRecord(payload.config);
        const latest = asRecord(payload.latest_document_graph);
        const failures = asArray(payload.graph_failures)
            .map((item) => textValue(asRecord(item).error || asRecord(item).message))
            .filter(Boolean)
            .slice(0, 3);

        return {
            bridgeOk: boolValue(bridge.ok),
            latencyMs: nullableNumber(bridge.latency_ms),
            graphEngine: textValue(config.graph_engine, 'raganything'),
            graphModel: textValue(config.graph_model, 'unknown'),
            embeddingModel: textValue(config.embedding_model, 'unknown'),
            latestDocument: textValue(latest.title || latest.document_id, 'none'),
            latestDataset: textValue(latest.dataset_id, 'none'),
            graphTriplets: nullableNumber(latest.graph_triplets),
            failures,
        };
    }

    async function loadStats(addActivity = true): Promise<void> {
        try {
            const payload = await requestJson(statsEndpoint);
            stats = parseStats(payload);
            if (addActivity) {
                pushActivity('Stats', `Qdrant ${stats.collections.length}, Neo4j ${stats.triplets} triplets`);
            }
        } catch (error) {
            stats = {...stats, qdrantOk: false, neo4jOk: false};
            if (addActivity) {
                pushActivity('Stats', error instanceof Error ? error.message : 'Stats unavailable', 'warn');
            }
        }
    }

    async function loadMonitor(): Promise<void> {
        try {
            const payload = await requestJson(monitorEndpoint);
            monitor = parseMonitor(payload);
        } catch (error) {
            monitor = {...monitor, bridgeOk: false};
            pushActivity('Monitor', error instanceof Error ? error.message : 'Monitor unavailable', 'warn');
        }
    }

    async function refreshSystem(): Promise<void> {
        await Promise.all([loadMonitor(), loadStats(false)]);
        pushActivity('System', 'Live state refreshed');
    }

    async function loadQueryReadyDatasets(): Promise<boolean> {
        try {
            const payload = await requestJson(datasetsEndpoint);
            datasets = asArray(payload.datasets)
                .map((value): DatasetOption | null => {
                    const dataset = asRecord(value);
                    const datasetId = textValue(dataset.dataset_id);
                    if (!datasetId) return null;

                    return {
                        datasetId,
                        name: textValue(dataset.name, datasetId),
                    };
                })
                .filter((value): value is DatasetOption => value !== null);
            errorMessage = '';

            const remembered = window.localStorage.getItem('hawkiRagQueryDatasetId') || '';
            selectedDatasetId = datasets.some((dataset) => dataset.datasetId === remembered)
                ? remembered
                : datasets[0]?.datasetId || '';

            if (selectedDatasetId) {
                window.localStorage.setItem('hawkiRagQueryDatasetId', selectedDatasetId);
            } else {
                window.localStorage.removeItem('hawkiRagQueryDatasetId');
                status = 'No query-ready dataset is available.';
            }

            return true;
        } catch (error) {
            datasets = [];
            selectedDatasetId = '';
            window.localStorage.removeItem('hawkiRagQueryDatasetId');
            errorMessage = error instanceof Error ? error.message : 'Query-ready datasets could not be loaded.';
            status = errorMessage;

            return false;
        }
    }

    function rememberDataset(): void {
        if (selectedDatasetId) {
            window.localStorage.setItem('hawkiRagQueryDatasetId', selectedDatasetId);
        }
    }

    function removeDatasetOption(datasetId: string): void {
        datasets = datasets.filter((dataset) => dataset.datasetId !== datasetId);
        if (!datasets.some((dataset) => dataset.datasetId === selectedDatasetId)) {
            selectedDatasetId = datasets[0]?.datasetId || '';
        }

        if (selectedDatasetId) {
            window.localStorage.setItem('hawkiRagQueryDatasetId', selectedDatasetId);
        } else {
            window.localStorage.removeItem('hawkiRagQueryDatasetId');
        }
    }

    async function runQuery(): Promise<void> {
        const query = question.trim();
        if (!query || !selectedDatasetId || busy) return;
        const queryDatasetId = selectedDatasetId;

        busy = true;
        errorMessage = '';
        status = retrievalMode === 'deep' ? 'Running scoped high-recall retrieval...' : 'Running fast scoped retrieval...';
        hits = [];
        kgFacts = [];
        answer = '';
        rawPayload = null;
        graphEnabled = null;
        graphDisabledReason = '';
        resultFastMode = retrievalMode === 'fast';
        elapsedMs = null;
        resultTab = 'sources';
        pushActivity('Query', query.slice(0, 120), 'active');

        const startedAt = performance.now();

        try {
            const payload = await requestJson(queryEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    dataset_id: queryDatasetId,
                    query,
                    top_k: topK,
                    generate: includeAnswer,
                    fast_mode: retrievalMode === 'fast',
                    smart_lookup: retrievalMode === 'deep',
                }),
            });

            rawPayload = payload;
            elapsedMs = Math.round(performance.now() - startedAt);
            hits = asArray(payload.hits).map(normalizeHit);
            kgFacts = asArray(payload.kg).map(normalizeKgFact);
            const retrieval = asRecord(payload.retrieval);
            graphEnabled = typeof retrieval.graph_enabled === 'boolean' ? retrieval.graph_enabled : null;
            graphDisabledReason = textValue(retrieval.graph_disabled_reason);
            answer = textValue(payload.answer || payload.response || payload.generated_answer);
            status = `${hits.length} sources retrieved in ${elapsedMs} ms`;
            pushActivity('Retrieval', `${hits.length} sources, ${kgFacts.length} graph facts, ${elapsedMs} ms`, 'ready');
        } catch (error) {
            if (error instanceof ApiRequestError && error.code === 'dataset_not_ready') {
                removeDatasetOption(queryDatasetId);
                const refreshed = await loadQueryReadyDatasets();
                if (refreshed) {
                    removeDatasetOption(queryDatasetId);
                }
                errorMessage = refreshed
                    ? 'That dataset is no longer query-ready and was removed from the available datasets.'
                    : 'That dataset is no longer query-ready, and the available datasets could not be refreshed.';
            } else {
                errorMessage = error instanceof Error ? error.message : 'Retrieval failed.';
            }
            status = errorMessage;
            pushActivity('Retrieval', errorMessage, 'fail');
        } finally {
            busy = false;
        }
    }

    async function deleteCollection(collection: string): Promise<void> {
        const name = collection.trim();
        if (!name || deletingCollection) return;
        if (!window.confirm(`Delete Qdrant collection "${name}"?`)) return;

        deletingCollection = name;
        try {
            await requestJson(`${qdrantCollectionEndpointBase}/${encodeURIComponent(name)}`, {
                method: 'DELETE',
                headers: {'X-CSRF-TOKEN': csrfToken()},
            });
            pushActivity('Qdrant', `Deleted ${name}`, 'warn');
            await loadStats(false);
            const refreshed = await loadQueryReadyDatasets();
            if (refreshed) {
                status = `Deleted ${name}; available datasets were refreshed.`;
            }
        } catch (error) {
            pushActivity('Qdrant', error instanceof Error ? error.message : 'Delete failed', 'fail');
        } finally {
            deletingCollection = '';
        }
    }

    async function clearNeo4j(): Promise<void> {
        if (clearingGraph) return;
        if (!window.confirm('Clear the Neo4j graph?')) return;

        clearingGraph = true;
        try {
            await requestJson(neo4jClearEndpoint, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': csrfToken()},
            });
            pushActivity('Neo4j', 'Graph cleared', 'warn');
            await loadStats(false);
        } catch (error) {
            pushActivity('Neo4j', error instanceof Error ? error.message : 'Clear failed', 'fail');
        } finally {
            clearingGraph = false;
        }
    }

    function scoreWeight(score: number | null): number {
        if (score === null || !Number.isFinite(score) || score <= 0) return 0;
        return score;
    }

    function scorePercent(score: number | null): number {
        const weight = scoreWeight(score);
        if (totalHitScore <= 0 || weight <= 0) return 0;

        return Math.min(100, (weight / totalHitScore) * 100);
    }

    function scoreShareLabel(score: number | null): string {
        const percent = scorePercent(score);
        if (percent > 0 && percent < 0.1) return '<0.1% share';

        return `${percent.toFixed(percent < 10 ? 1 : 0)}% share`;
    }

    function scoreLabel(score: number | null): string {
        return score === null ? 'n/a' : score.toFixed(score <= 1 ? 4 : 2);
    }

    function hitPrimarySource(hit: QueryHit): string {
        return hit.url || hit.sourceUrl;
    }

    function isUploadSource(value: string): boolean {
        return value.trim().toLowerCase().startsWith('upload://');
    }

    function isHttpSource(value: string): boolean {
        return /^https?:\/\//i.test(value.trim());
    }

    function uploadSourceDownloadUrl(sourceUrl: string, contentHash: string): string {
        const url = new URL(uploadDownloadEndpoint, window.location.href);
        url.searchParams.set('source_url', sourceUrl);
        if (contentHash) {
            url.searchParams.set('content_hash', contentHash);
        }

        return url.toString();
    }

    function uploadSourceName(sourceUrl: string): string {
        const rawName = sourceUrl.trim().slice('upload://'.length);
        const withoutPath = rawName.replace(/\\/g, '/').split('/').filter(Boolean).pop() || 'uploaded document';

        try {
            return decodeURIComponent(withoutPath);
        } catch {
            return withoutPath;
        }
    }

    function hitSourceHref(hit: QueryHit): string {
        const source = hitPrimarySource(hit);
        if (isUploadSource(source)) {
            return uploadSourceDownloadUrl(source, hit.contentHash);
        }
        if (isHttpSource(source)) {
            return source;
        }

        return '';
    }

    function hitSourceLabel(hit: QueryHit): string {
        const source = hitPrimarySource(hit);
        if (isUploadSource(source)) {
            return `Download ${uploadSourceName(source)}`;
        }

        return source;
    }

    function hitSourceTarget(hit: QueryHit): string | undefined {
        return isUploadSource(hitPrimarySource(hit)) ? undefined : '_blank';
    }

    function hitSourceRel(hit: QueryHit): string | undefined {
        return isUploadSource(hitPrimarySource(hit)) ? undefined : 'noopener noreferrer';
    }
</script>

<div {...restProps} class={['playground-shell', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG retrieval"
        title="Retrieval Console"
        copy="Ask questions, inspect evidence, and compare vector or graph-backed answers."
        active="playground"
    />

    <section class="signal-strip" aria-label="Live retrieval state">
        {#each systemSignals as signal}
            <article data-tone={signal.tone}>
                <span>{signal.label}</span>
                <strong>{signal.value}</strong>
            </article>
        {/each}
    </section>

    <main class="playground-grid">
        <section class="composer" aria-labelledby="query-title">
            <div class="panel-heading">
                <div>
                    <p>Query</p>
                    <h2 id="query-title">Ask with evidence</h2>
                </div>
                <span data-tone={busy ? 'active' : errorMessage ? 'fail' : 'ready'}>{busy ? 'Running' : errorMessage ? 'Failed' : 'Ready'}</span>
            </div>

            <form class="query-form" onsubmit={(event) => { event.preventDefault(); void runQuery(); }}>
                <label for="hawki-question">Question</label>
                <textarea
                    id="hawki-question"
                    bind:value={question}
                    placeholder="Ask about your documents, datasets, topics, or graph evidence..."
                    required
                ></textarea>

                <div class="prompt-bank" aria-label="Prompt starters">
                    {#each promptBank as prompt}
                        <button type="button" onclick={() => choosePrompt(prompt)}>{prompt}</button>
                    {/each}
                </div>

                <div class="control-grid">
                    <div class="dataset-control">
                        <label for="query-dataset">Dataset</label>
                        <select
                            id="query-dataset"
                            bind:value={selectedDatasetId}
                            onchange={rememberDataset}
                            disabled={datasets.length === 0 || busy}
                            required
                        >
                            {#if datasets.length === 0}
                                <option value="">No query-ready datasets</option>
                            {:else}
                                {#each datasets as dataset}
                                    <option value={dataset.datasetId}>{dataset.name} ({dataset.datasetId})</option>
                                {/each}
                            {/if}
                        </select>
                    </div>

                    <fieldset>
                        <legend>Retrieval mode</legend>
                        <div class="segmented">
                            <button
                                type="button"
                                class:segmented__item--active={retrievalMode === 'deep'}
                                aria-pressed={retrievalMode === 'deep'}
                                onclick={() => { retrievalMode = 'deep'; }}
                            >
                                Deep vector
                            </button>
                            <button
                                type="button"
                                class:segmented__item--active={retrievalMode === 'fast'}
                                aria-pressed={retrievalMode === 'fast'}
                                onclick={() => { retrievalMode = 'fast'; }}
                            >
                                Fast vector
                            </button>
                        </div>
                    </fieldset>

                    <div>
                        <label for="top-k">Top-K</label>
                        <input id="top-k" bind:value={topK} type="number" min="1" max="100" />
                    </div>

                    <label class="answer-toggle" for="include-answer">
                        <input id="include-answer" bind:checked={includeAnswer} type="checkbox" />
                        <span>Draft answer</span>
                    </label>
                </div>

                <button type="submit" class="run-button" disabled={busy || !question.trim() || !selectedDatasetId}>
                    {busy ? 'Retrieving...' : 'Run retrieval'}
                </button>
            </form>

            <div class="status-line" data-tone={errorMessage ? 'fail' : busy ? 'active' : 'ready'}>
                {status}
            </div>

            <section class="activity-panel" aria-label="Recent playground activity">
                <div class="mini-heading">
                    <strong>Activity</strong>
                    <button type="button" onclick={() => { activity = []; }}>Clear</button>
                </div>
                {#if activity.length}
                    <div class="activity-list">
                        {#each activity as item, index (`${item.source}-${item.message}-${index}`)}
                            <article data-tone={item.tone}>
                                <time>{formatTime(item.time)}</time>
                                <strong>{item.source}</strong>
                                <span>{item.message}</span>
                            </article>
                        {/each}
                    </div>
                {:else}
                    <p class="empty-copy">No activity yet.</p>
                {/if}
            </section>
        </section>

        <section class="result-stage" aria-labelledby="result-title">
            <div class="panel-heading">
                <div>
                    <p>Result</p>
                    <h2 id="result-title">Evidence board</h2>
                </div>
                <span>{resultSummary}</span>
            </div>

            {#if !hasResult && !errorMessage}
                <div class="empty-result">
                    <strong>Ready for a grounded question.</strong>
                    <span>Sources, graph facts, and raw retrieval data will appear here.</span>
                </div>
            {/if}

            {#if errorMessage}
                <div class="error-panel">{errorMessage}</div>
            {/if}

            {#if hasResult}
                <div class="result-tabs" role="tablist" aria-label="Result views">
                    <button type="button" class:result-tabs__item--active={resultTab === 'sources'} onclick={() => { resultTab = 'sources'; }}>
                        Sources
                    </button>
                    <button type="button" class:result-tabs__item--active={resultTab === 'graph'} onclick={() => { resultTab = 'graph'; }}>
                        Graph
                    </button>
                    <button type="button" class:result-tabs__item--active={resultTab === 'raw'} onclick={() => { resultTab = 'raw'; }}>
                        Raw
                    </button>
                </div>

                {#if answer}
                    <article class="answer-panel">
                        <span>Answer draft</span>
                        <p>
                            {#each answerParts as part}
                                {#if part.kind === 'text'}
                                    {part.text}
                                {:else if hits[part.sourceIndex]}
                                    {@const citationHit = hits[part.sourceIndex]}
                                    {#if hitSourceHref(citationHit)}
                                        <a
                                            class="answer-citation"
                                            href={hitSourceHref(citationHit)}
                                            target={hitSourceTarget(citationHit)}
                                            rel={hitSourceRel(citationHit)}
                                            download={isUploadSource(hitPrimarySource(citationHit)) ? true : undefined}
                                            title={`Open reference ${part.sourceNumber}`}
                                        >{answerCitationLabel(part.sourceNumber)}</a>
                                    {:else}
                                        <button
                                            type="button"
                                            class="answer-citation"
                                            title={`Show reference ${part.sourceNumber}`}
                                            onclick={() => { resultTab = 'sources'; }}
                                        >{answerCitationLabel(part.sourceNumber)}</button>
                                    {/if}
                                {:else}
                                    <span class="answer-citation answer-citation--missing">[Reference {part.sourceNumber} unavailable]</span>
                                {/if}
                            {/each}
                        </p>
                    </article>
                {/if}

                {#if resultTab === 'sources'}
                    <div class="source-layout">
                        <div class="source-list">
                            {#each hits as hit, index (`${hit.title}-${index}`)}
                                <article class="source-item">
                                    <div class="source-rank">{String(index + 1).padStart(2, '0')}</div>
                                    <div>
                                        <h3>{hit.title}</h3>
                                        <div class="source-meta">
                                            <span>{hit.kind}</span>
                                            <span>{hit.format}</span>
                                            <span>score {scoreLabel(hit.score)}</span>
                                            <span>{scoreShareLabel(hit.score)}</span>
                                        </div>
                                        <div class="score-bar" aria-label={`Score ${scoreLabel(hit.score)}, ${scoreShareLabel(hit.score)}`}>
                                            <span style={`width: ${scorePercent(hit.score).toFixed(3)}%;`}></span>
                                        </div>
                                        {#if hitSourceHref(hit)}
                                            <a
                                                class:source-download-link={isUploadSource(hitPrimarySource(hit))}
                                                href={hitSourceHref(hit)}
                                                target={hitSourceTarget(hit)}
                                                rel={hitSourceRel(hit)}
                                                download={isUploadSource(hitPrimarySource(hit)) ? true : undefined}
                                            >{hitSourceLabel(hit)}</a>
                                        {:else if hitPrimarySource(hit)}
                                            <span class="source-path">{hitPrimarySource(hit)}</span>
                                        {/if}
                                        {#if hit.snippet}
                                            <p>{hit.snippet}</p>
                                        {/if}
                                    </div>
                                </article>
                            {:else}
                                <div class="empty-result">
                                    <strong>No sources retrieved.</strong>
                                    <span>Try a broader question or increase Top-K.</span>
                                </div>
                            {/each}
                        </div>

                        <aside class="source-lens" aria-label="Top source lens">
                            <span>Top source</span>
                            {#if selectedHit}
                                <strong>{selectedHit.title}</strong>
                                <p>{selectedHit.parent || hitSourceLabel(selectedHit) || 'No parent source recorded.'}</p>
                            {:else}
                                <strong>No selected source</strong>
                                <p>Run retrieval to inspect the strongest evidence.</p>
                            {/if}
                        </aside>
                    </div>
                {/if}

                {#if resultTab === 'graph'}
                    <div class="graph-facts">
                        {#each kgFacts as fact, index (`${fact.subject}-${fact.relation}-${index}`)}
                            <article>
                                <strong>{fact.subject}</strong>
                                <span>{fact.relation}</span>
                                <strong>{fact.object}</strong>
                            </article>
                        {:else}
                            <div class="empty-result">
                                <strong>{graphEmptyTitle}</strong>
                                <span>{graphEmptyMessage}</span>
                            </div>
                        {/each}
                    </div>
                {/if}

                {#if resultTab === 'raw'}
                    <pre class="raw-json">{rawJson}</pre>
                {/if}
            {/if}
        </section>

        <aside class="system-panel" aria-label="HAWKI-RAG system overview">
            <section class="system-section">
                <div class="mini-heading">
                    <strong>Runtime</strong>
                    <span data-tone={monitor.bridgeOk ? 'ready' : 'fail'}>{monitor.bridgeOk ? 'online' : 'offline'}</span>
                </div>
                <dl class="runtime-list">
                    <div>
                        <dt>Engine</dt>
                        <dd>{monitor.graphEngine}</dd>
                    </div>
                    <div>
                        <dt>Graph model</dt>
                        <dd>{monitor.graphModel}</dd>
                    </div>
                    <div>
                        <dt>Embedding</dt>
                        <dd>{monitor.embeddingModel}</dd>
                    </div>
                    <div>
                        <dt>Latest dataset</dt>
                        <dd>{monitor.latestDataset}</dd>
                    </div>
                </dl>
            </section>

            <section class="system-section">
                <div class="mini-heading">
                    <strong>Qdrant</strong>
                    <span>{qdrantPoints} points</span>
                </div>
                <div class="collection-list">
                    {#each stats.collections as collection (collection.name)}
                        <article>
                            <div>
                                <strong>{collection.name}</strong>
                                <span>{collection.count ?? 'n/a'} points</span>
                            </div>
                            <button
                                type="button"
                                disabled={deletingCollection === collection.name}
                                onclick={() => { void deleteCollection(collection.name); }}
                            >
                                {deletingCollection === collection.name ? 'Deleting' : 'Delete'}
                            </button>
                        </article>
                    {:else}
                        <p class="empty-copy">No collections reported.</p>
                    {/each}
                </div>
            </section>

            <section class="system-section">
                <div class="mini-heading">
                    <strong>Neo4j</strong>
                    <button type="button" class="danger-action" disabled={clearingGraph} onclick={() => { void clearNeo4j(); }}>
                        {clearingGraph ? 'Clearing' : 'Clear graph'}
                    </button>
                </div>
                <div class="graph-stats">
                    <article>
                        <span>Entities</span>
                        <strong>{stats.entities}</strong>
                    </article>
                    <article>
                        <span>Triplets</span>
                        <strong>{stats.triplets}</strong>
                    </article>
                </div>
                <div class="relationship-list">
                    {#each stats.relationships.slice(0, 5) as relationship}
                        <span>{relationship.label}: {relationship.count}</span>
                    {/each}
                </div>
            </section>
        </aside>
    </main>
</div>

<style>
    :global(body) {
        margin: 0;
        background: #07111f;
    }

    :global(*) {
        box-sizing: border-box;
    }

    .playground-shell {
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

    button,
    input,
    textarea {
        font: inherit;
    }

    button {
        cursor: pointer;
    }

    button:disabled {
        cursor: wait;
        opacity: 0.62;
    }

    a {
        color: var(--pg-cyan);
        overflow-wrap: anywhere;
    }

    .signal-strip,
    .playground-grid {
        margin: 0 auto;
    }

    .signal-strip,
    .playground-grid {
        max-width: 1760px;
    }

    .panel-heading p {
        margin: 0 0 7px;
        color: var(--pg-cyan);
        font-size: 0.78rem;
        font-weight: 860;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .prompt-bank button,
    .segmented button,
    .result-tabs button,
    .mini-heading button,
    .collection-list button,
    .danger-action {
        min-height: 38px;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        background: rgba(15, 23, 42, 0.72);
        color: var(--pg-text);
        font-size: 0.85rem;
        font-weight: 820;
        text-decoration: none;
    }

    .signal-strip {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }

    .signal-strip article,
    .composer,
    .result-stage,
    .system-panel,
    .activity-panel,
    .system-section,
    .answer-panel,
    .source-lens {
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        background: var(--pg-surface);
        box-shadow: var(--pg-shadow);
    }

    .signal-strip article {
        min-height: 72px;
        padding: 12px;
    }

    .signal-strip span,
    .source-meta,
    .source-lens span,
    .answer-panel span,
    .runtime-list dt,
    .graph-stats span,
    .collection-list span,
    .relationship-list span,
    .empty-copy,
    .empty-result span {
        color: var(--pg-muted);
    }

    .signal-strip strong {
        display: block;
        margin-top: 7px;
        color: var(--pg-text);
        font-size: 1.05rem;
        overflow-wrap: anywhere;
    }

    [data-tone="ready"] {
        --tone: var(--pg-green);
    }

    [data-tone="active"] {
        --tone: var(--pg-cyan);
    }

    [data-tone="warn"] {
        --tone: var(--pg-amber);
    }

    [data-tone="fail"] {
        --tone: var(--pg-red);
    }

    .signal-strip [data-tone],
    .status-line[data-tone],
    .activity-list [data-tone] {
        border-color: color-mix(in srgb, var(--tone) 42%, transparent);
    }

    .signal-strip [data-tone] span:first-child,
    .status-line[data-tone],
    .activity-list [data-tone] strong {
        color: var(--tone);
    }

    .playground-grid {
        display: grid;
        grid-template-columns: minmax(340px, 0.82fr) minmax(560px, 1.42fr) minmax(320px, 0.76fr);
        gap: 14px;
        align-items: start;
    }

    .composer,
    .result-stage,
    .system-panel {
        min-height: calc(100vh - 190px);
        padding: 16px;
    }

    .composer,
    .system-panel {
        position: sticky;
        top: 14px;
    }

    .panel-heading,
    .mini-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .panel-heading {
        margin-bottom: 14px;
    }

    .panel-heading h2 {
        margin: 0;
        color: var(--pg-text);
        font-size: 1.65rem;
        line-height: 1;
        letter-spacing: 0;
    }

    .panel-heading > span,
    .mini-heading > span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 30px;
        border: 1px solid var(--pg-border);
        border-radius: 999px;
        padding: 0 10px;
        color: var(--pg-muted);
        font-size: 0.75rem;
        font-weight: 850;
        white-space: nowrap;
    }

    .query-form {
        display: grid;
        gap: 12px;
    }

    label,
    legend {
        color: var(--pg-muted);
        font-size: 0.8rem;
        font-weight: 840;
        text-transform: uppercase;
    }

    textarea,
    input[type="number"],
    select {
        width: 100%;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        background: rgba(3, 7, 18, 0.72);
        color: var(--pg-text);
    }

    textarea {
        min-height: 170px;
        resize: vertical;
        padding: 14px;
        line-height: 1.52;
    }

    input[type="number"],
    select {
        min-height: 42px;
        padding: 0 11px;
    }

    textarea:focus,
    input[type="number"]:focus,
    select:focus {
        outline: none;
        border-color: var(--pg-border-strong);
    }

    .prompt-bank {
        display: grid;
        gap: 8px;
    }

    .prompt-bank button {
        min-height: 42px;
        padding: 8px 10px;
        text-align: left;
    }

    .control-grid {
        display: grid;
        grid-template-columns: 1fr 94px;
        gap: 10px;
        align-items: end;
    }

    .dataset-control {
        grid-column: 1 / -1;
    }

    .dataset-control select {
        margin-top: 6px;
    }

    fieldset {
        min-width: 0;
        margin: 0;
        border: 0;
        padding: 0;
    }

    .segmented {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
        margin-top: 6px;
    }

    .segmented button,
    .result-tabs button {
        padding: 0 10px;
    }

    .segmented__item--active,
    .result-tabs__item--active {
        border-color: var(--pg-border-strong) !important;
        background: rgba(34, 211, 238, 0.14) !important;
        color: #cffafe !important;
    }

    .answer-toggle {
        grid-column: 1 / -1;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 42px;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        padding: 0 11px;
        background: rgba(15, 23, 42, 0.56);
        color: var(--pg-text);
        text-transform: none;
    }

    .answer-toggle input {
        width: 16px;
        height: 16px;
        accent-color: var(--pg-green);
    }

    .run-button {
        min-height: 48px;
        border: 0;
        border-radius: 8px;
        background: linear-gradient(135deg, #34d399, #22d3ee 58%, #f4d35e);
        color: var(--pg-ink);
        font-weight: 900;
    }

    .status-line {
        min-height: 42px;
        margin-top: 12px;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        padding: 11px 12px;
        background: rgba(3, 7, 18, 0.52);
        font-weight: 780;
    }

    .activity-panel {
        margin-top: 12px;
        padding: 12px;
        background: rgba(15, 23, 42, 0.52);
    }

    .mini-heading {
        align-items: center;
        margin-bottom: 10px;
    }

    .mini-heading strong {
        color: var(--pg-text);
    }

    .mini-heading button,
    .collection-list button,
    .danger-action {
        min-height: 30px;
        padding: 0 9px;
        font-size: 0.75rem;
    }

    .activity-list {
        display: grid;
        gap: 7px;
    }

    .activity-list article {
        display: grid;
        grid-template-columns: 78px 76px minmax(0, 1fr);
        gap: 8px;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        padding: 8px;
        background: rgba(3, 7, 18, 0.38);
        color: var(--pg-muted);
        font-size: 0.78rem;
    }

    .activity-list span {
        overflow-wrap: anywhere;
    }

    .empty-copy {
        margin: 0;
    }

    .result-stage {
        overflow: hidden;
    }

    .empty-result,
    .error-panel {
        display: grid;
        gap: 8px;
        border: 1px dashed var(--pg-border);
        border-radius: 8px;
        padding: 18px;
        background: rgba(3, 7, 18, 0.4);
    }

    .empty-result strong,
    .error-panel {
        color: var(--pg-text);
    }

    .error-panel {
        border-color: rgba(251, 113, 133, 0.42);
        color: #fecaca;
    }

    .result-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
    }

    .answer-panel {
        display: grid;
        gap: 7px;
        margin-bottom: 12px;
        padding: 14px;
        background: rgba(20, 184, 166, 0.12);
    }

    .answer-panel p {
        margin: 0;
        color: var(--pg-text);
        line-height: 1.58;
        white-space: pre-wrap;
    }

    .answer-citation {
        display: inline;
        border: 0;
        padding: 0;
        background: transparent;
        color: var(--pg-cyan);
        font: inherit;
        font-weight: 700;
        text-decoration: underline;
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
        cursor: pointer;
    }

    .answer-citation--missing {
        color: var(--pg-amber);
        cursor: default;
    }

    .source-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(220px, 0.36fr);
        gap: 12px;
        align-items: start;
    }

    .source-list {
        display: grid;
        gap: 10px;
    }

    .source-item {
        display: grid;
        grid-template-columns: 46px minmax(0, 1fr);
        gap: 12px;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        padding: 12px;
        background: var(--pg-surface-soft);
    }

    .source-rank {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 999px;
        background: var(--pg-green);
        color: var(--pg-ink);
        font-weight: 900;
    }

    .source-item h3 {
        margin: 0;
        color: var(--pg-text);
        font-size: 1rem;
        line-height: 1.24;
    }

    .source-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 7px;
        font-size: 0.76rem;
        text-transform: uppercase;
    }

    .score-bar {
        height: 7px;
        margin: 9px 0;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.18);
    }

    .score-bar span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--pg-green), var(--pg-cyan), var(--pg-amber));
    }

    .source-item p {
        margin: 9px 0 0;
        color: #dbeafe;
        line-height: 1.52;
    }

    .source-path {
        color: var(--pg-cyan);
        overflow-wrap: anywhere;
    }

    .source-download-link {
        font-weight: 820;
    }

    .source-lens {
        position: sticky;
        top: 14px;
        display: grid;
        gap: 8px;
        padding: 12px;
        background: rgba(8, 18, 32, 0.72);
    }

    .source-lens strong {
        color: var(--pg-text);
        line-height: 1.2;
    }

    .source-lens p {
        margin: 0;
        color: var(--pg-muted);
        overflow-wrap: anywhere;
    }

    .graph-facts {
        display: grid;
        gap: 9px;
    }

    .graph-facts article {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(120px, 0.42fr) minmax(0, 1fr);
        gap: 10px;
        align-items: center;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        padding: 11px;
        background: rgba(15, 23, 42, 0.56);
    }

    .graph-facts strong {
        color: var(--pg-text);
        overflow-wrap: anywhere;
    }

    .graph-facts span {
        border: 1px solid rgba(244, 211, 94, 0.34);
        border-radius: 999px;
        padding: 6px 9px;
        color: var(--pg-amber);
        text-align: center;
        overflow-wrap: anywhere;
    }

    .raw-json {
        max-height: calc(100vh - 300px);
        overflow: auto;
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        margin: 0;
        padding: 14px;
        background: rgba(3, 7, 18, 0.76);
        color: #e5edf7;
        font-size: 0.82rem;
        line-height: 1.5;
        white-space: pre-wrap;
    }

    .system-panel {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
        background: rgba(8, 18, 32, 0.82);
    }

    .system-section {
        min-width: 0;
        padding: 12px;
        background: rgba(15, 23, 42, 0.5);
        box-shadow: none;
    }

    .runtime-list {
        display: grid;
        gap: 8px;
        margin: 0;
    }

    .runtime-list div,
    .collection-list article,
    .graph-stats article {
        border: 1px solid var(--pg-border);
        border-radius: 8px;
        background: rgba(3, 7, 18, 0.34);
    }

    .runtime-list div {
        padding: 9px;
    }

    .runtime-list dt {
        margin-bottom: 4px;
        font-size: 0.72rem;
        font-weight: 850;
        text-transform: uppercase;
    }

    .runtime-list dd {
        margin: 0;
        color: var(--pg-text);
        font-weight: 790;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .collection-list {
        display: grid;
        gap: 8px;
    }

    .collection-list article {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 8px;
        align-items: center;
        padding: 9px;
    }

    .collection-list strong {
        display: block;
        color: var(--pg-text);
        overflow-wrap: anywhere;
    }

    .collection-list button,
    .danger-action {
        border-color: rgba(251, 113, 133, 0.38);
        color: #fecaca;
    }

    .graph-stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .graph-stats article {
        padding: 10px;
    }

    .graph-stats strong {
        display: block;
        margin-top: 5px;
        color: var(--pg-text);
        font-size: 1.55rem;
        line-height: 1;
    }

    .relationship-list {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 10px;
    }

    .relationship-list span {
        border: 1px solid var(--pg-border);
        border-radius: 999px;
        padding: 5px 8px;
        background: rgba(3, 7, 18, 0.34);
        font-size: 0.76rem;
    }

    @media (max-width: 1440px) {
        .playground-grid {
            grid-template-columns: minmax(320px, 0.9fr) minmax(0, 1.1fr);
        }

        .system-panel {
            grid-column: 2;
            position: static;
            min-height: auto;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }
    }

    @media (max-width: 900px) {
        .playground-grid,
        .source-layout,
        .system-panel {
            grid-template-columns: 1fr;
        }

        .signal-strip {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .composer,
        .system-panel,
        .source-lens {
            position: static;
        }

        .composer,
        .result-stage,
        .system-panel {
            min-height: auto;
        }
    }

    @media (max-width: 560px) {
        .playground-shell {
            padding: 10px;
        }

        .signal-strip,
        .control-grid,
        .graph-facts article,
        .activity-list article {
            grid-template-columns: 1fr;
        }

        .source-item {
            grid-template-columns: 1fr;
        }
    }
</style>
