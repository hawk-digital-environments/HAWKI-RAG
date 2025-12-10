# Communication Service

A flexible, extensible service layer for handling message communication from various sources (Redis Pub/Sub, RabbitMQ, etc.).

## Architecture

```
CommunicationService/
├── Contracts/
│   └── MessageListenerInterface.php      # Interface for all listeners
├── Implementations/
│   ├── RedisMessageListener.php          # Redis Pub/Sub implementation
│   └── RabbitMQMessageListener.php       # RabbitMQ implementation
├── Jobs/
│   └── ProcessIncomingMessageJob.php     # Queue job for message processing
├── Data/
│   └── IncomingMessage.php               # DTO for incoming messages
└── CommunicationServiceFactory.php       # Factory for creating listeners
```

## Features

- **Multiple Communication Services**: Support for Redis, RabbitMQ, and easy to extend
- **Factory Pattern**: Simple creation of listener instances
- **Job Queue Integration**: Prevents overloading by queuing incoming messages
- **Automatic Retry**: Failed message processing is automatically retried
- **Graceful Shutdown**: Handles SIGTERM/SIGINT signals properly
- **Comprehensive Logging**: Full visibility into message processing

## Configuration

Add to your `.env`:

```env
# Default communication service (redis or rabbitmq)
COMMUNICATION_SERVICE=redis

# Redis Configuration (for Pub/Sub)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_SCRAPE_CHANNEL=scrape-events

# RabbitMQ Configuration
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_EXCHANGE=scrape_events
RABBITMQ_EXCHANGE_TYPE=topic
RABBITMQ_SCRAPE_QUEUE=scrape-events
```

Configuration is stored in `config/communication.php`.

## Usage

### Command Line

Start a listener using the new command:

```bash
# Use default service from config
php artisan communication:listen

# Specify service type
php artisan communication:listen --service=redis --channels=channel1,channel2
php artisan communication:listen --service=rabbitmq --channels=queue1,queue2

# Legacy command (deprecated)
php artisan redis:subscribe --channel=scrape-events
```

### Programmatic Usage

```php
use App\Services\CommunicationService\CommunicationServiceFactory;

// Create a Redis listener
$listener = CommunicationServiceFactory::create('redis');
$listener->subscribe(['channel-1', 'channel-2']);

// Create a RabbitMQ listener
$listener = CommunicationServiceFactory::create('rabbitmq');
$listener->subscribe(['queue-1', 'queue-2']);

// Use default from config
$listener = CommunicationServiceFactory::create();
$listener->subscribe(['default-channel']);
```

## How It Works

1. **Listener**: Subscribes to channels/queues and receives messages
2. **IncomingMessage DTO**: Standardizes message format from any source
3. **ProcessIncomingMessageJob**: Queued job processes the message asynchronously
4. **ScrapeEventHandler**: Existing business logic handles the scrape events

### Message Flow

```
Communication Service (Redis/RabbitMQ)
    ↓
MessageListener (receives message)
    ↓
IncomingMessage (DTO created)
    ↓
ProcessIncomingMessageJob (dispatched to queue)
    ↓
ScrapeEventPacket (created from message)
    ↓
ScrapeEventHandler (processes event)
```

## Adding New Communication Services

1. Create a new class implementing `MessageListenerInterface`
2. Register it in the factory:

```php
use App\Services\CommunicationService\CommunicationServiceFactory;
use App\Services\CommunicationService\Implementations\MyCustomListener;

// In a service provider
CommunicationServiceFactory::register('custom', MyCustomListener::class);
```

3. Add configuration in `config/communication.php`
4. Use it: `php artisan communication:listen --service=custom`

## Requirements

- **Redis**: PHP Redis extension (`pecl install redis`)
- **RabbitMQ**: php-amqplib package (`composer require php-amqplib/php-amqplib`)

## Job Queue

Incoming messages are processed asynchronously through Laravel's queue system. This provides:

- **Backpressure handling**: Prevents overload by rate-limiting message processing
- **Automatic retries**: Failed messages are retried up to 3 times
- **Error isolation**: Message processing errors don't crash the listener
- **Scalability**: Multiple queue workers can process messages in parallel

Ensure your queue workers are running:

```bash
php artisan queue:work
```

## Comparison with Old System

### Old System
- Redis-specific code in `ScrapeService`
- Direct message processing (no job queue)
- Single channel support
- Tightly coupled to scrape service

### New System
- Separate `CommunicationService` with multiple implementations
- Job queue layer for backpressure
- Multiple channels/queues support
- Factory pattern for extensibility
- Clean separation of concerns

## Migration Guide

The old `ScraperEventSubscriber` is still available in `app/Services/ScrapeService/` but should be considered deprecated. New code should use the `CommunicationService` instead.

To migrate:

**Before:**
```php
$subscriber = new ScraperEventSubscriber($channel);
$subscriber->subscribeWithNativeRedis();
```

**After:**
```php
$listener = CommunicationServiceFactory::create('redis');
$listener->subscribe([$channel]);
```

## Troubleshooting

### Messages not being processed

1. Check queue workers are running: `php artisan queue:work`
2. Check logs: `storage/logs/laravel.log`
3. Verify connection settings in `.env`

### RabbitMQ connection errors

1. Ensure RabbitMQ server is running
2. Verify credentials in `.env`
3. Install php-amqplib: `composer require php-amqplib/php-amqplib`

### Redis connection errors

1. Ensure Redis server is running: `redis-cli ping`
2. Verify Redis extension is installed: `php -m | grep redis`
3. Check connection settings in `.env`
