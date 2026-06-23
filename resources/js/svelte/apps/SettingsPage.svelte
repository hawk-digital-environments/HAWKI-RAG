<!--
  @component Operator settings page for custom converter defaults and model runtime choices.
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
    }

    interface ProviderOption {
        key: string;
        label: string;
        runtimeSupported: boolean;
        embeddingSupported: boolean;
        apiUrl: string;
        apiKeySet: boolean;
        defaultGraphModel: string;
        defaultEmbeddingModel: string;
    }

    interface SettingsConfig {
        customConverter?: CustomConverterConfig;
        models?: ModelConfig;
        providers?: ProviderOption[];
        operatorAuthorized?: boolean;
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
        /** Whether the current browser request is allowed to use operator APIs. */
        operatorAuthorized?: boolean;
    }

    const {
        endpoint,
        csrfToken,
        initialConfig,
        operatorAuthorized = false,
        class: className = '',
        ...restProps
    }: Props = $props();

    const fallbackProvider: ProviderOption = {
        key: 'ollama',
        label: 'Ollama',
        runtimeSupported: true,
        embeddingSupported: true,
        apiUrl: '',
        apiKeySet: false,
        defaultGraphModel: '',
        defaultEmbeddingModel: '',
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
        return initialConfig.models?.graph_model || providers[0]?.defaultGraphModel || '';
    }

    function initialEmbeddingModel(): string {
        return initialConfig.models?.embedding_model || providers[0]?.defaultEmbeddingModel || '';
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
        return provider.runtimeSupported ? 'available' : 'not active';
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
        graphModel = payload.models?.graph_model || selectedProvider?.defaultGraphModel || '';
        embeddingModel = payload.models?.embedding_model || selectedProvider?.defaultEmbeddingModel || '';
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
        return Object.fromEntries(providers.map((provider) => {
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
        copy="Converter defaults, provider credentials, and graph extraction model choices."
        active="settings"
    />

    {#if operatorAuthorized}
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
                    <h2 id="settings-model-title">Graph Extraction Runtime</h2>
                    <p>{selectedProvider?.label || 'Provider'} · {providerState(selectedProvider)}</p>
                </div>
            </div>

            <div class="settings-grid">
                <div>
                    <label for="settings-provider">Provider</label>
                    <select id="settings-provider" bind:value={activeProvider}>
                        {#each providers as provider (provider.key)}
                            <option value={provider.key} disabled={!provider.runtimeSupported}>
                                {provider.label} {provider.runtimeSupported ? '' : '(not active)'}
                            </option>
                        {/each}
                    </select>
                </div>
                <div>
                    <label for="settings-graph-model">Graph model</label>
                    <input id="settings-graph-model" type="text" bind:value={graphModel} autocomplete="off" />
                </div>
                <div>
                    <label for="settings-embedding-model">Embedding model</label>
                    <input id="settings-embedding-model" type="text" bind:value={embeddingModel} autocomplete="off" disabled={!selectedProvider?.embeddingSupported} />
                </div>
            </div>
        </section>

        <section class="settings-panel settings-provider-panel" aria-labelledby="settings-provider-credentials-title">
            <div class="section-head">
                <div>
                    <h2 id="settings-provider-credentials-title">Provider Credentials</h2>
                    <p>{providers.length} providers configured</p>
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
                            {#if providerForms[provider.key]?.apiKeySet}
                                <strong>key stored</strong>
                            {/if}
                        </div>
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
                    </article>
                {/each}
            </div>
        </section>

        <div class="settings-actions">
            <button type="submit" disabled={busy}>{busy ? 'Saving...' : 'Save Settings'}</button>
            <span class="settings-status" data-tone={tone} aria-live="polite">{status}</span>
        </div>
    </form>
    {:else}
    <section class="settings-auth-panel" aria-labelledby="settings-auth-required-title">
        <span class="settings-auth-kicker">Operator access required</span>
        <h2 id="settings-auth-required-title">Settings are locked.</h2>
        <p>Sign in with an operator account or enable the explicit local bypass before editing converter defaults, credentials, and runtime models.</p>
    </section>
    {/if}
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

    .settings-auth-panel {
        display: grid;
        gap: 10px;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 12px;
        padding: 20px;
        background: rgba(15, 23, 42, 0.72);
        color: #e2e8f0;
        box-shadow: 0 18px 48px rgba(15, 23, 42, 0.2);
    }

    .settings-auth-panel h2,
    .settings-auth-panel p {
        margin: 0;
    }

    .settings-auth-kicker {
        color: #7dd3fc;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
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
    }
</style>
