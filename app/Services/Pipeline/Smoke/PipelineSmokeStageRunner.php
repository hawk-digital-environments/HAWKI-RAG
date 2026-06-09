<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;

class PipelineSmokeStageRunner
{
    /**
     * @var array<int, array{status:string, stage:string, message:string}>
     */
    private array $results = [];

    public function __construct(private readonly ConsoleWorkflowIO $io)
    {
    }

    public function stage(string $name, callable $callback, callable $message): mixed
    {
        try {
            $value = $callback();
            $text = $message($value);
            $this->recordPass($name, $text);

            return $value;
        } catch (\Throwable $exception) {
            $this->recordFail($name, $exception->getMessage());
            throw $exception;
        }
    }

    public function skip(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'SKIP', 'stage' => $stage, 'message' => $message];
        $this->io->warn("SKIP {$stage}: {$message}");
    }

    public function printSummary(): void
    {
        $this->io->line('Smoke summary');
        foreach ($this->results as $result) {
            $this->io->line(sprintf(
                '  [%s] %s - %s',
                $result['status'],
                $result['stage'],
                $result['message'],
            ));
        }
        $this->io->newLine();
    }

    private function recordPass(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'PASS', 'stage' => $stage, 'message' => $message];
        $this->io->info("PASS {$stage}: {$message}");
    }

    private function recordFail(string $stage, string $message): void
    {
        $this->results[] = ['status' => 'FAIL', 'stage' => $stage, 'message' => $message];
        $this->io->error("FAIL {$stage}: {$message}");
    }
}
