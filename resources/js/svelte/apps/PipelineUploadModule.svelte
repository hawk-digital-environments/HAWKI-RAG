<!--
  @component Pipeline controller upload module with native RAGAnything validation and custom converter configuration.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';

    type UploadTone = 'info' | 'warn' | 'error' | 'success';

    interface UploadResponse {
        success?: boolean;
        message?: string;
        taskId?: string;
        jobId?: string;
    }

    interface Props extends HTMLAttributes<HTMLDivElement> {
        /** Browser URL for the pipeline controller upload endpoint. */
        endpoint: string;
        /** CSRF token used by Laravel for same-origin POST requests. */
        csrfToken: string;
        /** Extensions accepted by native RAGAnything ingestion. */
        nativeExtensions: string[];
        /** Preferred extensions advertised by the configured custom converter, when any. */
        customExtensions: string[];
        /** Called after the backend queues a pipeline task. */
        onqueued?: (taskId: string, jobId: string) => void;
    }

    const {
        endpoint,
        csrfToken,
        nativeExtensions,
        customExtensions,
        onqueued,
        class: className = '',
        ...restProps
    }: Props = $props();

    let datasetId = $state('controller-uploads');
    let graph = $state(true);
    let customConverter = $state(false);
    let converterUrl = $state('');
    let converterToken = $state('');
    let converterStartPath = $state('/extract');
    let busy = $state(false);
    let status = $state('Native RAGAnything upload is ready.');
    let tone = $state<UploadTone>('info');
    let fileInput: HTMLInputElement | undefined = $state();

    const normalizedNativeExtensions = $derived(normalizeExtensions(nativeExtensions));
    const normalizedCustomExtensions = $derived(normalizeExtensions(customExtensions));
    const nativeAccept = $derived(normalizedNativeExtensions.map((extension) => `.${extension}`).join(','));
    const nativeSummary = $derived(formatExtensions(normalizedNativeExtensions));
    const customSummary = $derived(
        normalizedCustomExtensions.length > 0
            ? formatExtensions(normalizedCustomExtensions)
            : 'Any file with an extension',
    );
    function normalizeExtensions(values: string[]): string[] {
        return Array.from(new Set(
            values
                .map((value) => String(value || '').trim().toLowerCase().replace(/^\.+/, ''))
                .filter(Boolean),
        ));
    }

    function formatExtensions(values: string[]): string {
        return values.map((extension) => `.${extension}`).join(', ');
    }

    function extensionFor(filename: string): string {
        const parts = filename.trim().toLowerCase().split('.');

        return parts.length > 1 ? parts.at(-1) || '' : '';
    }

    function setStatus(message: string, nextTone: UploadTone = 'info'): void {
        status = message;
        tone = nextTone;
    }

    async function parseResponse(response: Response): Promise<UploadResponse> {
        const body = await response.text();
        if (!body) return {};

        try {
            return JSON.parse(body) as UploadResponse;
        } catch {
            return {
                success: false,
                message: body.trim() || `HTTP ${response.status}`,
            };
        }
    }

    function validateSelection(): boolean {
        if (!fileInput?.files?.length) {
            setStatus('Choose a file before queueing ingestion.', 'warn');
            return false;
        }

        const currentExtension = extensionFor(fileInput.files.item(0)?.name || '');
        if (!customConverter && !normalizedNativeExtensions.includes(currentExtension)) {
            setStatus('This file is outside the RAGAnything native list. Enable Custom converter to continue.', 'warn');
            return false;
        }

        if (customConverter && !converterUrl.trim()) {
            setStatus('Custom converter mode needs an endpoint URL.', 'warn');
            return false;
        }

        return true;
    }

    async function submitUpload(): Promise<void> {
        if (!validateSelection() || !fileInput?.files?.length) return;

        const selectedFile = fileInput.files.item(0);
        if (!selectedFile) {
            setStatus('Choose a file before queueing ingestion.', 'warn');
            return;
        }

        const formData = new FormData();
        formData.set('dataset_id', datasetId.trim() || 'controller-uploads');
        formData.set('graph', graph ? 'true' : 'false');
        formData.set('converter_mode', customConverter ? 'custom' : 'native');
        formData.set('file', selectedFile);

        if (customConverter) {
            formData.set('converter_url', converterUrl.trim());
            formData.set('converter_start_path', converterStartPath.trim() || '/extract');
            formData.set('converter_token', converterToken);
        }

        busy = true;
        setStatus(customConverter ? 'Queueing file through custom converter...' : 'Queueing native RAGAnything ingestion...');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });
            const data = await parseResponse(response);

            if (!response.ok || !data.success || !data.taskId) {
                throw new Error(data.message || `File pipeline start failed (${response.status})`);
            }

            setStatus(`Queued ${customConverter ? 'custom converter' : 'native'} pipeline ${data.jobId || data.taskId}.`, 'success');
            converterToken = '';
            onqueued?.(data.taskId, data.jobId || '');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'File pipeline start failed.', 'error');
        } finally {
            busy = false;
        }
    }
