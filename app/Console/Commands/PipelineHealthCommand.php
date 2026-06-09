<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\Health\PipelineHealthService;
use Illuminate\Console\Command;

class PipelineHealthCommand extends Command
{
    protected $signature = 'pipeline:health
        {--timeout=5 : HTTP and connection timeout in seconds}';

    protected $description = 'Check MVP pipeline dependencies and print clear fix suggestions.';

    public function handle(PipelineHealthService $health): int
    {
        $results = $health->check(max(1, (int) $this->option('timeout')));

        $this->line('HAWKI RAG MVP pipeline health');
        $this->newLine();

        foreach ($results as $result) {
            $this->printResult($result);
        }

        $failed = collect($results)->contains(fn (array $result): bool => $result['status'] === 'fail');
        $this->newLine();
        $failed
            ? $this->error('Pipeline health failed. Fix the red checks above before starting a pipeline task.')
            : $this->info('Pipeline health passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function printResult(array $result): void
    {
        $line = sprintf('[%s] %s - %s', strtoupper($result['status']), $result['name'], $result['detail']);

        match ($result['status']) {
            'ok' => $this->info($line),
            'warn' => $this->warn($line),
            default => $this->error($line),
        };

        if ($result['status'] === 'fail' && ($result['fix'] ?? '') !== '') {
            $this->line('      Fix: '.$result['fix']);
        }
    }
}
