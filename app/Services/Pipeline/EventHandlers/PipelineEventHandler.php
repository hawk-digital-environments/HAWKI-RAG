<?php
declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

interface PipelineEventHandler
{
    /**
     * @return array<int,string>
     */
    public function eventTypes(): array;

    public function handle(array $event): void;

    public function failed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void;
}
