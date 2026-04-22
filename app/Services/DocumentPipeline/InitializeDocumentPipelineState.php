<?php

namespace App\Services\DocumentPipeline;

use App\Models\Document;
use App\Models\DocumentProcessingState;

class InitializeDocumentPipelineState
{
    public const STAGES = [
        DocumentProcessingState::STAGE_CONVERT,
        DocumentProcessingState::STAGE_CHUNK,
        DocumentProcessingState::STAGE_EMBED,
        DocumentProcessingState::STAGE_GRAPH_EXTRACT,
        DocumentProcessingState::STAGE_INDEX_VECTOR,
        DocumentProcessingState::STAGE_INDEX_GRAPH,
    ];

    /**
     * @return array{created: array<int, string>, existing: array<int, string>}
     */
    public function handle(Document $document): array
    {
        $created = [];
        $existing = [];

        foreach (self::STAGES as $stage) {
            $state = DocumentProcessingState::firstOrCreate(
                [
                    'document_id' => $document->id,
                    'stage' => $stage,
                ],
                [
                    'state' => DocumentProcessingState::STATE_PENDING,
                ]
            );

            if ($state->wasRecentlyCreated) {
                $created[] = $stage;
                continue;
            }

            $existing[] = $stage;
        }

        return [
            'created' => $created,
            'existing' => $existing,
        ];
    }
}

