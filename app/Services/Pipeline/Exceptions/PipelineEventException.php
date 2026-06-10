<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

class PipelineEventException extends \RuntimeException implements PipelineExceptionInterface
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function delayedPublishRequiresEventType(): self
    {
        return new self('Delayed pipeline event publish requires event_type. Pass a normalized pipeline event before requesting a delayed retry.');
    }

    public static function recoveryRetryRequiresEventType(): self
    {
        return new self('Pipeline recovery retry requires event_type. Pass a normalized pipeline event before requesting operator recovery.');
    }

    public static function payloadMustBeJsonObject(): self
    {
        return new self('Pipeline event payload must be a JSON object.');
    }

    public static function unknownWorker(string $worker): self
    {
        return new self("Unknown pipeline event worker [{$worker}]. Configure communication.rabbitmq.pipeline_events.workers before declaring topology.");
    }
}
