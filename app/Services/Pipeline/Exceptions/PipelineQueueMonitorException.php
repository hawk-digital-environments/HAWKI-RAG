<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

class PipelineQueueMonitorException extends \RuntimeException implements PipelineExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missingManagementUrl(): self
    {
        return new self('RABBITMQ_MANAGEMENT_URL is empty. Set the RabbitMQ management URL before checking pipeline queues.');
    }

    public static function unsuccessfulResponse(string $url, int $status): self
    {
        return new self("HTTP {$status} from {$url}/api/queues. Verify RabbitMQ management is running and credentials are valid.");
    }

    public static function invalidQueuePayload(): self
    {
        return new self('RabbitMQ management API returned an invalid queue payload. Expected a JSON array of queue objects.');
    }
}
