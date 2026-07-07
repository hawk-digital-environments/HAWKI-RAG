<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\SpecV2\Corpus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Corpus */
class CorpusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_count' => $this->reference_count,
            'document_count' => (int) ($this->documents_count ?? 0),
            'content_preview' => $this->content !== null ? mb_substr($this->content, 0, 240) : null,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