</script>

<div {...restProps} class={['pipeline-upload-module', className].filter(Boolean).join(' ')}>
    <div class="pipeline-panel-head">
        <div>
            <span class="pipeline-kicker">File input</span>
            <h2 id="pipeline-file-input-title">Convert and Ingest File</h2>
        </div>
        <label class="pipeline-upload-switch" for="pipeline-custom-converter">
            <input id="pipeline-custom-converter" type="checkbox" bind:checked={customConverter} />
            <span>Custom converter</span>
        </label>
    </div>

    <form id="pipeline-file-form" class="controller-file-form" enctype="multipart/form-data" onsubmit={(event) => { event.preventDefault(); void submitUpload(); }}>
        <div class="controller-file-grid">
            <div>
                <label for="pipeline-file-dataset">Dataset ID</label>
                <input id="pipeline-file-dataset" name="dataset_id" type="text" bind:value={datasetId} autocomplete="off" />
            </div>
            <div>
                <label for="pipeline-file-input">File</label>
                <input
                    id="pipeline-file-input"
                    name="file"
                    type="file"
                    accept={customConverter ? undefined : nativeAccept}
                    data-supported-extensions={customConverter ? customSummary : normalizedNativeExtensions.join(',')}
                    bind:this={fileInput}
                />
            </div>
            <label class="controller-toggle" for="pipeline-file-graph">
                <input id="pipeline-file-graph" name="graph" type="checkbox" bind:checked={graph} />
                <span>Neo4j graph</span>
            </label>
        </div>

        <div class="pipeline-upload-mode-panel" data-mode={customConverter ? 'custom' : 'native'}>
            {#if customConverter}
                <div class="pipeline-upload-mode-copy">
                    <strong>Custom converter module</strong>
                    <span>{customSummary}</span>
                </div>
                <div class="pipeline-custom-converter-grid">
                    <div>
                        <label for="pipeline-converter-url">Converter API URL</label>
                        <input id="pipeline-converter-url" type="url" bind:value={converterUrl} placeholder="https://converter.example.test" autocomplete="off" />
                    </div>
                    <div>
                        <label for="pipeline-converter-token">API key</label>
                        <input id="pipeline-converter-token" type="password" bind:value={converterToken} autocomplete="off" />
                    </div>
                    <div>
                        <label for="pipeline-converter-start">Start path</label>
                        <input id="pipeline-converter-start" type="text" bind:value={converterStartPath} autocomplete="off" />
                    </div>
                </div>
            {:else}
                <div class="pipeline-upload-mode-copy">
                    <strong>RAGAnything native upload</strong>
                    <span>{nativeSummary}</span>
                </div>
            {/if}
        </div>

        <div class="controller-file-actions">
            <button type="submit" id="pipeline-file-submit" disabled={busy}>
                {busy ? 'Queueing...' : customConverter ? 'Queue with Custom Converter' : 'Queue Native Upload'}
            </button>
            <span id="pipeline-file-note" class="pipeline-task-note" data-tone={tone} aria-live="polite">{status}</span>
        </div>
    </form>
</div>
