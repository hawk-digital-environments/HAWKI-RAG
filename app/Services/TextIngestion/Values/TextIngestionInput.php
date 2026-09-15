<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

final readonly class TextIngestionInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public string $externalDocumentId,
        public string $datasetId,
        public string $text,
        public TextContentFormat $contentFormat,
        public ?string $displayName,
        public ?string $sourceUrl,
        public array $metadata,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromValidated(array $input): self
    {
        return new self(
            externalDocumentId: $input['external_document_id'],
            datasetId: $input['dataset_id'],
            text: $input['text'],
            contentFormat: TextContentFormat::from($input['content_format']),
            displayName: $input['display_name'] ?? null,
            sourceUrl: $input['source_url'] ?? null,
            metadata: $input['metadata'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_document_id' => $this->externalDocumentId,
            'dataset_id' => $this->datasetId,
            'text' => $this->text,
            'content_format' => $this->contentFormat->value,
            'display_name' => $this->displayName,
            'source_url' => $this->sourceUrl,
            'metadata' => $this->metadata,
        ];
    }
}
