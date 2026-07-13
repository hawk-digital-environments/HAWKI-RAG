<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Values;

use App\Services\Document\Values\ManagedDocumentId;

readonly class PipelineUploadInput
{
    private function __construct(
        public string $datasetId,
        public bool $graph,
        public string $converterMode,
        public ?string $customConverterUrl,
        public ?string $customConverterToken,
        public string $customConverterStartPath,
        public array $requestMetadata,
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromValidated(array $validated, array $customConverterDefaults = []): self
    {
        $datasetId = self::stringValue($validated['dataset_id'] ?? $validated['datasetId'] ?? null)
            ?? 'controller-uploads';
        $graph = filter_var($validated['graph'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $converterMode = self::stringValue($validated['converter_mode'] ?? $validated['converterMode'] ?? null);
        $converterMode = $converterMode === 'custom' ? 'custom' : 'native';
        $defaultUrl = self::stringValue($customConverterDefaults['api_url'] ?? null);
        $defaultStartPath = self::pathValue($customConverterDefaults['start_path'] ?? null) ?? '/extract';

        return new self(
            $datasetId,
            $graph ?? true,
            $converterMode,
            self::stringValue($validated['converter_url'] ?? $validated['converterUrl'] ?? null) ?? $defaultUrl,
            self::stringValue($validated['converter_token'] ?? $validated['converterToken'] ?? null),
            self::pathValue($validated['converter_start_path'] ?? $validated['converterStartPath'] ?? null) ?? $defaultStartPath,
            self::requestMetadata($validated['request_metadata'] ?? $validated['requestMetadata'] ?? null),
        );
    }

    public function usesCustomConverter(): bool
    {
        return $this->converterMode === 'custom';
    }

    /**
     * @return array<string, string>
     */
    public function customConverterProfile(): array
    {
        if (! $this->usesCustomConverter() || $this->customConverterUrl === null) {
            return [];
        }

        return array_filter([
            'converter_url' => $this->customConverterUrl,
            'converter_start_path' => $this->customConverterStartPath,
            'converter_token' => $this->customConverterToken,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function pathValue(mixed $value): ?string
    {
        $path = self::stringValue($value);
        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return ManagedDocumentId::normalizeRequestMetadata($value);
    }
}
