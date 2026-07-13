<?php

declare(strict_types=1);

namespace App\Services\Document\Values;

use Illuminate\Support\Str;

readonly class ManagedDocumentId
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self('adoc_'.Str::lower((string) Str::ulid()));
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if (! self::looksLike($normalized)) {
            throw new \InvalidArgumentException(sprintf('Invalid managed document id [%s].', $value));
        }

        return new self($normalized);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromRequestMetadata(array $metadata): ?self
    {
        $value = self::stringValue($metadata['document_id'] ?? $metadata['assistant_document_id'] ?? null);
        if ($value === null || ! self::looksLike($value)) {
            return null;
        }

        return new self($value);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function normalizeRequestMetadata(array $metadata): array
    {
        $documentId = self::fromRequestMetadata($metadata);
        if ($documentId === null) {
            return $metadata;
        }

        return array_merge($metadata, $documentId->toRequestMetadata());
    }

    public static function looksLike(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $normalized = trim((string) $value);

        return $normalized !== ''
            && (
                str_starts_with($normalized, 'adoc_')
                || str_starts_with($normalized, 'adoc-')
                || str_starts_with($normalized, 'doc_')
            );
    }

    /**
     * @return array{document_id: string, assistant_document_id: string}
     */
    public function toRequestMetadata(): array
    {
        return [
            'document_id' => $this->value,
            'assistant_document_id' => $this->value,
        ];
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
