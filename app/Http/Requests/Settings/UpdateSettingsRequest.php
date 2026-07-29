<?php
declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customConverter' => 'nullable|array',
            'customConverter.enabled' => 'nullable|boolean',
            'customConverter.supportedExtensions' => 'nullable|string|max:2000',
            'customConverter.apiUrl' => 'nullable|url|max:2048',
            'customConverter.startPath' => 'nullable|string|max:160',
            'customConverter.apiKey' => 'nullable|string|max:4096',
            'customConverter.clearApiKey' => 'nullable|boolean',
            'models' => 'required|array',
            'models.provider' => 'required|string|max:80',
            'models.graphModel' => 'nullable|string|max:160',
            'models.embeddingModel' => 'nullable|string|max:160',
            'models.visionModel' => 'nullable|string|max:160',
            'providerCredentials' => 'nullable|array',
            'providerCredentials.*.apiUrl' => 'nullable|url|max:2048',
            'providerCredentials.*.apiKey' => 'nullable|string|max:4096',
            'providerCredentials.*.clearApiKey' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = strtolower(trim((string) $this->input('models.provider', 'ollama')));
            $settings = app(SettingsService::class);

            if (! $settings->supportsRuntimeProvider($provider)) {
                $validator->errors()->add(
                    'models.provider',
                    'This provider is not supported by the current RAG runtime.',
                );

                return;
            }

            $models = [
                'graph' => ['input' => 'models.graphModel', 'label' => 'chat / graph'],
                'embedding' => ['input' => 'models.embeddingModel', 'label' => 'embedding'],
                'vision' => ['input' => 'models.visionModel', 'label' => 'vision'],
            ];
            foreach ($models as $capability => $model) {
                $value = $this->input($model['input']);
                if (! $settings->supportsRuntimeModel($provider, $capability, is_scalar($value) ? (string) $value : null)) {
                    $validator->errors()->add(
                        $model['input'],
                        sprintf('The selected %s model is not an allowed %s alias.', $model['label'], $provider),
                    );
                }
            }
        });
    }
}
