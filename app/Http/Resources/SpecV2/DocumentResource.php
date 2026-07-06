<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\Document;
use App\Services\SpecV2\DocumentSearchPayloadFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = app(DocumentSearchPayloadFactory::class)->publicMetadata($this->metadata_json);

        return [
            'id' => (string) $this->id,
            'documentId' => (string) $this->id,
            'heapId' => $this->dataset_id,
            'corpusId' => $this->corpus_id,
            'sourceUrl' => $this->source_url,
            'sourceType' => $this->source_type,
            'originalFilename' => $this->original_filename,
            'title' => $this->title,
            'status' => $this->status,
            'metadata' => $metadata,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
