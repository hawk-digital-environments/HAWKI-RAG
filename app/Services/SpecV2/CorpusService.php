<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Corpus;
use App\Services\SpecV2\Exceptions\CorpusNotFoundException;
use App\Services\SpecV2\Payloads\CorpusPayloadBuilder;
use App\Services\SpecV2\Payloads\PaginationPayloadBuilder;
use App\Services\SpecV2\Repositories\CorpusRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class CorpusService
{
    public function __construct(
        private CorpusRepository $corpora,
        private CorpusPayloadBuilder $payloads,
        private PaginationPayloadBuilder $pagination,
    ) {}

    public function list(int $page, int $perPage): array
    {
        $corpora = $this->corpora->paginate($perPage, $page);

        return [
            'data' => $corpora->getCollection()->map(fn (Corpus $corpus): array => $this->payloads->payload($corpus))->all(),
            'pagination' => $this->pagination->payload($corpora),
        ];
    }

    public function show(string $corpusId): array
    {
        $corpus = $this->corpora->findById($corpusId);
        if (! $corpus instanceof Corpus) {
            throw CorpusNotFoundException::withId($corpusId);
        }

        return $this->payloads->payload($corpus);
    }
}
