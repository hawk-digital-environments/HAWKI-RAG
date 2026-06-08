<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
            'dataset_id' => 'nullable|string|max:160',
            'datasetId' => 'nullable|string|max:160',
            'graph' => 'nullable',
        ];
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function uploadInput(): PipelineUploadInput
    {
        return PipelineUploadInput::fromValidated($this->validated());
    }
}
