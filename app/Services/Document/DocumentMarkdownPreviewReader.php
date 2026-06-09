<?php

declare(strict_types=1);

namespace App\Services\Document;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class DocumentMarkdownPreviewReader
{
    private const PREVIEW_BYTES = 24000;

    public function __construct(
        private Application $app,
        private Filesystem $files,
    ) {
    }

    /**
     * @return array{content:string,path:string|null,error:string|null,truncated:bool}
     */
    public function preview(?string $path): array
    {
        $path = $this->stringValue($path);
        if (! $path) {
            return [
                'content' => '',
                'path' => null,
                'error' => 'No local Markdown path is recorded for this document.',
                'truncated' => false,
            ];
        }

        foreach ($this->pathCandidates($path) as $candidate) {
            if (! $this->files->isFile($candidate)) {
                continue;
            }

            try {
                $content = $this->files->get($candidate);
            } catch (\Throwable) {
                continue;
            }

            $truncated = strlen($content) > self::PREVIEW_BYTES;

            return [
                'content' => $truncated ? substr($content, 0, self::PREVIEW_BYTES) : $content,
                'path' => $candidate,
                'error' => null,
                'truncated' => $truncated,
            ];
        }

        return [
            'content' => '',
            'path' => $path,
            'error' => "Markdown file is not readable from {$path}.",
            'truncated' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function pathCandidates(string $path): array
    {
        if (str_starts_with($path, '/')) {
            return [$path];
        }

        return array_values(array_unique([
            $path,
            $this->app->storagePath('app/'.ltrim($path, '/')),
            $this->app->basePath($path),
        ]));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
