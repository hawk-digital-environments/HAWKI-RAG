<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentPipeline\InitializeDocumentPipelineState;
use Illuminate\Console\Command;

class InitDocumentState extends Command
{
    protected $signature = 'hawki:init-document-state {documentId : Document UUID}';

    protected $description = 'Initialize document_processing_state rows for a document';

    public function handle(InitializeDocumentPipelineState $initializer): int
    {
        $documentId = (string) $this->argument('documentId');
        $document = Document::query()->find($documentId);

        if (!$document) {
            $this->error("Document not found: {$documentId}");
            return self::FAILURE;
        }

        $result = $initializer->handle($document);

        $created = empty($result['created']) ? 'none' : implode(', ', $result['created']);
        $existing = empty($result['existing']) ? 'none' : implode(', ', $result['existing']);

        $this->info("Document: {$document->id}");
        $this->line("Created stages: {$created}");
        $this->line("Existing stages: {$existing}");

        return self::SUCCESS;
    }
}

