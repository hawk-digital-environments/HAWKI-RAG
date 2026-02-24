<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;

/**
 * Shared helpers for MCP tools (HTTP calls + input normalization).
 */
/**
 * Shared helpers for MCP tools (HTTP calls + input normalization).
 */
final class McpToolHelpers
{
    private function __construct()
    {
    }

    /**
     * Normalize a boolean-like input into true/false or null.
     */
    public static function normalizeBool(mixed $value, bool $default = true): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            return $default;
        }
        return $parsed;
    }

    /**
     * Clamp an integer to the provided bounds.
     */
    public static function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Return a trimmed string or empty string if null.
     */
    public static function trimString(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * Return array if value is array, otherwise empty array.
     */
    public static function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Return HAWKI RAG bridge base URL from env (fallback to docker service name).
     */
    public static function hawkiRagBridgeBaseUrl(): string
    {
        return rtrim((string) config('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');
    }

    /**
     * Return Qdrant base URL from env (fallback to docker service name).
     */
    public static function qdrantBaseUrl(): string
    {
        return rtrim((string) config('config.qdrant_http_url', 'http://qdrant:6333'), '/');
    }

    /**
     * POST JSON with a timeout and return the response.
     */
    public static function postJson(string $url, array $payload, int $timeoutSeconds = 60)
    {
        return Http::timeout($timeoutSeconds)->post($url, $payload);
    }
}
