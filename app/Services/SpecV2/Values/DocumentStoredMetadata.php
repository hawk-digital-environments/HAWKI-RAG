<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Values;

final readonly class DocumentStoredMetadata
{
    public const INTERNAL_KEY = '__rawki';
    public const AUDIT_KEY = 'audit';
    public const AUDIT_SCHEMA_VERSION = 1;

    /**
     * @param array<string, mixed> $publicMetadata
     * @param array{schema: int, heap: string, documentId: string} $audit
     */
    private function __construct(
        public array $publicMetadata,
        public array $audit,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function forDocument(string $heapId, string $documentId, array $metadata): self
    {
        return new self(
            publicMetadata: self::publicMetadata($metadata),
            audit: [
                'schema' => self::AUDIT_SCHEMA_VERSION,
                'heap' => $heapId,
                'documentId' => $documentId,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        unset($metadata[self::INTERNAL_KEY]);

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->publicMetadata,
            self::INTERNAL_KEY => [
                self::AUDIT_KEY => $this->audit,
            ],
        ];
    }
}
