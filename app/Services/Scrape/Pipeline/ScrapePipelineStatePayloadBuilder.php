<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Scrape\Data\ScrapeContext;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

#[Singleton]
readonly class ScrapePipelineStatePayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function initialized(ScrapeContext $context): array
    {
        $request = $context->getRequest();

        return [
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'counts' => $this->initialCounts($context),
            'metadata' => [
                'subStage' => 'initialized',
                'request' => $request->toArray(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runningSubStage(string $subStage): array
    {
        return [
            'status' => 'running',
            'metadata' => ['subStage' => $subStage],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function failed(ScrapeContext $context): array
    {
        $request = $context->getRequest();

        return [
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'errors' => $context->getErrors(),
            'warnings' => $context->getWarnings(),
            'metadata' => ['subStage' => $context->getStage()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitted(ScrapeContext $context): array
    {
        $request = $context->getRequest();

        return [
            'status' => 'running',
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'counts' => $this->initialCounts($context),
            'warnings' => $context->getWarnings(),
            'metadata' => [
                'subStage' => $context->getStage(),
                'message' => 'Crawl submitted to Crawl4AI.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function unexpectedFailure(ScrapeContext $context, Throwable $exception): array
    {
        $request = $context->getRequest();

        return [
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'errors' => $context->getErrors(),
            'metadata' => [
                'subStage' => $context->getStage(),
                'exception' => get_class($exception),
            ],
        ];
    }

    public function errorSummary(ScrapeContext $context): string
    {
        return implode('; ', array_map(
            static fn ($error) => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error,
            $context->getErrors()
        ));
    }

    /**
     * @return array{totalPages:int,pagesCrawled:int,failedUrls:int}
     */
    private function initialCounts(ScrapeContext $context): array
    {
        return [
            'totalPages' => $context->getRequest()->maxPages,
            'pagesCrawled' => 0,
            'failedUrls' => 0,
        ];
    }
}
