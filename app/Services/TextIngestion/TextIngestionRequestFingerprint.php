<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Services\TextIngestion\Values\TextIngestionInput;
use Illuminate\Container\Attributes\Singleton;

/**
 * Creates stable direct-text request hashes without changing request data.
 */
#[Singleton]
final readonly class TextIngestionRequestFingerprint
{
    public const VERSION = 2;

    public function forInput(TextIngestionInput $input): string
    {
        return $this->canonicalHash($input->toArray());
    }

    public function jsonHasSameCanonicalValue(string $left, string $right): bool
    {
        return hash_equals(
            $this->canonicalHash(json_decode($left, true, flags: JSON_THROW_ON_ERROR)),
            $this->canonicalHash(json_decode($right, true, flags: JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @param  array<string, mixed>  $taskMetadata
     */
    public function matchesPersisted(
        TextIngestionInput $input,
        array $taskMetadata,
    ): bool {
        $storedHash = $taskMetadata['request_hash'] ?? null;
        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        $canonicalHash = $this->forInput($input);
        if ($this->isCurrent($taskMetadata)) {
            return hash_equals($storedHash, $canonicalHash);
        }
        if (array_key_exists('request_hash_version', $taskMetadata)) {
            return false;
        }
        if (hash_equals($storedHash, $this->hash($input->toArray()))) {
            return true;
        }

        $storedInput = $this->legacyInput(
            $taskMetadata['request'] ?? null,
            $input->text,
        );
        if ($storedInput === null) {
            return false;
        }
        if (! hash_equals($storedHash, $this->hash($storedInput))) {
            return false;
        }

        return hash_equals($canonicalHash, $this->canonicalHash($storedInput));
    }

    /**
     * @param  array<string, mixed>  $taskMetadata
     */
    public function isCurrent(array $taskMetadata): bool
    {
        return ($taskMetadata['request_hash_version'] ?? null) === self::VERSION;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function canonicalHash(mixed $value): string
    {
        return $this->hash($this->canonicalize($value));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyInput(mixed $storedRequest, string $text): ?array
    {
        if (! is_array($storedRequest)) {
            return null;
        }

        foreach (['external_document_id', 'dataset_id', 'content_format'] as $field) {
            if (! array_key_exists($field, $storedRequest)) {
                return null;
            }
        }

        return [
            'external_document_id' => $storedRequest['external_document_id'],
            'dataset_id' => $storedRequest['dataset_id'],
            'text' => $text,
            'content_format' => $storedRequest['content_format'],
            'display_name' => $storedRequest['display_name'] ?? null,
            'source_url' => $storedRequest['source_url'] ?? null,
            'metadata' => $storedRequest['metadata'] ?? [],
        ];
    }
}
