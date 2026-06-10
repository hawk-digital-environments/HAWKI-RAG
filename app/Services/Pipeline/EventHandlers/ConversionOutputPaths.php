<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ConversionOutputPaths
{
    public function outputDirectory(string $sourcePath): string
    {
        return dirname($sourcePath).DIRECTORY_SEPARATOR.'converted_'.pathinfo($sourcePath, PATHINFO_FILENAME);
    }

    public function metadataPath(string $sourcePath): string
    {
        return $this->outputDirectory($sourcePath).DIRECTORY_SEPARATOR.'conversion_meta.json';
    }

    public function flatMarkdownPath(string $sourcePath): string
    {
        return dirname($sourcePath).DIRECTORY_SEPARATOR.pathinfo($sourcePath, PATHINFO_FILENAME).'_converted.md';
    }
}
