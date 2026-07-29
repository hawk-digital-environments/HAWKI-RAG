<!--
  @component Admin settings page for custom converter defaults and model runtime choices.
-->
<script lang="ts">
    import type {HTMLAttributes} from 'svelte/elements';
    import DashboardHeader from '../components/DashboardHeader.svelte';
    import HawkiRagBackground from '../components/HawkiRagBackground.svelte';

    type SettingsTone = 'info' | 'warn' | 'error' | 'success';

    interface CustomConverterConfig {
        enabled?: boolean;
        supportedExtensionsText?: string;
        apiUrl?: string;
        startPath?: string;
        apiKeySet?: boolean;
    }

    interface ModelConfig {
        provider?: string;
        graph_model?: string;
        embedding_model?: string;
        vision_model?: string;
    }

    interface ModelOption {
        value: string;
        label: string;
    }

    interface EnvironmentVariable {
        name: string;
        placeholder: string;
        description: string;
        secret: boolean;
        configured: boolean;
    }

    interface ProviderOption {
        key: string;
        label: string;
        runtimeSupported: boolean;
        embeddingSupported: boolean;
        configurationMode?: string;
        modelSelectionMode?: string;
        description?: string;
        apiUrl: string;
        apiKeySet: boolean;
        defaultGraphModel: string;
        defaultEmbeddingModel: string;
        defaultVisionModel?: string;
        graphModelPlaceholder?: string;
        embeddingModelPlaceholder?: string;
        visionModelPlaceholder?: string;
        graphModelOptions?: ModelOption[];
        embeddingModelOptions?: ModelOption[];
        visionModelOptions?: ModelOption[];
        environmentVariables?: EnvironmentVariable[];
    }

    interface SettingsConfig {
        customConverter?: CustomConverterConfig;
        models?: ModelConfig;
        providers?: ProviderOption[];
    }

    interface ProviderForm {
        apiUrl: string;
        apiKey: string;
        clearApiKey: boolean;
        apiKeySet: boolean;
    }

    interface SettingsResponse extends SettingsConfig {
        message?: string;
    }

    interface Props extends HTMLAttributes<HTMLElement> {
        /** JSON endpoint used to persist settings. */
        endpoint: string;
        /** CSRF token used by Laravel for same-origin PUT requests. */
        csrfToken: string;
        /** Initial settings payload rendered by Laravel. */
        initialConfig: SettingsConfig;
    }

    const {
        endpoint,
        csrfToken,
        initialConfig,
        class: className = '',
        ...restProps
    }: Props = $props();

    const fallbackProvider: ProviderOption = {
        key: 'ollama',
        label: 'Ollama (Direct)',
        runtimeSupported: true,
        embeddingSupported: true,
        configurationMode: 'environment',
        modelSelectionMode: 'settings',
        description: '',
        apiUrl: '',
        apiKeySet: false,
        defaultGraphModel: '',
        defaultEmbeddingModel: '',
        defaultVisionModel: '',
        graphModelPlaceholder: '',
        embeddingModelPlaceholder: '',
        visionModelPlaceholder: '',
        graphModelOptions: [],
        embeddingModelOptions: [],
        visionModelOptions: [],
        environmentVariables: [],
    };

    let providers = $state<ProviderOption[]>(initialProviders());
    let customEnabled = $state(initialCustomEnabled());
    let customExtensionsText = $state(initialCustomExtensionsText());
    let customApiUrl = $state(initialCustomApiUrl());
    let customStartPath = $state(initialCustomStartPath());
    let customApiKey = $state('');
    let customClearApiKey = $state(false);
    let customApiKeySet = $state(initialCustomApiKeySet());
    let activeProvider = $state(initialActiveProvider());
    let graphModel = $state(initialGraphModel());
    let embeddingModel = $state(initialEmbeddingModel());
    let visionModel = $state(initialVisionModel());
    let providerForms = $state<Record<string, ProviderForm>>(initialProviderForms());
    let busy = $state(false);
    let status = $state('Settings are loaded.');
    let tone = $state<SettingsTone>('info');

    const selectedProvider = $derived(
        providers.find((provider) => provider.key === activeProvider) || providers[0] || fallbackProvider,
    );

    function initialProviders(): ProviderOption[] {
        return normalizedProviders(initialConfig.providers);
    }

    function initialCustomEnabled(): boolean {
        return Boolean(initialConfig.customConverter?.enabled);
    }

    function initialCustomExtensionsText(): string {
        return initialConfig.customConverter?.supportedExtensionsText || '';
    }

    function initialCustomApiUrl(): string {
        return initialConfig.customConverter?.apiUrl || '';
    }

    function initialCustomStartPath(): string {
        return initialConfig.customConverter?.startPath || '/extract';
    }

    function initialCustomApiKeySet(): boolean {
        return Boolean(initialConfig.customConverter?.apiKeySet);
    }

    function initialActiveProvider(): string {
        return initialConfig.models?.provider || providers[0]?.key || 'ollama';
    }

    function initialGraphModel(): string {
        const provider = providers.find((item) => item.key === initialActiveProvider()) || providers[0];
        if (provider && isModelSelectionManaged(provider)) {
            return provider.defaultGraphModel || '';
        }

        return initialConfig.models?.graph_model || provider?.defaultGraphModel || '';
    }

    function initialEmbeddingModel(): string {
        const provider = providers.find((item) => item.key === initialActiveProvider()) || providers[0];
        if (provider && isModelSelectionManaged(provider)) {
            return provider.defaultEmbeddingModel || '';
        }

        return initialConfig.models?.embedding_model || provider?.defaultEmbeddingModel || '';
    }

    function initialVisionModel(): string {
        const provider = providers.find((item) => item.key === initialActiveProvider()) || providers[0];
        if (provider && isModelSelectionManaged(provider)) {
            return provider.defaultVisionModel || '';
        }

        return initialConfig.models?.vision_model || provider?.defaultVisionModel || '';
    }

    function initialProviderForms(): Record<string, ProviderForm> {
        return providerFormDefaults(providers);
    }

    function normalizedProviders(value: ProviderOption[] | undefined): ProviderOption[] {
        const items = Array.isArray(value) ? value : [];
        if (items.length > 0) {
            return items;
        }

        return [fallbackProvider];
    }

    function providerFormDefaults(items: ProviderOption[]): Record<string, ProviderForm> {
        return Object.fromEntries(items.map((provider) => [
            provider.key,
            {
                apiUrl: provider.apiUrl || '',
                apiKey: '',
                clearApiKey: false,
                apiKeySet: Boolean(provider.apiKeySet),
            },
        ]));
    }

    function setStatus(message: string, nextTone: SettingsTone = 'info'): void {
        status = message;
        tone = nextTone;
    }

    function providerState(provider: ProviderOption): string {
        if (provider.runtimeSupported && provider.key === 'ollama') return 'default runtime available';
        if (provider.runtimeSupported && provider.key === 'litellm') return 'optional runtime available';
        if (provider.runtimeSupported) return 'runtime available';
        if (provider.configurationMode === 'proxy') {
            const secretVariables = (provider.environmentVariables || []).filter((variable) => variable.secret);
            if (secretVariables.length === 0) return 'local proxy upstream';

            return secretVariables.every((variable) => variable.configured)
                ? 'proxy upstream configured'
                : 'proxy upstream · key missing';
        }

        return 'not active';
    }

    function isEnvironmentManaged(provider: ProviderOption): boolean {
        return provider.configurationMode !== undefined
            ? provider.configurationMode !== 'settings'
            : provider.key === 'litellm';
    }

    function isModelSelectionManaged(provider: ProviderOption): boolean {
        return provider.modelSelectionMode !== undefined
            ? provider.modelSelectionMode !== 'settings'
            : isEnvironmentManaged(provider);
    }

    function selectProvider(providerKey: string): void {
        const provider = providers.find((item) => item.key === providerKey);
        if (!provider || !provider.runtimeSupported) return;

        activeProvider = provider.key;
        graphModel = provider.defaultGraphModel || '';
        embeddingModel = provider.embeddingSupported ? provider.defaultEmbeddingModel || '' : '';
        visionModel = provider.defaultVisionModel || '';
        setStatus(`${provider.label} selected. Save settings to activate this runtime.`);
    }

    function providerApiKeyPlaceholder(provider: ProviderOption): string {
        const form = providerForms[provider.key];

        return form?.apiKeySet ? 'Stored API key will be kept' : 'API key';
    }

    function applyResponse(payload: SettingsResponse): void {
        providers = normalizedProviders(payload.providers);
        customEnabled = Boolean(payload.customConverter?.enabled);
        customExtensionsText = payload.customConverter?.supportedExtensionsText || '';
        customApiUrl = payload.customConverter?.apiUrl || '';
        customStartPath = payload.customConverter?.startPath || '/extract';
        customApiKey = '';
        customClearApiKey = false;
        customApiKeySet = Boolean(payload.customConverter?.apiKeySet);
        activeProvider = payload.models?.provider || providers[0]?.key || 'ollama';
        const provider = providers.find((item) => item.key === activeProvider) || providers[0] || fallbackProvider;
        graphModel = isModelSelectionManaged(provider)
            ? provider.defaultGraphModel || ''
            : payload.models?.graph_model || provider.defaultGraphModel || '';
        embeddingModel = isModelSelectionManaged(provider)
            ? provider.defaultEmbeddingModel || ''
            : payload.models?.embedding_model || provider.defaultEmbeddingModel || '';
        visionModel = isModelSelectionManaged(provider)
            ? provider.defaultVisionModel || ''
            : payload.models?.vision_model || provider.defaultVisionModel || '';
        providerForms = providerFormDefaults(providers);
    }

    async function parseResponse(response: Response): Promise<SettingsResponse> {
        const body = await response.text();
        if (!body) return {};

        try {
            return JSON.parse(body) as SettingsResponse;
        } catch {
            return {message: body.trim() || `HTTP ${response.status}`};
        }
    }

    function credentialPayload(): Record<string, {apiUrl: string; apiKey: string; clearApiKey: boolean}> {
        return Object.fromEntries(providers.filter((provider) => !isEnvironmentManaged(provider)).map((provider) => {
            const form = providerForms[provider.key] || {
                apiUrl: '',
                apiKey: '',
                clearApiKey: false,
                apiKeySet: false,
            };

            return [provider.key, {
                apiUrl: form.apiUrl.trim(),
                apiKey: form.apiKey,
                clearApiKey: form.clearApiKey,
            }];
        }));
    }

    async function saveSettings(): Promise<void> {
        busy = true;
        setStatus('Saving settings...');

        try {
            const response = await fetch(endpoint, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    customConverter: {
                        enabled: customEnabled,
                        supportedExtensions: customExtensionsText,
                        apiUrl: customApiUrl.trim(),
                        startPath: customStartPath.trim() || '/extract',
                        apiKey: customApiKey,
                        clearApiKey: customClearApiKey,
                    },
                    models: {
                        provider: activeProvider,
                        graphModel: graphModel.trim(),
                        embeddingModel: embeddingModel.trim(),
                        visionModel: visionModel.trim(),
                    },
                    providerCredentials: credentialPayload(),
                }),
            });
            const payload = await parseResponse(response);

            if (!response.ok) {
                throw new Error(payload.message || `Settings save failed (${response.status})`);
            }

            applyResponse(payload);
            setStatus('Settings saved.', 'success');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Settings save failed.', 'error');
        } finally {
            busy = false;
        }
    }
