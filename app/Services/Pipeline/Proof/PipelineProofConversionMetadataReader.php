<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Proof;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[Singleton]
readonly class PipelineProofConversionMetadataReader
{
    public function __construct(
        private Filesystem $files,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(?string $datasetPath): array
    {
        if ($datasetPath === null || ! $this->files->isDirectory($datasetPath)) {
            return [];
        }

        $files = [];
        foreach ($this->filesUnder($datasetPath) as $file) {
            if ($file->getFilename() !== 'conversion_meta.json') {
                continue;
            }

            $meta = json_decode($this->files->get($file->getPathname()), true);
            $files[] = [
                'path' => $file->getPathname(),
                'pipeline_job_id' => is_array($meta) ? ($meta['pipeline_job_id'] ?? null) : null,
                'converted_id' => is_array($meta) ? ($meta['converted_id'] ?? null) : null,
                'source_file' => is_array($meta) ? ($meta['source_file'] ?? $meta['source_pdf'] ?? null) : null,
                'output_dir' => is_array($meta) ? ($meta['output_dir'] ?? null) : null,
                'files' => is_array($meta) ? ($meta['files'] ?? []) : [],
                'converted_at' => is_array($meta) ? ($meta['converted_at'] ?? null) : null,
            ];
        }

        return $files;
    }

    private function filesUnder(string $path): Finder
    {
        return Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($path);
    }
}
