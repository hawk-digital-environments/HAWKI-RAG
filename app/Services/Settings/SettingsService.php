<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Services\Settings\Repositories\SettingsFileRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;

#[Singleton]
readonly class SettingsService
{
    public function __construct(
        private SettingsFileRepository $settings,
        private ConfigRepository $config,
        private Encrypter $encrypter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function browserPayload(): array
    {
        $stored = $this->settings->read();

        return [
            'customConverter' => $this->customConverterPublicDefaults($stored),
            'models' => $this->modelRuntime($stored),
            'providers' => $this->providerOptions($stored),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function update(array $validated): array
    {
        $current = $this->settings->read();
        $next = $current;

        $next['custom_converter'] = $this->updatedCustomConverter(
            is_array($current['custom_converter'] ?? null) ? $current['custom_converter'] : [],
            is_array($validated['customConverter'] ?? null) ? $validated['customConverter'] : [],
        );
        $next['models'] = $this->updatedModels(
            is_array($validated['models'] ?? null) ? $validated['models'] : [],
        );
        $next['provider_credentials'] = $this->updatedProviderCredentials(
            is_array($current['provider_credentials'] ?? null) ? $current['provider_credentials'] : [],
            is_array($validated['providerCredentials'] ?? null) ? $validated['providerCredentials'] : [],
        );

        $this->settings->write($next);

        return $this->browserPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function customConverterUploadDefaults(): array
    {
        $stored = $this->settings->read();
        $custom = is_array($stored['custom_converter'] ?? null) ? $stored['custom_converter'] : [];

        return [
            'enabled' => (bool) ($custom['enabled'] ?? false),
            'configured' => $this->stringValue($custom['api_url'] ?? null) !== null,
            'supported_extensions' => $this->normalizeExtensions($custom['supported_extensions'] ?? []),
            'api_url' => $this->stringValue($custom['api_url'] ?? null),
            'start_path' => $this->pathValue($custom['start_path'] ?? null) ?? '/extract',
            'api_key_set' => $this->stringValue($custom['api_key_ciphertext'] ?? null) !== null,
        ];
    }

    public function customConverterToken(): ?string
    {
        $stored = $this->settings->read();
        $custom = is_array($stored['custom_converter'] ?? null) ? $stored['custom_converter'] : [];

        return $this->decryptValue($custom['api_key_ciphertext'] ?? null);
    }

    /**
     * @return array{provider: string, graph_model: ?string, embedding_model: ?string, vision_model: ?string}
     */
    public function modelRuntime(?array $stored = null): array
    {
        $stored ??= $this->settings->read();
        $models = is_array($stored['models'] ?? null) ? $stored['models'] : [];
        $provider = $this->runtimeProvider(
            $this->stringValue($models['provider'] ?? null)
                ?? $this->stringValue($this->config->get('temporal.ingestion.provider'))
                ?? (string) $this->config->get('config.graph_provider', 'litellm'),
        );

        $graphModel = $this->runtimeModel(
            $provider,
            'graph',
            $this->stringValue($models['graph_model'] ?? null),
            $this->defaultGraphModel($provider),
        );
        $embeddingModel = $this->runtimeModel(
            $provider,
            'embedding',
            $this->stringValue($models['embedding_model'] ?? null),
            $this->defaultEmbeddingModel($provider),
        );
        $visionModel = $this->runtimeModel(
            $provider,
            'vision',
            $this->stringValue($models['vision_model'] ?? null),
            $this->defaultVisionModel($provider),
        );

        return [
            'provider' => $provider,
            'graph_model' => $graphModel,
            'embedding_model' => $embeddingModel,
            'vision_model' => $visionModel,
        ];
    }

    public function supportsRuntimeProvider(string $provider): bool
    {
        $providerConfig = $this->providerConfig($provider);

        return filter_var($providerConfig['runtime_supported'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function supportsRuntimeModel(string $provider, string $capability, ?string $model): bool
    {
        $model = $this->stringValue($model);
        if ($model === null) {
            return true;
        }

        return in_array($model, $this->allowedModels($provider, $capability), true);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function customConverterPublicDefaults(array $stored): array
    {
        $custom = is_array($stored['custom_converter'] ?? null) ? $stored['custom_converter'] : [];
        $extensions = $this->normalizeExtensions(
            $custom['supported_extensions'] ?? $this->config->get('file_converter.supported_extensions', []),
        );

        return [
            'enabled' => (bool) ($custom['enabled'] ?? false),
            'supportedExtensions' => $extensions,
            'supportedExtensionsText' => implode(', ', array_map(static fn (string $extension): string => '.'.$extension, $extensions)),
            'apiUrl' => $this->stringValue($custom['api_url'] ?? null) ?? '',
            'startPath' => $this->pathValue($custom['start_path'] ?? null) ?? '/extract',
            'apiKeySet' => $this->stringValue($custom['api_key_ciphertext'] ?? null) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function updatedCustomConverter(array $current, array $input): array
    {
        $next = [
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'supported_extensions' => $this->normalizeExtensions($input['supportedExtensions'] ?? []),
            'api_url' => $this->stringValue($input['apiUrl'] ?? null),
            'start_path' => $this->pathValue($input['startPath'] ?? null) ?? '/extract',
        ];

        $apiKey = $this->stringValue($input['apiKey'] ?? null);
        if ($apiKey !== null) {
            $next['api_key_ciphertext'] = $this->encryptValue($apiKey);
        } elseif (filter_var($input['clearApiKey'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($next['api_key_ciphertext']);
        } elseif ($this->stringValue($current['api_key_ciphertext'] ?? null) !== null) {
            $next['api_key_ciphertext'] = $current['api_key_ciphertext'];
        }

        return array_filter($next, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function updatedModels(array $input): array
    {
        $provider = $this->runtimeProvider($this->stringValue($input['provider'] ?? null) ?? 'litellm');

        return array_filter([
            'provider' => $provider,
            'graph_model' => $this->runtimeModel(
                $provider,
                'graph',
                $this->stringValue($input['graphModel'] ?? null),
                $this->defaultGraphModel($provider),
            ),
            'embedding_model' => $this->runtimeModel(
                $provider,
                'embedding',
                $this->stringValue($input['embeddingModel'] ?? null),
                $this->defaultEmbeddingModel($provider),
            ),
            'vision_model' => $this->runtimeModel(
                $provider,
                'vision',
                $this->stringValue($input['visionModel'] ?? null),
                $this->defaultVisionModel($provider),
            ),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function updatedProviderCredentials(array $current, array $input): array
    {
        $next = $current;
        foreach ($this->providerKeys() as $provider) {
            $providerConfig = $this->providerConfig($provider);
            if (($this->stringValue($providerConfig['configuration_mode'] ?? null) ?? 'settings') !== 'settings') {
                unset($next[$provider]);
                continue;
            }

            $providerInput = is_array($input[$provider] ?? null) ? $input[$provider] : [];
            $existing = is_array($current[$provider] ?? null) ? $current[$provider] : [];
            $apiUrl = $this->stringValue($providerInput['apiUrl'] ?? null);
            $updated = array_filter([
                'api_url' => $apiUrl,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $apiKey = $this->stringValue($providerInput['apiKey'] ?? null);
            if ($apiKey !== null) {
                $updated['api_key_ciphertext'] = $this->encryptValue($apiKey);
            } elseif (filter_var($providerInput['clearApiKey'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                unset($updated['api_key_ciphertext']);
            } elseif ($this->stringValue($existing['api_key_ciphertext'] ?? null) !== null) {
                $updated['api_key_ciphertext'] = $existing['api_key_ciphertext'];
            }

            if ($updated !== []) {
                $next[$provider] = $updated;
            }
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function providerOptions(array $stored): array
    {
        $credentials = is_array($stored['provider_credentials'] ?? null) ? $stored['provider_credentials'] : [];

        return array_map(function (string $provider) use ($credentials): array {
            $providerConfig = $this->providerConfig($provider);
            $storedCredentials = is_array($credentials[$provider] ?? null) ? $credentials[$provider] : [];
            $models = is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [];
            $configurationMode = $this->stringValue($providerConfig['configuration_mode'] ?? null) ?? 'stored';
            $applicationManaged = $configurationMode === 'settings';
            $modelSelectionMode = $this->stringValue($providerConfig['model_selection_mode'] ?? null) ?? 'settings';
            $embeddingSupported = $this->stringValue($models['embedding'] ?? null) !== null;

            return [
                'key' => $provider,
                'label' => $this->stringValue($providerConfig['label'] ?? null) ?? ucfirst($provider),
                'description' => $this->stringValue($providerConfig['description'] ?? null) ?? '',
                'configurationMode' => $configurationMode,
                'modelSelectionMode' => $modelSelectionMode,
                'runtimeSupported' => filter_var($providerConfig['runtime_supported'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'embeddingSupported' => $embeddingSupported,
                'apiUrl' => ($applicationManaged ? $this->stringValue($storedCredentials['api_url'] ?? null) : null)
                    ?? $this->stringValue($providerConfig['api_url'] ?? null)
                    ?? '',
                'apiKeySet' => $applicationManaged
                    && $this->stringValue($storedCredentials['api_key_ciphertext'] ?? null) !== null,
                'defaultGraphModel' => $this->defaultGraphModel($provider) ?? '',
                'defaultEmbeddingModel' => $embeddingSupported ? ($this->defaultEmbeddingModel($provider) ?? '') : '',
                'defaultVisionModel' => $this->defaultVisionModel($provider) ?? '',
                'graphModelPlaceholder' => $this->modelPlaceholder($providerConfig, 'graph'),
                'embeddingModelPlaceholder' => $this->modelPlaceholder($providerConfig, 'embedding'),
                'visionModelPlaceholder' => $this->modelPlaceholder($providerConfig, 'vision'),
                'graphModelOptions' => $this->modelOptions($providerConfig, 'graph'),
                'embeddingModelOptions' => $this->modelOptions($providerConfig, 'embedding'),
                'visionModelOptions' => $this->modelOptions($providerConfig, 'vision'),
                'environmentVariables' => $this->environmentVariables($providerConfig),
            ];
        }, $this->providerKeys());
    }

    private function runtimeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if ($this->supportsRuntimeProvider($provider)) {
            return $provider;
        }

        foreach ($this->providerKeys() as $candidate) {
            if ($this->supportsRuntimeProvider($candidate)) {
                return $candidate;
            }
        }

        return 'litellm';
    }

    /**
     * @return array<int, string>
     */
    private function providerKeys(): array
    {
        $providers = $this->config->get('model_providers.providers', []);

        return is_array($providers) ? array_keys($providers) : ['litellm'];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(string $provider): array
    {
        $providers = $this->config->get('model_providers.providers', []);
        $providerConfig = is_array($providers) ? ($providers[$provider] ?? []) : [];

        return is_array($providerConfig) ? $providerConfig : [];
    }

    private function defaultGraphModel(string $provider): ?string
    {
        $providerConfig = $this->providerConfig($provider);
        $models = is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [];

        return $this->stringValue($models['graph'] ?? null)
            ?? $this->stringValue($models['rag'] ?? null)
            ?? $this->stringValue($this->config->get('config.graph_default'));
    }

    private function defaultEmbeddingModel(string $provider): ?string
    {
        $providerConfig = $this->providerConfig($provider);
        $models = is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [];

        return $this->stringValue($models['embedding'] ?? null)
            ?? $this->stringValue($this->config->get('config.embedding_default'));
    }

    private function defaultVisionModel(string $provider): ?string
    {
        $providerConfig = $this->providerConfig($provider);
        $models = is_array($providerConfig['models'] ?? null) ? $providerConfig['models'] : [];

        return $this->stringValue($models['multimodal'] ?? null);
    }

    private function runtimeModel(
        string $provider,
        string $capability,
        ?string $candidate,
        ?string $default,
    ): ?string {
        if ($candidate !== null && $this->supportsRuntimeModel($provider, $capability, $candidate)) {
            return $candidate;
        }

        return $default !== null && $this->supportsRuntimeModel($provider, $capability, $default)
            ? $default
            : null;
    }

    /**
     * @return list<string>
     */
    private function allowedModels(string $provider, string $capability): array
    {
        $providerConfig = $this->providerConfig($provider);
        $allowed = is_array($providerConfig['allowed_models'] ?? null)
            ? ($providerConfig['allowed_models'][$capability] ?? [])
            : [];

        if (! is_array($allowed)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->stringValue($value),
            $allowed,
        ))));
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function modelPlaceholder(array $providerConfig, string $capability): string
    {
        $placeholders = is_array($providerConfig['model_placeholders'] ?? null)
            ? $providerConfig['model_placeholders']
            : [];

        return $this->stringValue($placeholders[$capability] ?? null) ?? '';
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return list<array{value:string,label:string}>
     */
    private function modelOptions(array $providerConfig, string $capability): array
    {
        $options = is_array($providerConfig['model_options'] ?? null)
            ? ($providerConfig['model_options'][$capability] ?? [])
            : [];
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = $this->stringValue($option['value'] ?? null);
            if ($value === null || ! in_array($value, $this->allowedModelsFromConfig($providerConfig, $capability), true)) {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'label' => $this->stringValue($option['label'] ?? null) ?? $value,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return list<string>
     */
    private function allowedModelsFromConfig(array $providerConfig, string $capability): array
    {
        $allowedModels = is_array($providerConfig['allowed_models'] ?? null)
            ? ($providerConfig['allowed_models'][$capability] ?? [])
            : [];

        return is_array($allowedModels)
            ? array_values(array_filter(array_map(fn (mixed $value): ?string => $this->stringValue($value), $allowedModels)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return list<array{name:string,placeholder:string,description:string,secret:bool,configured:bool}>
     */
    private function environmentVariables(array $providerConfig): array
    {
        $variables = is_array($providerConfig['environment_variables'] ?? null)
            ? $providerConfig['environment_variables']
            : [];
        $normalized = [];

        foreach ($variables as $variable) {
            if (! is_array($variable)) {
                continue;
            }

            $name = $this->stringValue($variable['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'placeholder' => $this->stringValue($variable['placeholder'] ?? null) ?? '',
                'description' => $this->stringValue($variable['description'] ?? null) ?? '',
                'secret' => filter_var($variable['secret'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'configured' => filter_var($variable['configured'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeExtensions(mixed $value): array
    {
        $values = is_array($value)
            ? $value
            : (preg_split('/[\s,]+/', is_scalar($value) ? (string) $value : '') ?: []);

        $normalized = [];
        foreach ($values as $extension) {
            $extension = ltrim(strtolower(trim((string) $extension)), '.');
            if ($extension !== '') {
                $normalized[$extension] = $extension;
            }
        }

        return array_values($normalized);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function pathValue(mixed $value): ?string
    {
        $path = $this->stringValue($value);
        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function encryptValue(string $value): string
    {
        return $this->encrypter->encrypt($value);
    }

    private function decryptValue(mixed $value): ?string
    {
        $ciphertext = $this->stringValue($value);
        if ($ciphertext === null) {
            return null;
        }

        try {
            $decrypted = $this->encrypter->decrypt($ciphertext);
        } catch (\Throwable) {
            return null;
        }

        return $this->stringValue($decrypted);
    }
}
