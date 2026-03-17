<?php

namespace App\Services\CommunicationService;

use App\Services\CommunicationService\Contracts\MessageListenerInterface;
use App\Services\CommunicationService\Implementations\RabbitMQMessageListener;
use App\Services\CommunicationService\Implementations\RedisMessageListener;
use InvalidArgumentException;

/**
 * Communication Service Factory
 *
 * Factory pattern implementation for creating message listener instances.
 * Supports multiple communication service types (Redis, RabbitMQ, etc.)
 * based on configuration.
 *
 * Usage:
 * $listener = CommunicationServiceFactory::create('redis');
 * $listener->subscribe(['channel-name']);
 */
class CommunicationServiceFactory
{
    /**
     * Registry of available listener implementations.
     *
     * @var array<string, class-string<MessageListenerInterface>>
     */
    protected static array $listeners = [
        'redis' => RedisMessageListener::class,
        'rabbitmq' => RabbitMQMessageListener::class,
    ];

    /**
     * Create a message listener instance.
     *
     * @param string|null $type Type of listener ('redis', 'rabbitmq', etc.). Defaults to config value.
     * @param array $config Additional configuration options
     * @return MessageListenerInterface
     * @throws InvalidArgumentException
     */
    public static function create(?string $type = null, array $config = []): MessageListenerInterface
    {
        $type = $type ?? config('communication.default', 'redis');

        if (!isset(self::$listeners[$type])) {
            throw new InvalidArgumentException(
                "Unknown communication service type: {$type}. Available types: " .
                implode(', ', array_keys(self::$listeners))
            );
        }

        $class = self::$listeners[$type];

        return new $class($config);
    }

    /**
     * Register a custom listener implementation.
     *
     * @param string $type
     * @param class-string<MessageListenerInterface> $class
     * @return void
     */
    public static function register(string $type, string $class): void
    {
        if (!is_subclass_of($class, MessageListenerInterface::class)) {
            throw new InvalidArgumentException(
                "Class {$class} must implement MessageListenerInterface"
            );
        }

        self::$listeners[$type] = $class;
    }

    /**
     * Get all registered listener types.
     *
     * @return array<string>
     */
    public static function getAvailableTypes(): array
    {
        return array_keys(self::$listeners);
    }

    /**
     * Check if a listener type is registered.
     *
     * @param string $type
     * @return bool
     */
    public static function hasType(string $type): bool
    {
        return isset(self::$listeners[$type]);
    }
}
