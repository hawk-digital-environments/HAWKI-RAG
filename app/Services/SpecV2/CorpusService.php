<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Corpus;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\CorpusNotFoundException;
use App\Services\SpecV2\Repositories\CorpusRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class CorpusService
{
    public function __construct(
        private CorpusRepository $corpora,
        private ApiActorScopeService $actors,
    ) {}

    public function list(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->corpora->paginate($this->actors->currentCorpusIds(), $perPage, $page);
    }

    public function show(string $corpusId): Corpus
    {
        $corpus = $this->corpora->findById($corpusId);
        if (! $corpus instanceof Corpus) {
            throw CorpusNotFoundException::withId($corpusId);
        }

        if (! $this->actors->currentCanReadCorpus($corpus)) {
            throw AccessDeniedException::forAction('read', 'corpus', $corpusId);
        }

        return $corpus;
    }
}
