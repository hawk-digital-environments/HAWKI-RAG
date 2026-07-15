<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SettingsDashboardTest extends TestCase
{
    public function test_settings_page_mounts_and_saves_converter_and_model_defaults(): void
    {
        $this->withoutVite();
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);
        $settingsPath = storage_path('framework/testing/settings-dashboard.json');
        File::delete($settingsPath);
        config()->set('config.operator_settings_path', $settingsPath);

        $payload = [
            'customConverter' => [
                'enabled' => true,
                'supportedExtensions' => '.zip, epub',
                'apiUrl' => 'https://converter.example.test',
                'startPath' => 'extract',
                'apiKey' => 'secret-converter-key',
            ],
            'models' => [
                'provider' => 'litellm',
                'graphModel' => 'hawki-gpt-chat',
                'embeddingModel' => 'hawki-openai-embedding',
                'visionModel' => 'hawki-gpt-vision',
            ],
            'providerCredentials' => [
                'openai' => [
                    'apiUrl' => 'https://api.openai.com/v1',
                    'apiKey' => 'openai-key',
                ],
            ],
        ];

        $response = $this->withSession(['_token' => 'test-token'])
            ->putJson('/settings/config', $payload, ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('customConverter.enabled', true)
            ->assertJsonPath('customConverter.apiKeySet', true)
            ->assertJsonPath('customConverter.startPath', '/extract')
            ->assertJsonPath('models.provider', 'litellm')
            ->assertJsonPath('models.graph_model', 'hawki-gpt-chat')
            ->assertJsonPath('models.embedding_model', 'hawki-openai-embedding')
            ->assertJsonPath('models.vision_model', 'hawki-gpt-vision');

        $this->assertStringNotContainsString('secret-converter-key', $response->getContent());
        $this->assertStringNotContainsString('openai-key', $response->getContent());

        $this->assertFileExists($settingsPath);
        $stored = File::get($settingsPath);
        $this->assertStringNotContainsString('secret-converter-key', $stored);
        $this->assertStringNotContainsString('openai-key', $stored);

        $this->get('/settings')
            ->assertOk()
            ->assertSee('HAWKI Settings')
            ->assertSee('data-settings-dashboard', false)
            ->assertSee('settings-dashboard-config', false)
            ->assertSee('"apiKeySet":true', false)
            ->assertDontSee('secret-converter-key', false);

        File::delete($settingsPath);
    }

    public function test_settings_rejects_provider_not_supported_by_runtime(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);
        $settingsPath = storage_path('framework/testing/settings-dashboard-invalid.json');
        File::delete($settingsPath);
        config()->set('config.operator_settings_path', $settingsPath);

        $this->withSession(['_token' => 'test-token'])
            ->putJson('/settings/config', [
                'customConverter' => [],
                'models' => [
                    'provider' => 'openai',
                    'graphModel' => 'gpt-model',
                    'embeddingModel' => 'embed-model',
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('models.provider');
    }

    public function test_settings_accepts_litellm_as_a_runtime_provider(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);
        $settingsPath = storage_path('framework/testing/settings-dashboard-litellm.json');
        File::delete($settingsPath);
        config()->set('config.operator_settings_path', $settingsPath);

        $response = $this->withSession(['_token' => 'test-token'])
            ->putJson('/settings/config', [
                'customConverter' => [],
                'models' => [
                    'provider' => 'litellm',
                    'graphModel' => 'hawki-claude-chat',
                    'embeddingModel' => 'hawki-ollama-embedding',
                    'visionModel' => 'hawki-claude-vision',
                ],
                'providerCredentials' => [
                    'litellm' => [
                        'apiUrl' => 'http://litellm:4000/v1',
                        'apiKey' => 'ignored-litellm-key',
                    ],
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('models.provider', 'litellm')
            ->assertJsonPath('models.graph_model', 'hawki-claude-chat')
            ->assertJsonPath('models.embedding_model', 'hawki-ollama-embedding')
            ->assertJsonPath('models.vision_model', 'hawki-claude-vision')
            ->assertJsonFragment([
                'key' => 'litellm',
                'configurationMode' => 'environment',
                'modelSelectionMode' => 'settings',
                'defaultGraphModel' => 'hawki-ollama-chat',
                'defaultEmbeddingModel' => 'hawki-ollama-embedding',
                'defaultVisionModel' => 'hawki-ollama-vision',
            ]);

        $this->assertStringNotContainsString('ignored-litellm-key', $response->getContent());

        $stored = json_decode(File::get($settingsPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('litellm', $stored['provider_credentials'] ?? []);
        $this->assertStringNotContainsString('ignored-litellm-key', File::get($settingsPath));

        File::delete($settingsPath);
    }

    public function test_settings_rejects_unknown_litellm_aliases(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->withSession(['_token' => 'test-token'])
            ->putJson('/settings/config', [
                'customConverter' => [],
                'models' => [
                    'provider' => 'litellm',
                    'graphModel' => 'unpriced-arbitrary-model',
                    'embeddingModel' => 'hawki-ollama-embedding',
                    'visionModel' => 'hawki-ollama-vision',
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('models.graphModel');
    }
}
