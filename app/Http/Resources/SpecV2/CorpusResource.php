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
            'referenceCount' => $this->reference_count,
            'documentCount' => (int) ($this->documents_count ?? 0),
            'contentPreview' => $this->content !== null ? mb_substr($this->content, 0, 240) : null,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
