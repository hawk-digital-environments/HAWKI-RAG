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
                'provider' => 'ollama',
                'graphModel' => 'llama3.2:3b',
                'embeddingModel' => 'bge-m3',
            ],
            'providerCredentials' => [
                'ollama' => [
                    'apiUrl' => 'http://hawki_ollama:11434/api',
                    'apiKey' => 'ollama-key',
                ],
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
            ->assertJsonPath('models.provider', 'ollama')
            ->assertJsonPath('models.graph_model', 'llama3.2:3b')
            ->assertJsonPath('models.embedding_model', 'bge-m3');

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
}
