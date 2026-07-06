<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineDemoCommandInputParser
{
    /**
     * @return array{input: PipelineDemoCommandInput|null, error: string|null}
     */
    public function parse(Command $command): array
    {
        $heapId = $this->stringOption($command, 'heap') ?: 'demo';
        $limit = $this->integerOption($command, 'limit', 5);
        $graph = $this->booleanOption($command, 'graph', true);
        $dryRun = $this->booleanOption($command, 'dry-run', false);
        $force = $this->booleanOption($command, 'force', false);

        if ($limit === null || $limit < 1) {
            return $this->failure('The --limit option must be an integer greater than zero.');
        }

        if ($graph === null) {
            return $this->failure('The --graph option must be true or false.');
        }

        if ($dryRun === null) {
            return $this->failure('The --dry-run option must be true or false.');
        }

        if ($force === null) {
            return $this->failure('The --force option must be true or false.');
        }

        return [
            'input' => new PipelineDemoCommandInput(
                $heapId,
                $limit,
                $graph,
                $dryRun,
                $force,
                $this->explicitUrls($command),
            ),
            'error' => null,
        ];
    }

    /**
     * @return array{input: null, error: string}
     */
    private function failure(string $message): array
    {
        return ['input' => null, 'error' => $message];
    }

    /**
     * @return list<string>
     */
    private function explicitUrls(Command $command): array
    {
        $urls = $command->option('url') ?: [];

        return array_values(array_filter(array_map('strval', (array) $urls)));
    }

    private function stringOption(Command $command, string $name): ?string
    {
        $value = $command->option($name);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function integerOption(Command $command, string $name, int $default): ?int
    {
        $value = $this->stringOption($command, $name);
        if ($value === null) {
            return $default;
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function booleanOption(Command $command, string $name, bool $default): ?bool
    {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($parsed) ? $parsed : null;
    }
}
