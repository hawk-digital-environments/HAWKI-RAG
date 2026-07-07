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
            'document_id' => (string) $this->id,
            'heap_id' => $this->heapId(),
            'corpus_id' => $this->corpus_id,
            'source_url' => $this->source_url,
            'source_type' => $this->source_type,
            'original_filename' => $this->original_filename,
            'title' => $this->title,
            'status' => $this->status,
            'metadata' => $metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
