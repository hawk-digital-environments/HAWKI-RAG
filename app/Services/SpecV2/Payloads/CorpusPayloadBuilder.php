<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use App\Models\SpecV2\Corpus;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class CorpusPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Corpus $corpus): array
    {
        return [
            'id' => $corpus->id,
            'referenceCount' => $corpus->reference_count,
            'documentCount' => (int) ($corpus->documents_count ?? 0),
            'contentPreview' => $corpus->content !== null ? mb_substr($corpus->content, 0, 240) : null,
            'metadata' => $corpus->metadata_json ?? [],
            'createdAt' => $corpus->created_at?->toIso8601String(),
            'updatedAt' => $corpus->updated_at?->toIso8601String(),
        ];
    }
}
