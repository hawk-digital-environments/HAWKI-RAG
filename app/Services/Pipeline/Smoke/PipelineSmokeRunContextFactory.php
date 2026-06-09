<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use App\Services\Pipeline\Exceptions\PipelineSmokeException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineSmokeRunContextFactory
{
    public function __construct(
        private PipelineSmokeExternalVerifier $externalVerifier,
        private Application $app,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function fromIO(ConsoleWorkflowIO $io): PipelineSmokeRunContext
    {
        $taskId = 'smoke_'.$this->clock->now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
        $sourceUrl = $this->stringOption($io, 'url') ?: "https://example.test/hawki-rag-smoke/{$taskId}";

        return new PipelineSmokeRunContext(
            taskId: $taskId,
            datasetId: $this->stringOption($io, 'dataset') ?: 'smoke-demo',
            graph: $this->graphOption($io),
            timeout: max(1, (int) $io->option('timeout')),
            keepFiles: $this->booleanOption($io, 'keep-files', false),
            sourceUrl: $sourceUrl,
            fixtureDir: $this->app->storagePath("app/pipeline-smoke/{$taskId}"),
        );
    }

    private function graphOption(ConsoleWorkflowIO $io): bool
    {
        $value = $this->stringOption($io, 'graph');
        if ($value === null || strtolower($value) === 'auto') {
            return $this->externalVerifier->defaultGraphEnabled();
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (! is_bool($parsed)) {
            throw PipelineSmokeException::invalidGraphOption();
        }

        return $parsed;
    }

    private function stringOption(ConsoleWorkflowIO $io, string $name): ?string
    {
        $value = $io->option($name);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function booleanOption(ConsoleWorkflowIO $io, string $name, bool $default): bool
    {
        $value = $io->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : $default;
    }
}
