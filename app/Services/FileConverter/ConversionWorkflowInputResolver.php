<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ConversionWorkflowInputResolver
{
    public function __construct(
        private CrawledFileDiscovery $discovery,
        private ExistingConversionPolicy $existingConversionPolicy,
        private Filesystem $files,
    ) {
    }

    public function outputDir(ConsoleWorkflowIO $io): ?string
    {
        $outputDirArg = $io->argument('outputDir');
        if ($outputDirArg) {
            $outputDir = $this->discovery->resolveOutputDir((string) $outputDirArg);
            if (! $this->files->isDirectory($outputDir)) {
                $io->error("Output dir not found: $outputDir");

                return null;
            }

            return $outputDir;
        }

        if ($this->existingConversionPolicy->automationEnabled() || ! $io->isInteractive()) {
            $io->error('Output dir is required in automation or non-interactive mode.');

            return null;
        }

        return $this->discovery->pickOutputDir($io);
    }
}
