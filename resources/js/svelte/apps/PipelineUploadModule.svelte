<!--
  @component Pipeline controller upload module with native RAGAnything validation and settings-backed custom converter support.
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

    interface CustomConverterDefaults {
        enabled?: boolean;
        configured?: boolean;
        supported_extensions?: string[];
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
        /** Saved custom converter defaults from Settings. */
        customConverter?: CustomConverterDefaults;
        /** Called after the backend queues a pipeline task. */
        onqueued?: (taskId: string, jobId: string) => void;
    }

    const {
        endpoint,
        csrfToken,
        nativeExtensions,
        customExtensions,
        customConverter = {},
        onqueued,
        class: className = '',
        ...restProps
    }: Props = $props();

    let datasetId = $state('controller-uploads');
    let graph = $state(true);
    let busy = $state(false);
    let status = $state(initialStatus());
    let tone = $state<UploadTone>('info');
    let fileInput: HTMLInputElement | undefined = $state();

    const normalizedNativeExtensions = $derived(normalizeExtensions(nativeExtensions));
    const normalizedCustomExtensions = $derived(
        normalizeExtensions(customConverter.supported_extensions?.length ? customConverter.supported_extensions : customExtensions),
    );
    const customConverterMode = $derived(Boolean(customConverter.enabled && customConverter.configured));
    const nativeAccept = $derived(normalizedNativeExtensions.map((extension) => `.${extension}`).join(','));
    const customSummary = $derived(
        normalizedCustomExtensions.length > 0
            ? formatExtensions(normalizedCustomExtensions)
            : '',
    );

    function initialStatus(): string {
        return customConverter.enabled && customConverter.configured
            ? 'Settings custom converter upload is ready.'
            : 'Native RAGAnything upload is ready.';
    }
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
        if (!customConverterMode && !normalizedNativeExtensions.includes(currentExtension)) {
            setStatus('This file is outside the RAGAnything native list. Configure Custom converter in Settings to continue.', 'warn');
            return false;
        }

        if (customConverter.enabled && !customConverter.configured) {
            setStatus('Custom converter needs its endpoint configured in Settings.', 'warn');
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
        formData.set('converter_mode', customConverterMode ? 'custom' : 'native');
        formData.set('file', selectedFile);

        busy = true;
        setStatus(customConverterMode ? 'Queueing file through custom converter...' : 'Queueing native RAGAnything ingestion...');

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

            setStatus(`Queued ${customConverterMode ? 'custom converter' : 'native'} pipeline ${data.jobId || data.taskId}.`, 'success');
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
                    accept={customConverterMode ? undefined : nativeAccept}
                    data-supported-extensions={customConverterMode ? customSummary : normalizedNativeExtensions.join(',')}
                    bind:this={fileInput}
                />
            </div>
            <label class="controller-toggle" for="pipeline-file-graph">
                <input id="pipeline-file-graph" name="graph" type="checkbox" bind:checked={graph} />
                <span>Neo4j graph</span>
            </label>
        </div>

        <div class="controller-file-actions">
            <button type="submit" id="pipeline-file-submit" disabled={busy}>
                {busy ? 'Queueing...' : customConverterMode ? 'Queue with Custom Converter' : 'Queue Native Upload'}
            </button>
            <span id="pipeline-file-note" class="pipeline-task-note" data-tone={tone} aria-live="polite">{status}</span>
        </div>
    </form>
</div>
