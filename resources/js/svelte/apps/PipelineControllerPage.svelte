<!--
  @component Svelte-owned shell for the pipeline controller page.
-->
<script lang="ts">
    import {onMount} from 'svelte';
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';
    import PipelineUploadModule from './PipelineUploadModule.svelte';

    interface PipelineControllerRuntime {
        selectTask?: (taskId: string) => void;
        refreshRuns?: () => void;
    }

    interface Signal {
        state: string;
        label: string;
        copy: string;
        tone: 'active' | 'next';
    }

    interface CustomConverterDefaults {
        enabled?: boolean;
        supported_extensions?: string[];
        api_url?: string;
        start_path?: string;
        api_key_set?: boolean;
    }

    interface Props extends HTMLAttributes<HTMLElement> {
        /** Browser URL for the pipeline controller upload endpoint. */
        uploadEndpoint: string;
        /** CSRF token used by Laravel for same-origin POST requests. */
        csrfToken: string;
        /** Extensions accepted by native RAGAnything ingestion. */
        nativeExtensions: string[];
        /** Preferred extensions advertised by the configured custom converter, when any. */
        customExtensions: string[];
        /** Saved custom converter defaults from Settings. */
        customConverter?: CustomConverterDefaults;
        /** Called once the static controller DOM is available for the current runtime. */
        onready?: () => void;
    }

    const signals: Signal[] = [
        {
            state: 'Input',
            label: 'Upload or crawl',
            copy: 'Start controlled ingestion from files or scraper tasks.',
            tone: 'active',
        },
        {
            state: 'Flow',
            label: 'Pipeline stages',
            copy: 'Follow scrape, convert, ingest, retry, cancel, and cleanup.',
            tone: 'active',
        },
        {
            state: 'Graph',
            label: 'Retrieval assets',
            copy: 'Prepare data for vector search and graph exploration.',
            tone: 'next',
        },
    ];

    const {
        uploadEndpoint,
        csrfToken,
        nativeExtensions,
        customExtensions,
        customConverter = {},
        onready,
        class: className = '',
        ...restProps
    }: Props = $props();

    onMount(() => {
        onready?.();
    });

    function queued(taskId: string): void {
        const pipelineWindow = window as Window & {hawkiPipelineController?: PipelineControllerRuntime};

        pipelineWindow.hawkiPipelineController?.selectTask?.(taskId);
        pipelineWindow.hawkiPipelineController?.refreshRuns?.();
    }
</script>

<div {...restProps} class={['container', 'pipeline-controller-dashboard', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG controller"
        title="Pipeline Controller"
        copy="Start crawler tasks, upload documents, and follow scrape to convert to ingest chaining."
        active="controller"
    />

    <section class="hawki-rag-page-signal hawki-rag-page-signal--pipeline" aria-label="Ingestion Control route context">
        <div class="hawki-rag-page-signal__copy">
            <p class="hawki-rag-page-signal__kicker">Search. Retrieve. Explore.</p>
            <h2>Ingestion Control</h2>
            <p>Turn files and crawler runs into searchable Qdrant vectors and Neo4j graph signals.</p>
        </div>
        <div class="hawki-rag-page-signal__map" aria-label="HAWKI-RAG service flow">
            {#each signals as signal (signal.state)}
                <article class="hawki-rag-page-signal__node" data-tone={signal.tone}>
                    <span>{signal.state}</span>
                    <strong>{signal.label}</strong>
                    <small>{signal.copy}</small>
                </article>
            {/each}
        </div>
    </section>

    <section class="controller-file-section" aria-labelledby="pipeline-file-input-title">
        <PipelineUploadModule
            endpoint={uploadEndpoint}
            {csrfToken}
            {nativeExtensions}
            {customExtensions}
            {customConverter}
            onqueued={queued}
        />
    </section>

    <section class="pipeline-operations-section">
        <div class="pipeline-hero">
            <div class="pipeline-heading">
                <span class="pipeline-kicker">Scraper Pipeline</span>
                <h2>Pipeline Control</h2>
            </div>
            <div class="pipeline-current-wrap">
                <span id="pipeline-current" class="badge">No pipeline selected.</span>
                <span id="pipeline-job-id" class="pipeline-job-id">Job ID: none</span>
            </div>
        </div>
        <div class="pipeline-workspace">
            <aside class="pipeline-task-panel">
                <div class="pipeline-panel-head">
                    <h3>Scraper Tasks</h3>
                </div>
                <label for="pipeline-task-select">Available task</label>
                <select id="pipeline-task-select">
                    <option value="">Loading scraper tasks...</option>
                </select>
                <div class="pipeline-task-summary">
                    <span><strong id="pipeline-task-count">0</strong> tasks</span>
                    <span id="pipeline-task-source">Source: none</span>
                </div>
                <div id="pipeline-task-detail" class="pipeline-task-detail" hidden></div>
                <div id="pipeline-task-note" class="pipeline-task-note"></div>
                <button type="button" id="pipeline-task-start-btn">Start Pipeline Task</button>

                <div class="pipeline-run-list-block">
                    <div class="pipeline-panel-head">
                        <h3>Pipeline Tasks</h3>
                    </div>
                    <div id="pipeline-run-list" class="pipeline-run-list">
                        <button type="button" disabled>Loading pipeline tasks...</button>
                    </div>
                </div>
            </aside>

            <main class="pipeline-stage-panel">
                <div class="pipeline-stage-header">
                    <div>
                        <h3>Stage State</h3>
                        <p id="pipeline-dataset-path">Dataset path: none</p>
                    </div>
                    <div id="pipeline-updated-at" class="pipeline-updated-at"></div>
                </div>
                <div id="pipeline-task-run" class="pipeline-task-run" hidden></div>
                <div id="pipeline-stages" class="pipeline-stages pipeline-stages-expanded"></div>
                <section class="pipeline-stage-log-panel" aria-labelledby="pipeline-stage-log-title">
                    <div class="pipeline-stage-log-head">
                        <div>
                            <h4 id="pipeline-stage-log-title">Stage logs</h4>
                            <p id="pipeline-stage-log-status">Select Scrape, Convert, or Ingest logs from a stage card.</p>
                        </div>
                    </div>
                    <pre id="pipeline-stage-log-viewer" class="pipeline-stage-log-viewer">No stage log selected.</pre>
                </section>
            </main>
        </div>
    </section>
</div>
