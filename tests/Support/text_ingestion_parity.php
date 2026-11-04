<?php

declare(strict_types=1);
use App\Services\TextIngestion\TextIngestionRequestFingerprint;
use App\Services\TextIngestion\Values\TextIngestionInput;

require __DIR__.'/../../vendor/autoload.php';
$raw = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$input = TextIngestionInput::fromValidated($raw);
$fingerprint = new TextIngestionRequestFingerprint;
echo json_encode(['fingerprint' => $fingerprint->forInput($input),
    'canonical_json' => json_encode((new ReflectionMethod($fingerprint, 'canonicalize'))->invoke($fingerprint, $input->toArray()), JSON_THROW_ON_ERROR),
    'metadata_json' => json_encode($input->metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    'metadata_bytes' => strlen(json_encode($input->metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    'passthrough' => json_encode(['ingestion_mode' => 'direct_text', 'external_document_id' => $input->externalDocumentId,
        'display_name' => $input->displayName, 'content_format' => $input->contentFormat->value, 'metadata' => $input->metadata],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"], JSON_THROW_ON_ERROR);
