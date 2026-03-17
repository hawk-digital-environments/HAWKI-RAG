<?php

namespace App\Services\CommunicationService\Contracts;

/**
 * Message Listener Interface
 *
 * Defines the contract for communication service implementations
 * that can subscribe to message channels/queues and handle incoming messages.
 *
 * Implementations can include Redis Pub/Sub, RabbitMQ, Kafka, etc.
 */
interface MessageListenerInterface
{
    /**
     * Subscribe to one or more channels/queues and start listening for messages.
     * This method will typically block and run indefinitely until stopped.
     *
     * @param array $channels Array of channel/queue names to subscribe to
     * @return int Exit code (0 for success, non-zero for failure)
     */
    public function subscribe(array $channels): int;

    /**
     * Stop the listener gracefully.
     *
     * @return void
     */
    public function stop(): void;

    /**
     * Set output callback for console messages and logging.
     *
     * @param \Closure $callback Callback function that receives (string $message, string $type)
     * @return self
     */
    public function setOutputCallback(\Closure $callback): self;

    /**
     * Check if the listener is currently running.
     *
     * @return bool
     */
    public function isRunning(): bool;

    /**
     * Get the name of this communication service implementation.
     *
     * @return string (e.g., 'redis', 'rabbitmq', 'kafka')
     */
    public function getName(): string;
}
