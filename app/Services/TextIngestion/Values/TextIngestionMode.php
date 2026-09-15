<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

enum TextIngestionMode: string
{
    case DirectText = 'direct_text';

    /**
     * @param  array<string, mixed>  ...$metadataSets
     */
    public static function isDirectText(array ...$metadataSets): bool
    {
        foreach ($metadataSets as $metadata) {
            if (($metadata['ingestion_mode'] ?? null) === self::DirectText->value) {
                return true;
            }

            $legacy = $metadata['text_ingestion'] ?? null;
            if (
                is_array($legacy)
                && isset($legacy['external_document_id'], $legacy['markdown_path'])
            ) {
                return true;
            }
        }

        return false;
    }
}
