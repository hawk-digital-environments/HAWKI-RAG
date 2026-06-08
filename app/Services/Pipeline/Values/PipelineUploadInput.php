<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Values;

readonly class PipelineUploadInput
{
    private function __construct(
        public string $datasetId,
        public bool $graph,
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromValidated(array $validated): self
    {
        $datasetId = self::stringValue($validated['dataset_id'] ?? $validated['datasetId'] ?? null)
            ?? 'controller-uploads';
        $graph = filter_var($validated['graph'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return new self($datasetId, $graph ?? true);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
