<?php

declare(strict_types=1);

namespace App\Services\Graph\Exceptions;

final class Neo4jClientException extends \RuntimeException implements GraphExceptionInterface
{
    public static function httpError(int $status, string $body): self
    {
        return new self("Neo4j HTTP error {$status}: {$body}");
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    public static function queryErrors(array $errors): self
    {
        $message = is_array($errors[0] ?? null)
            ? (string) ($errors[0]['message'] ?? 'Neo4j query failed.')
            : 'Neo4j query failed.';

        return new self($message);
    }
}
