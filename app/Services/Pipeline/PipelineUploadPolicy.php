<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class PipelineUploadPolicy
{
    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => ltrim(strtolower(trim((string) $value)), '.'),
            config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']),
        )));
    }

    public function supports(string $extension): bool
    {
        return in_array($this->normalizeExtension($extension), $this->supportedExtensions(), true);
    }

    public function unsupportedMessage(): string
    {
        return 'Unsupported converter input. Supported file types: '
            . implode(', ', $this->supportedExtensions())
            . '.';
    }

    public function normalizeExtension(string $extension): string
    {
        return ltrim(strtolower(trim($extension)), '.');
    }
}
