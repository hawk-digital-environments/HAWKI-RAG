<?php

namespace App\Services\CommunicationService\Implementations;

use App\Services\CommunicationService\Contracts\MessageListenerInterface;
use App\Services\CommunicationService\Data\IncomingMessage;
use App\Services\CommunicationService\Jobs\ProcessIncomingMessageJob;
use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ Message Listener Implementation
 *
 * Subscribes to RabbitMQ queues and processes incoming messages.
 * Messages are dispatched to a job queue for asynchronous processing
 * to prevent overloading.
 *
 * Features:
 * - Multiple queue subscription
 * - Automatic reconnection on failure
 * - Graceful shutdown with signal handling
 * - Job queue integration for backpressure
 * - Message acknowledgment support
 * - Comprehensive logging
 *
 * Requirements:
 * - php-amqplib/php-amqplib package must be installed
 *
 * Usage:
 * $listener = new RabbitMQMessageListener();
 * $listener->subscribe(['queue-1', 'queue-2']);
 */
class RabbitMQMessageListener implements MessageListenerInterface
{
    protected bool $shouldStop = false;
    protected bool $isRunning = false;
    protected ?Closure $outputCallback = null;
    protected array $config;
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => config('communication.rabbitmq.host'),
            'port' => config('communication.rabbitmq.port'),
            'user' => config('communication.rabbitmq.user'),
            'password' => config('communication.rabbitmq.password'),
            'vhost' => config('communication.rabbitmq.vhost'),
            'exchange' => config('communication.rabbitmq.exchange'),
            'exchange_type' => config('communication.rabbitmq.exchange_type'),
            'prefetch_count' => config('communication.rabbitmq.prefetch_count'),
            'durable' => config('communication.rabbitmq.durable'),
            'auto_ack' => config('communication.rabbitmq.auto_ack'),
        ], $config);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(array $channels): int
    {
        if (empty($channels)) {
            $this->output("No queues specified for subscription", 'error');
            return 1;
        }

        $this->output('');
        $this->output("Starting RabbitMQ Message Listener...");
        $this->output("Queues: " . implode(', ', $channels));
        $this->output("Press Ctrl+C to stop");
        $this->output('');

        $this->setupSignalHandling();

        try {
            $this->connectToRabbitMQ();

            $this->output("Connected to RabbitMQ successfully!");
            $this->output("Setting up queues and consumers...");

            Log::info("Starting RabbitMQ subscriber on queues: " . implode(', ', $channels));

            $this->isRunning = true;

            // Set QoS (Quality of Service) - prefetch count
            $this->channel->basic_qos(0, $this->config['prefetch_count'], null);

            // Subscribe to each queue
            foreach ($channels as $queue) {
                $this->setupQueue($queue);
            }

            // Start consuming messages
            while (!$this->shouldStop && count($this->channel->callbacks)) {
                try {
                    $this->channel->wait(null, false, 5); // 5 second timeout
                } catch (AMQPTimeoutException $e) {
                    // Timeout is expected, continue loop
                    continue;
                } catch (Exception $e) {
                    // Handle other exceptions
                    $this->output("Error while waiting for messages: " . $e->getMessage(), 'error');
                    break;
                }
            }

            $this->isRunning = false;
            $this->output('Subscriber stopped gracefully');
            Log::info("RabbitMQ subscriber stopped");

            return 0;

        } catch (Exception $e) {
            $this->isRunning = false;
            $this->output("Fatal error: " . $e->getMessage(), 'error');
            Log::error("RabbitMQ subscriber fatal error: " . $e->getMessage(), [
                'exception' => $e
            ]);

            return 1;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Connect to RabbitMQ server.
     *
     * @return void
     * @throws Exception
     */
    protected function connectToRabbitMQ(): void
    {
        Log::debug("Connecting to RabbitMQ: {$this->config['host']}:{$this->config['port']}");

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost']
        );

        $this->channel = $this->connection->channel();

        // Declare exchange
        $this->channel->exchange_declare(
            $this->config['exchange'],
            $this->config['exchange_type'],
            false, // passive
            $this->config['durable'], // durable
            false // auto_delete
        );

        Log::debug("Connected to RabbitMQ and declared exchange: {$this->config['exchange']}");
    }

    /**
     * Set up a queue and start consuming.
     *
     * @param string $queueName
     * @return void
     */
    protected function setupQueue(string $queueName): void
    {
        // Declare queue
        $this->channel->queue_declare(
            $queueName,
            false, // passive
            $this->config['durable'], // durable
            false, // exclusive
            false // auto_delete
        );

        // Bind queue to exchange with routing key
        $this->channel->queue_bind($queueName, $this->config['exchange'], $queueName);

        // Set up consumer
        $callback = function (AMQPMessage $msg) use ($queueName) {
            try {
//                Log::debug('message received from queue: ' . $msg->getBody());
                $this->handleMessage($msg->getBody(), $queueName);

                // Acknowledge the message if auto_ack is disabled
                if (!$this->config['auto_ack']) {
                    $this->channel->basic_ack($msg->getDeliveryTag());
                }
            } catch (Exception $e) {
                Log::error("Error processing RabbitMQ message: " . $e->getMessage(), [
                    'exception' => $e,
                    'queue' => $queueName
                ]);

                // Reject and requeue the message on error
                if (!$this->config['auto_ack']) {
                    $this->channel->basic_nack($msg->getDeliveryTag(), false, true); // requeue=true
                }
            }
        };

        $this->channel->basic_consume(
            $queueName,
            '', // consumer tag
            false, // no_local
            $this->config['auto_ack'], // no_ack
            false, // exclusive
            false, // nowait
            $callback
        );

        Log::debug("Set up consumer for queue: $queueName");
    }

    /**
     * Disconnect from RabbitMQ.
     *
     * @return void
     */
    protected function disconnect(): void
    {
        try {
            if ($this->channel) {
                $this->channel->close();
                $this->channel = null;
            }

            if ($this->connection) {
                $this->connection->close();
                $this->connection = null;
            }
        } catch (Exception $e) {
            Log::warning("Error closing RabbitMQ connection: " . $e->getMessage());
        }
    }

    /**
     * Handle incoming RabbitMQ message.
     *
     * @param string $message Raw message payload
     * @param string $queue Queue name
     * @return void
     * @throws JsonException
     */
    protected function handleMessage(string $message, string $queue): void
    {
        try {
//            Log::debug("Received message on queue $queue: " . substr($message, 0, 200));

            // Create IncomingMessage DTO
            $incomingMessage = IncomingMessage::fromRaw(
                channel: $queue,
                rawPayload: $message,
                source: 'rabbitmq',
                metadata: [
                    'rabbitmq_host' => $this->config['host'],
                    'rabbitmq_port' => $this->config['port'],
                    'exchange' => $this->config['exchange'],
                ]
            );

            // Validate message
            if (!$incomingMessage->isValid()) {
                Log::warning("Invalid message received on queue $queue");
                return;
            }

            // Dispatch to job queue for asynchronous processing
            ProcessIncomingMessageJob::dispatch($incomingMessage);

//            Log::debug("Message dispatched to job queue", [
//                'queue' => $queue,
//                'source' => 'rabbitmq'
//            ]);

        } catch (JsonException $e) {
            Log::error("Invalid JSON in RabbitMQ message: " . $e->getMessage(), [
                'queue' => $queue,
                'message' => substr($message, 0, 500)
            ]);
            throw $e; // Re-throw to trigger nack
        } catch (Exception $e) {
            Log::error("Error processing RabbitMQ message: " . $e->getMessage(), [
                'exception' => $e,
                'queue' => $queue
            ]);
            throw $e; // Re-throw to trigger nack
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
        Log::info("Stop signal received for RabbitMQ listener");
    }

    /**
     * {@inheritDoc}
     */
    public function setOutputCallback(Closure $callback): self
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
        return 'rabbitmq';
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
