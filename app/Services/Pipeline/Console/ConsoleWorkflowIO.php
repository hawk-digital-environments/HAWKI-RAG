<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

readonly class ConsoleWorkflowIO
{
    public function __construct(
        private Command $command,
        private ?OutputInterface $output = null,
        private bool $interactive = false,
    ) {
    }

    public function argument(string $name): mixed
    {
        return $this->command->argument($name);
    }

    public function option(string $name): mixed
    {
        return $this->command->option($name);
    }

    /**
     * @param array<int, string> $choices
     */
    public function choice(string $question, array $choices, mixed $default = null): mixed
    {
        return $this->command->choice($question, $choices, $default);
    }

    public function line(string $message): void
    {
        $this->command->line($message);
    }

    public function info(string $message): void
    {
        $this->command->info($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function newLine(int $count = 1): void
    {
        $this->command->newLine($count);
    }

    public function output(): OutputInterface
    {
        return $this->output ?? $this->command->getOutput();
    }

    public function createProgressBar(int $max): ProgressBar
    {
        $output = $this->output();

        if (method_exists($output, 'createProgressBar')) {
            return $output->createProgressBar($max);
        }

        return new ProgressBar($output, $max);
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }
}
