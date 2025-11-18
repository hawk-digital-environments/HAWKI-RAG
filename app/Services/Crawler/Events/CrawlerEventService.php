<?php

namespace App\Services\Crawler\Events;

use App\Services\Crawler\Data\CrawlerContext;

/**
 * Service for dispatching crawler events throughout the pipeline.
 *
 * This service provides a decoupled way to communicate progress and
 * state changes throughout the crawler pipeline. Different consumers
 * (commands, APIs, queues) can register listeners to receive events
 * without modifying the core service logic.
 */
class CrawlerEventService
{
    /**
     * Registered event listeners.
     *
     * @var array<string, array<callable>>
     */
    private array $listeners = [];

    /**
     * Event history for debugging and tracking.
     *
     * @var array
     */
    private array $eventHistory = [];

    /**
     * Whether to record event history.
     *
     * @var bool
     */
    private bool $recordHistory = false;

    /**
     * Register an event listener.
     *
     * @param string $event Event name
     * @param callable $callback Callback function to execute
     * @return void
     */
    public function on(string $event, callable $callback): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $callback;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @param string $event Event name
     * @param mixed ...$args Arguments to pass to listeners
     * @return void
     */
    public function dispatch(string $event, mixed ...$args): void
    {
        if ($this->recordHistory) {
            $this->recordEvent($event, $args);
        }

        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Remove all listeners for a specific event.
     *
     * @param string $event Event name
     * @return void
     */
    public function removeListeners(string $event): void
    {
        unset($this->listeners[$event]);
    }

    /**
     * Remove all listeners for all events.
     *
     * @return void
     */
    public function clearListeners(): void
    {
        $this->listeners = [];
    }

    /**
     * Enable event history recording.
     *
     * @return void
     */
    public function enableHistory(): void
    {
        $this->recordHistory = true;
    }

    /**
     * Disable event history recording.
     *
     * @return void
     */
    public function disableHistory(): void
    {
        $this->recordHistory = false;
    }

    /**
     * Get event history.
     *
     * @return array
     */
    public function getHistory(): array
    {
        return $this->eventHistory;
    }

    /**
     * Clear event history.
     *
     * @return void
     */
    public function clearHistory(): void
    {
        $this->eventHistory = [];
    }

    /**
     * Record an event in the history.
     *
     * @param string $event Event name
     * @param array $args Event arguments
     * @return void
     */
    private function recordEvent(string $event, array $args): void
    {
        $this->eventHistory[] = [
            'event' => $event,
            'args' => $args,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    // ========================================
    // Standard Event Dispatchers
    // ========================================

    /**
     * Dispatch a stage change event.
     *
     * @param CrawlerContext $context Current context
     * @param string $stage Stage name
     * @return void
     */
    public function stageChanged(CrawlerContext $context, string $stage): void
    {
        $this->dispatch('stage.changed', $context, $stage);
    }

    /**
     * Dispatch a validation started event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function validationStarted(CrawlerContext $context): void
    {
        $this->dispatch('validation.started', $context);
    }

    /**
     * Dispatch a validation completed event.
     *
     * @param CrawlerContext $context Current context
     * @param bool $success Whether validation passed
     * @return void
     */
    public function validationCompleted(CrawlerContext $context, bool $success): void
    {
        $this->dispatch('validation.completed', $context, $success);
    }

    /**
     * Dispatch a configuration started event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function configurationStarted(CrawlerContext $context): void
    {
        $this->dispatch('configuration.started', $context);
    }

    /**
     * Dispatch a configuration completed event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function configurationCompleted(CrawlerContext $context): void
    {
        $this->dispatch('configuration.completed', $context);
    }

    /**
     * Dispatch an existing data found event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function existingDataFound(CrawlerContext $context): void
    {
        $this->dispatch('existing_data.found', $context);
    }

    /**
     * Dispatch an execution started event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function executionStarted(CrawlerContext $context): void
    {
        $this->dispatch('execution.started', $context);
    }

    /**
     * Dispatch an execution progress event.
     *
     * @param CrawlerContext $context Current context
     * @param string $output Progress output
     * @return void
     */
    public function executionProgress(CrawlerContext $context, string $output): void
    {
        $this->dispatch('execution.progress', $context, $output);
    }

    /**
     * Dispatch an execution completed event.
     *
     * @param CrawlerContext $context Current context
     * @param bool $success Whether execution succeeded
     * @return void
     */
    public function executionCompleted(CrawlerContext $context, bool $success): void
    {
        $this->dispatch('execution.completed', $context, $success);
    }

    /**
     * Dispatch a storage started event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function storageStarted(CrawlerContext $context): void
    {
        $this->dispatch('storage.started', $context);
    }

    /**
     * Dispatch a storage completed event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function storageCompleted(CrawlerContext $context): void
    {
        $this->dispatch('storage.completed', $context);
    }

    /**
     * Dispatch a pipeline completed event.
     *
     * @param CrawlerContext $context Current context
     * @return void
     */
    public function pipelineCompleted(CrawlerContext $context): void
    {
        $this->dispatch('pipeline.completed', $context);
    }

    /**
     * Dispatch an error event.
     *
     * @param CrawlerContext $context Current context
     * @param string $message Error message
     * @param \Throwable|null $exception Exception if available
     * @return void
     */
    public function error(CrawlerContext $context, string $message, ?\Throwable $exception = null): void
    {
        $this->dispatch('error', $context, $message, $exception);
    }

    /**
     * Dispatch a warning event.
     *
     * @param CrawlerContext $context Current context
     * @param string $message Warning message
     * @return void
     */
    public function warning(CrawlerContext $context, string $message): void
    {
        $this->dispatch('warning', $context, $message);
    }

    /**
     * Dispatch an info event.
     *
     * @param CrawlerContext $context Current context
     * @param string $message Info message
     * @return void
     */
    public function info(CrawlerContext $context, string $message): void
    {
        $this->dispatch('info', $context, $message);
    }
}
