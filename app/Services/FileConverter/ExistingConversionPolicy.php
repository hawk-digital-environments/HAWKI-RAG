<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

readonly class ExistingConversionPolicy
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function automationEnabled(): bool
    {
        return (bool) $this->config->get('config.pipeline_automation', false);
    }

    public function resolve(string $mode, ConsoleWorkflowIO $io): string
    {
        $mode = strtolower(trim($mode));
        $allowed = ['ask', 'continue', 'restart', 'cancel'];
        if (! in_array($mode, $allowed, true)) {
            $io->warn('Invalid --existing value. Continuing and validating cached outputs.');

            return 'continue';
        }

        if ($mode !== 'ask') {
            return $mode;
        }

        if ($this->automationEnabled() || ! $io->isInteractive()) {
            $default = $this->configuredMode();
            $io->info("Automation/non-interactive run detected; using existing output mode '{$default}'.");

            return $default;
        }

        return (string) $io->choice(
            'How would you like to proceed?',
            ['continue', 'restart', 'cancel'],
            0
        );
    }

    private function configuredMode(): string
    {
        $mode = strtolower(trim((string) $this->config->get('config.convert_existing_mode', 'continue')));

        return in_array($mode, ['continue', 'restart', 'cancel'], true) ? $mode : 'continue';
    }
}
