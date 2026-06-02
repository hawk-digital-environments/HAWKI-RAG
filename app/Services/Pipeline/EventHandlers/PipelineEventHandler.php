<?php

namespace App\Services\Pipeline\EventHandlers;

use Throwable;

interface PipelineEventHandler
{
    /**
     * @return array<int,string>
     */
    public function eventTypes(): array;

    public function handle(array $event): void;

    public function failed(array $event, Throwable $error, int $retryCount, int $maxRetries): void;
}
