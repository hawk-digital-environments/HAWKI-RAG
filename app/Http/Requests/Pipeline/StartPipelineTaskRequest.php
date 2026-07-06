<?php
declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class StartPipelineTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => 'nullable|string',
            'taskId' => 'nullable|string',
            'heap_id' => 'nullable|string',
            'heapId' => 'nullable|string',
            'dataset_id' => 'nullable|string',
            'datasetId' => 'nullable|string',
            'sitemap_url' => 'nullable|string',
            'sitemapUrl' => 'nullable|string',
            'sitemap_path' => 'nullable|string',
            'sitemapPath' => 'nullable|string',
            'source_url' => 'nullable|string',
            'sourceUrl' => 'nullable|string',
            'urls' => 'nullable',
            'refresh_cadence' => 'nullable|string|in:daily,weekly,monthly',
            'refreshCadence' => 'nullable|string|in:daily,weekly,monthly',
            'cadence' => 'nullable|string|in:daily,weekly,monthly',
            'metadata' => 'nullable|array',
        ];
    }
}
