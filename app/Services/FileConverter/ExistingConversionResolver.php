<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ExistingConversionResolver
{
    public function __construct(
        private ExistingConversionPolicy $policy,
        private Filesystem $files,
    ) {
    }

    public function resolve(array $docPaths, ConsoleWorkflowIO $io): ExistingConversionDecision
    {
        $existingMetaCount = $this->countExistingMetadata($docPaths);
        if ($existingMetaCount === 0) {
            return new ExistingConversionDecision(false, false, 0);
        }

        $io->line("Detected {$existingMetaCount} previously converted document(s) in this directory.");
        $choice = $this->policy->resolve((string) $io->option('existing'), $io);

        if ($choice === 'cancel') {
            return new ExistingConversionDecision(true, false, $existingMetaCount);
        }

        if ($choice === 'restart') {
            $io->warn('Restart selected - existing converted outputs will be re-generated.');

            return new ExistingConversionDecision(false, true, $existingMetaCount);
        }

        $io->info('Continuing will skip already converted documents when their hashes match.');

        return new ExistingConversionDecision(false, false, $existingMetaCount);
    }

    private function countExistingMetadata(array $docPaths): int
    {
        $existingMetaCount = 0;
        foreach ($docPaths as $docPath) {
            $destDir = dirname($docPath).'/converted_'.pathinfo($docPath, PATHINFO_FILENAME);
            if ($this->files->isFile($destDir.'/conversion_meta.json')) {
                $existingMetaCount++;
            }
        }

        return $existingMetaCount;
    }
}
