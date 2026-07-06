<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Corpus;
use App\Services\SpecV2\Repositories\CorpusRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class CorpusSyncService
{
    public function __construct(
        private CorpusRepository $corpora,
        private CorpusContentReader $content,
    ) {}

    public function syncFromDocument(Document $document): Corpus
    {
        $corpusId = (string) $document->checksum_sha256;
        $corpus = $this->corpora->firstOrNew($corpusId);

        if ($corpus->content === null) {
            $corpus->content = $this->content->read($document->storage_path);
        }

        $corpus->reference_count = $this->corpora->referenceCount($corpusId);
        $corpus->metadata_json = is_array($corpus->metadata_json) ? $corpus->metadata_json : [];
        $this->corpora->save($corpus);

        if ($document->corpus_id !== $corpusId) {
            $document->corpus_id = $corpusId;
            $document->save();
        }

        return $corpus;
    }
}
