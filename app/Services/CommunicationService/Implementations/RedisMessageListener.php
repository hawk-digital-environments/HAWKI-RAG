<?php

namespace App\Services\CommunicationService\Implementations;

use App\Services\CommunicationService\Contracts\MessageListenerInterface;
use App\Services\CommunicationService\Data\IncomingMessage;
use App\Services\CommunicationService\Jobs\ProcessIncomingMessageJob;
use Illuminate\Support\Facades\Log;

/**
 * Redis Message Listener Implementation
 *
 * Subscribes to Redis Pub/Sub channels and processes incoming messages.
 * Messages are dispatched to a job queue for asynchronous processing
 * to prevent overloading.
 *
 * Features:
 * - Multiple channel subscription
 * - Automatic reconnection on failure
 * - Graceful shutdown with signal handling
 * - Job queue integration for backpressure
 * - Comprehensive logging
 *
 * Usage:
 * $listener = new RedisMessageListener();
 * $listener->subscribe(['channel-1', 'channel-2']);
 */
class RedisMessageListener implements MessageListenerInterface
{
    protected bool $shouldStop = false;
    protected bool $isRunning = false;
    protected ?\Closure $outputCallback = null;
    protected array $config;
    protected ?\Redis $redis = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => config('database.redis.default.host', '127.0.0.1'),
            'port' => config('database.redis.default.port', 6379),
            'password' => config('database.redis.default.password'),
            'timeout' => 0,
            'reconnect_delay' => 2,
            'max_reconnect_attempts' => 10,
        ], $config);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(array $channels): int
    {
        if (empty($channels)) {
            $this->output("No channels specified for subscription", 'error');
            return 1;
        }

        $this->output('');
        $this->output("Starting Redis Message Listener...", 'info');
        $this->output("Channels: " . implode(', ', $channels), 'info');
        $this->output("Press Ctrl+C to stop", 'info');
        $this->output('');

        $this->setupSignalHandling();

        try {
            $this->connectToRedis();

            $this->output("Connected to Redis successfully!", 'info');
            $this->output("Subscribing to channels...", 'info');

            Log::channel('communication')->info("Starting Redis subscriber on channels: " . implode(', ', $channels));

            $this->isRunning = true;

            // Subscribe to multiple channels
            $this->redis->subscribe($channels, function($redis, $channel, $message) {
                // Handle subscription confirmation (numeric message)
                if (is_numeric($message)) {
                    Log::channel('communication')->debug("Subscribed to channel: {$channel}");
                    return;
                }

                Log::channel('communication')->debug("Received message on channel {$channel}: " . substr($message, 0, 200));

                if ($this->shouldStop) {
                    Log::channel('communication')->info("Stop signal received, unsubscribing...");
                    $redis->unsubscribe();
                    return;
                }

                try {
                    $this->handleMessage($message, $channel);
                } catch (\Exception $e) {
                    Log::channel('communication')->error("Error handling message: " . $e->getMessage(), [
                        'exception' => $e,
                        'channel' => $channel
                    ]);
                }
            });

            $this->isRunning = false;
            $this->output('Subscriber stopped gracefully', 'info');
            Log::channel('communication')->info("Redis subscriber stopped");

            return 0;

        } catch (\Exception $e) {
            $this->isRunning = false;
            $this->output("Fatal error: " . $e->getMessage(), 'error');
            Log::channel('communication')->error("Redis subscriber fatal error: " . $e->getMessage(), [
                'exception' => $e
            ]);

            return 1;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Connect to Redis server.
     *
     * @return void
     * @throws \RedisException
     */
    protected function connectToRedis(): void
    {
        $this->redis = new \Redis();

        Log::channel('communication')->debug("Connecting to Redis: {$this->config['host']}:{$this->config['port']}");

        $this->redis->pconnect(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout']
        );

        // Authenticate if password is set
        if (!empty($this->config['password'])) {
            $this->redis->auth($this->config['password']);
            Log::channel('communication')->debug("Authenticated with Redis");
        }
    }

    /**
     * Disconnect from Redis.
     *
     * @return void
     */
    protected function disconnect(): void
    {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                Log::channel('communication')->warning("Error closing Redis connection: " . $e->getMessage());
            }
            $this->redis = null;
        }
    }

    /**
     * Handle incoming Redis message.
     *
     * @param string $message Raw message payload
     * @param string $channel Redis channel name
     * @return void
     */
    protected function handleMessage(string $message, string $channel): void
    {
        try {
            // Create IncomingMessage DTO
            $incomingMessage = IncomingMessage::fromRaw(
                channel: $channel,
                rawPayload: $message,
                source: 'redis',
                metadata: [
                    'redis_host' => $this->config['host'],
                    'redis_port' => $this->config['port'],
                ]
            );

            // Validate message
            if (!$incomingMessage->isValid()) {
                Log::channel('communication')->warning("Invalid message received on channel {$channel}");
                return;
            }

            // Dispatch to job queue for asynchronous processing
            ProcessIncomingMessageJob::dispatch($incomingMessage);

            Log::channel('communication')->debug("Message dispatched to job queue", [
                'channel' => $channel,
                'source' => 'redis'
            ]);

        } catch (\JsonException $e) {
            Log::channel('communication')->error("Invalid JSON in Redis message: " . $e->getMessage(), [
                'channel' => $channel,
                'message' => substr($message, 0, 500)
            ]);
        } catch (\Exception $e) {
            Log::channel('communication')->error("Error processing Redis message: " . $e->getMessage(), [
                'exception' => $e,
                'channel' => $channel
            ]);
        }
    }

    /**
     * Set up PCNTL signal handling for graceful shutdown.
     *
     * @return void
     */
    protected function setupSignalHandling(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stop());
            pcntl_signal(SIGINT, fn() => $this->stop());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        Log::channel('communication')->info("Stop signal received for Redis listener");
    }

    /**
     * {@inheritDoc}
     */
    public function setOutputCallback(\Closure $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'redis';
    }

    /**
     * Output a message (to console if callback is set, otherwise to log).
     *
     * @param string $message
     * @param string $type 'info'|'error'|'line'
     * @return void
     */
    protected function output(string $message, string $type = 'info'): void
    {
        if ($this->outputCallback) {
            ($this->outputCallback)($message, $type);
        }
    }
}
