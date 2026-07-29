<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Services\Settings\SettingsService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class UploadPipelineFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:102400',
            'dataset_id' => 'nullable|string|max:160|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',
            'datasetId' => 'nullable|string|max:160|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',
            'graph' => 'nullable',
            'converter_mode' => 'nullable|string|in:native,custom',
            'converterMode' => 'nullable|string|in:native,custom',
            'converter_url' => 'nullable|url|max:2048',
            'converterUrl' => 'nullable|url|max:2048',
            'converter_token' => 'nullable|string|max:4096',
            'converterToken' => 'nullable|string|max:4096',
            'converter_start_path' => 'nullable|string|max:160',
            'converterStartPath' => 'nullable|string|max:160',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = (string) ($this->input('converter_mode') ?? $this->input('converterMode') ?? 'native');
            if ($mode !== 'custom') {
                return;
            }

            if ($this->filled('converter_url') || $this->filled('converterUrl')) {
                return;
            }

            if (app(SettingsService::class)->customConverterUploadDefaults()['api_url'] ?? null) {
                return;
            }

            $validator->errors()->add(
                'converter_url',
                'The converter url field is required when converter mode is custom.',
            );
        });
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function uploadInput(): PipelineUploadInput
    {
        return PipelineUploadInput::fromValidated(
            $this->validated(),
            app(SettingsService::class)->customConverterUploadDefaults(),
        );
    }
}
