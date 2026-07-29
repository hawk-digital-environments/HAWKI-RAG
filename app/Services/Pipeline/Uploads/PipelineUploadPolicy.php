<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
class PipelineUploadPolicy
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => ltrim(strtolower(trim((string) $value)), '.'),
            $this->config->get('file_converter.raganything_supported_extensions', []),
        )));
    }

    /**
     * @return list<string>
     */
    public function customConverterPreferredExtensions(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => ltrim(strtolower(trim((string) $value)), '.'),
            $this->config->get('file_converter.supported_extensions', []),
        )));
    }

    public function supports(string $extension, ?PipelineUploadInput $input = null): bool
    {
        if ($input?->usesCustomConverter()) {
            return $this->normalizeExtension($extension) !== '';
        }

        $supportedExtensions = $this->supportedExtensions();

        return in_array($this->normalizeExtension($extension), $supportedExtensions, true);
    }

    public function unsupportedMessage(?PipelineUploadInput $input = null): string
    {
        if ($input?->usesCustomConverter()) {
            return 'Custom converter uploads still need a file extension so the pipeline can preserve type metadata.';
        }

        return 'This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats.';
    }

    public function normalizeExtension(string $extension): string
    {
        return ltrim(strtolower(trim($extension)), '.');
    }
}