</script>

<main {...restProps} class={['container', 'settings-dashboard', 'hawki-page-shell', className].filter(Boolean).join(' ')}>
    <HawkiRagBackground />

    <DashboardHeader
        eyebrow="HAWKI RAG settings"
        title="Settings"
        copy="Converter defaults, direct Ollama, optional LiteLLM, and graph extraction model choices."
        active="settings"
    />

    <form class="settings-layout" onsubmit={(event) => { event.preventDefault(); void saveSettings(); }}>
        <section class="settings-panel" aria-labelledby="settings-converter-title">
            <div class="section-head">
                <div>
                    <h2 id="settings-converter-title">Custom Converter</h2>
                    <p>{customApiKeySet ? 'API key stored' : 'No API key stored'}</p>
                </div>
                <label class="settings-switch" for="settings-custom-converter-enabled">
                    <input id="settings-custom-converter-enabled" type="checkbox" bind:checked={customEnabled} />
                    <span>Enabled</span>
                </label>
            </div>

            <div class="settings-grid">
                <div>
                    <label for="settings-custom-converter-extensions">File extensions</label>
                    <input id="settings-custom-converter-extensions" type="text" bind:value={customExtensionsText} placeholder="Any file with an extension" autocomplete="off" />
                </div>
                <div>
                    <label for="settings-custom-converter-url">Converter API URL</label>
                    <input id="settings-custom-converter-url" type="url" bind:value={customApiUrl} placeholder="https://converter.example.test" autocomplete="off" />
                </div>
                <div>
                    <label for="settings-custom-converter-key">API key</label>
                    <input id="settings-custom-converter-key" type="password" bind:value={customApiKey} placeholder={customApiKeySet ? 'Stored API key will be kept' : 'API key'} autocomplete="off" />
                </div>
                <div>
                    <label for="settings-custom-converter-path">Start path</label>
                    <input id="settings-custom-converter-path" type="text" bind:value={customStartPath} placeholder="/extract" autocomplete="off" />
                </div>
            </div>

            {#if customApiKeySet}
                <label class="settings-inline-check" for="settings-custom-converter-clear-key">
                    <input id="settings-custom-converter-clear-key" type="checkbox" bind:checked={customClearApiKey} />
                    <span>Clear stored API key</span>
                </label>
            {/if}
        </section>

        <section class="settings-panel" aria-labelledby="settings-model-title">
            <div class="section-head">
                <div>
                    <h2 id="settings-model-title">RAG Model Runtime</h2>
                    <p>{selectedProvider?.label || 'Provider'} · {providerState(selectedProvider)}</p>
                </div>
            </div>

            <div class="settings-grid">
                <div>
                    <label for="settings-provider">Provider</label>
                    <select
                        id="settings-provider"
                        value={activeProvider}
                        onchange={(event) => selectProvider(event.currentTarget.value)}
                    >
                        {#each providers as provider (provider.key)}
                            <option value={provider.key} disabled={!provider.runtimeSupported}>
                                {provider.label} {provider.runtimeSupported ? '' : '(not active)'}
                            </option>
                        {/each}
                    </select>
                </div>
                <div>
                    <label for="settings-graph-model">Chat / graph model</label>
                    <input
                        id="settings-graph-model"
                        type="text"
                        bind:value={graphModel}
                        list="settings-graph-model-options"
                        placeholder={selectedProvider.graphModelPlaceholder || 'hawki-ollama-chat'}
                        autocomplete="off"
                        disabled={isModelSelectionManaged(selectedProvider)}
                    />
                    <datalist id="settings-graph-model-options">
                        {#each selectedProvider.graphModelOptions || [] as option (option.value)}
                            <option value={option.value}>{option.label}</option>
                        {/each}
                    </datalist>
                </div>
                <div>
                    <label for="settings-embedding-model">Embedding model</label>
                    <input
                        id="settings-embedding-model"
                        type="text"
                        bind:value={embeddingModel}
                        list="settings-embedding-model-options"
                        placeholder={selectedProvider.embeddingModelPlaceholder || 'hawki-ollama-embedding'}
                        autocomplete="off"
                        disabled={!selectedProvider?.embeddingSupported || isModelSelectionManaged(selectedProvider)}
                    />
                    <datalist id="settings-embedding-model-options">
                        {#each selectedProvider.embeddingModelOptions || [] as option (option.value)}
                            <option value={option.value}>{option.label}</option>
                        {/each}
                    </datalist>
                </div>
                <div>
                    <label for="settings-vision-model">Vision model</label>
                    <input
                        id="settings-vision-model"
                        type="text"
                        bind:value={visionModel}
                        list="settings-vision-model-options"
                        placeholder={selectedProvider.visionModelPlaceholder || 'hawki-ollama-vision'}
                        autocomplete="off"
                        disabled={isModelSelectionManaged(selectedProvider)}
                    />
                    <datalist id="settings-vision-model-options">
                        {#each selectedProvider.visionModelOptions || [] as option (option.value)}
                            <option value={option.value}>{option.label}</option>
                        {/each}
                    </datalist>
                </div>
            </div>

            <p class="settings-model-safety">
                Provider selection is explicit: an unavailable LiteLLM gateway fails visibly and never falls back to Ollama. Chat and vision models apply immediately. The embedding model is captured when a dataset is created; existing datasets require re-ingestion to change vector models.
            </p>

            {#if isEnvironmentManaged(selectedProvider)}
                <aside class="settings-runtime-note" data-provider={selectedProvider.key}>
                    <div class="settings-runtime-note__head">
                        <strong>Deployment-managed connection</strong>
                        <span>Connectivity is not checked on this page</span>
                    </div>
                    <p>
                        {selectedProvider.description || `${selectedProvider.label} is configured by the server environment.`}
                        This page may select only deployment-allowlisted model identifiers, but it does not expose or change runtime endpoints or provider API keys.
                    </p>
                    <dl class="settings-runtime-facts">
                        <div>
                            <dt>Endpoint</dt>
                            <dd><code>{selectedProvider.apiUrl || 'Not reported'}</code></dd>
                        </div>
                        <div>
                            <dt>Chat / graph model</dt>
                            <dd><code>{graphModel || selectedProvider.defaultGraphModel || 'Not reported'}</code></dd>
                        </div>
                        <div>
                            <dt>Embedding model</dt>
                            <dd><code>{embeddingModel || selectedProvider.defaultEmbeddingModel || 'Not reported'}</code></dd>
                        </div>
                        {#if selectedProvider.defaultVisionModel}
                            <div>
                                <dt>Vision model</dt>
                                <dd><code>{visionModel || selectedProvider.defaultVisionModel}</code></dd>
                            </div>
                        {/if}
                    </dl>
                    <p class="settings-runtime-note__hint">
                        Update the deployment environment and recreate Python services to change endpoints or direct models. Recreate LiteLLM only when the optional gateway is enabled.
                    </p>
                </aside>
            {/if}
        </section>

        <section class="settings-panel settings-provider-panel" aria-labelledby="settings-provider-credentials-title">
            <div class="section-head">
                <div>
                    <h2 id="settings-provider-credentials-title">Model Provider Connections</h2>
                    <p>Direct Ollama and optional LiteLLM · secrets never leave the deployment environment</p>
                </div>
            </div>

            <div class="settings-provider-list">
                {#each providers as provider (provider.key)}
                    <article class="settings-provider-card" data-supported={provider.runtimeSupported}>
                        <div class="settings-provider-card__head">
                            <div>
                                <h3>{provider.label}</h3>
                                <span>{providerState(provider)}</span>
                            </div>
                            {#if isEnvironmentManaged(provider)}
                                <strong>deployment managed</strong>
                            {:else if providerForms[provider.key]?.apiKeySet}
                                <strong>key stored</strong>
                            {/if}
                        </div>
                        {#if isEnvironmentManaged(provider)}
                            <div class="settings-managed-connection">
                                <p>{provider.description || `${provider.label} connection details are supplied by the deployment environment.`}</p>
                                <div>
                                    <span>Endpoint</span>
                                    <code>{provider.apiUrl || 'Not reported'}</code>
                                </div>
                                {#if provider.environmentVariables?.length}
                                    <dl class="settings-environment-list">
                                        {#each provider.environmentVariables as variable (variable.name)}
                                            <div>
                                                <dt>
                                                    <code>{variable.name}</code>
                                                    {#if variable.secret}
                                                        <span data-configured={variable.configured}>
                                                            {variable.configured ? 'configured' : 'not configured'}
                                                        </span>
                                                    {/if}
                                                </dt>
                                                <dd>
                                                    <code>{variable.placeholder}</code>
                                                    {#if variable.description}<small>{variable.description}</small>{/if}
                                                </dd>
                                            </div>
                                        {/each}
                                    </dl>
                                {/if}
                            </div>
                        {:else}
                            <div class="settings-grid">
                                <div>
                                    <label for={`settings-provider-url-${provider.key}`}>API URL</label>
                                    <input id={`settings-provider-url-${provider.key}`} type="url" bind:value={providerForms[provider.key].apiUrl} autocomplete="off" />
                                </div>
                                <div>
                                    <label for={`settings-provider-key-${provider.key}`}>API key</label>
                                    <input id={`settings-provider-key-${provider.key}`} type="password" bind:value={providerForms[provider.key].apiKey} placeholder={providerApiKeyPlaceholder(provider)} autocomplete="off" />
                                </div>
                            </div>
                            {#if providerForms[provider.key]?.apiKeySet}
                                <label class="settings-inline-check" for={`settings-provider-clear-${provider.key}`}>
                                    <input id={`settings-provider-clear-${provider.key}`} type="checkbox" bind:checked={providerForms[provider.key].clearApiKey} />
                                    <span>Clear stored API key</span>
                                </label>
                            {/if}
                        {/if}
                    </article>
                {/each}
            </div>
        </section>

        <div class="settings-actions">
            <button type="submit" disabled={busy}>{busy ? 'Saving...' : 'Save Settings'}</button>
            <span class="settings-status" data-tone={tone} aria-live="polite">{status}</span>
        </div>
    </form>
</main>

<style>
    .settings-dashboard {
        display: grid;
        gap: 1.1rem;
    }

    .settings-layout {
        display: grid;
        gap: 1rem;
    }

    .settings-panel,
    .settings-provider-card {
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 0.75rem;
        background: rgba(15, 23, 42, 0.74);
        box-shadow: 0 18px 40px rgba(2, 6, 23, 0.16);
        padding: 1rem;
    }

    .settings-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .settings-grid > div {
        display: grid;
        gap: 0.4rem;
    }

    .settings-grid label,
    .settings-inline-check,
    .settings-switch {
        color: #bfdbfe;
        font-size: 0.86rem;
        font-weight: 700;
    }

    .settings-grid input,
    .settings-grid select {
        min-width: 0;
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 0.55rem;
        background: rgba(2, 6, 23, 0.46);
        color: #e5eefb;
        padding: 0.7rem 0.75rem;
    }

    .settings-grid input:disabled {
        opacity: 0.55;
    }

    .settings-switch,
    .settings-inline-check {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
    }

    .settings-provider-list {
        display: grid;
        gap: 0.85rem;
    }

    .settings-provider-card {
        background: rgba(8, 13, 26, 0.46);
        box-shadow: none;
    }

    .settings-provider-card__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.8rem;
    }

    .settings-provider-card h3 {
        margin: 0;
        color: #f8fafc;
        font-size: 1rem;
    }

    .settings-provider-card span,
    .settings-provider-card strong,
    .settings-panel p {
        margin: 0;
        color: #93c5fd;
        font-size: 0.82rem;
    }

    .settings-provider-card[data-supported="false"] {
        border-color: rgba(251, 191, 36, 0.3);
    }

    .settings-runtime-note {
        display: grid;
        gap: 0.75rem;
        margin-top: 0.95rem;
        border: 1px solid rgba(56, 189, 248, 0.28);
        border-radius: 0.65rem;
        padding: 0.85rem;
        background: rgba(8, 47, 73, 0.24);
    }

    .settings-runtime-note p,
    .settings-managed-connection p {
        margin: 0;
        color: #cbd5e1;
        font-size: 0.86rem;
        line-height: 1.5;
    }

    .settings-runtime-note__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.45rem 1rem;
    }

    .settings-runtime-note__head strong {
        color: #e0f2fe;
        font-size: 0.9rem;
    }

    .settings-runtime-note__head span,
    .settings-runtime-note__hint {
        color: #7dd3fc !important;
        font-size: 0.78rem !important;
    }

    .settings-runtime-facts {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.65rem;
        margin: 0;
    }

    .settings-runtime-facts > div,
    .settings-managed-connection > div {
        display: grid;
        gap: 0.3rem;
        min-width: 0;
    }

    .settings-runtime-facts dt,
    .settings-managed-connection span {
        color: #93c5fd;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .settings-runtime-facts dd {
        min-width: 0;
        margin: 0;
    }

    .settings-runtime-facts code,
    .settings-managed-connection code {
        display: block;
        overflow-wrap: anywhere;
        color: #f8fafc;
        font-size: 0.82rem;
    }

    .settings-managed-connection {
        display: grid;
        gap: 0.65rem;
    }

    .settings-model-safety {
        margin-top: 0.8rem !important;
        color: #fcd34d !important;
        line-height: 1.45;
    }

    .settings-environment-list {
        display: grid;
        gap: 0.55rem;
        margin: 0;
    }

    .settings-environment-list > div {
        display: grid;
        grid-template-columns: minmax(190px, 0.7fr) minmax(0, 1.3fr);
        gap: 0.5rem 0.8rem;
        border-top: 1px solid rgba(148, 163, 184, 0.15);
        padding-top: 0.55rem;
    }

    .settings-environment-list dt,
    .settings-environment-list dd {
        min-width: 0;
        margin: 0;
    }

    .settings-environment-list dt {
        display: grid;
        align-content: start;
        gap: 0.25rem;
    }

    .settings-environment-list dt span {
        color: #fca5a5;
        font-size: 0.72rem;
    }

    .settings-environment-list dt span[data-configured="true"] {
        color: #86efac;
    }

    .settings-environment-list dd {
        display: grid;
        gap: 0.25rem;
    }

    .settings-environment-list small {
        color: #94a3b8;
        line-height: 1.35;
    }

    .settings-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .settings-status {
        color: #bfdbfe;
        font-size: 0.9rem;
    }

    .settings-status[data-tone="success"] {
        color: #86efac;
    }

    .settings-status[data-tone="warn"] {
        color: #fde68a;
    }

    .settings-status[data-tone="error"] {
        color: #fecaca;
    }

    @media (max-width: 760px) {
        .settings-grid {
            grid-template-columns: 1fr;
        }

        .settings-environment-list > div {
            grid-template-columns: 1fr;
        }
    }
</style>
