# RabbitMQ Docker Setup Guide

This guide explains how to use RabbitMQ in the Docker Compose environment.

## Overview

RabbitMQ has been added to the Docker Compose stack as a central message broker. Both your Laravel application and Python microservices can connect to RabbitMQ to publish and consume messages.

## Architecture

```
┌─────────────────┐         ┌─────────────────┐
│  Laravel App    │────────▶│   RabbitMQ      │
│  (Consumer)     │         │  (Message Broker)│
└─────────────────┘         └─────────────────┘
                                     ▲
                                     │
                            ┌────────┴────────┐
                            │                 │
                     ┌──────┴──────┐   ┌─────┴──────┐
                     │  Python     │   │  Other     │
                     │  Microservice│   │  Services  │
                     │  (Publisher)│   │            │
                     └─────────────┘   └────────────┘
```

## Docker Compose Configuration

RabbitMQ is configured in `docker-compose.yml` with:

- **Container Name**: `rawki_rabbitmq`
- **Image**: `rabbitmq:3.13-management-alpine`
- **Ports**:
  - `5672`: AMQP protocol port (for message publishing/consumption)
  - `15672`: Management UI port (for monitoring and administration)
- **Volumes**:
  - `rabbitmq_data`: Persistent message storage
  - `rabbitmq_logs`: RabbitMQ logs
- **Default Credentials**:
  - Username: `admin` (configurable via `RABBITMQ_USER`)
  - Password: `admin123` (configurable via `RABBITMQ_PASSWORD`)

## Starting RabbitMQ

### First Time Setup

1. **Start the Docker network** (if not already created):
   ```bash
   docker network create rawki-network
   ```

2. **Start RabbitMQ**:
   ```bash
   docker-compose up -d rabbitmq
   ```

3. **Verify RabbitMQ is running**:
   ```bash
   docker-compose ps rabbitmq
   docker-compose logs rabbitmq
   ```

4. **Access RabbitMQ Management UI**:
   - URL: http://localhost:15672
   - Username: `admin`
   - Password: `admin123`

### Starting All Services

To start all services including RabbitMQ:

```bash
docker-compose up -d
```

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# RabbitMQ Connection
RABBITMQ_HOST=rabbitmq          # Use 'rabbitmq' for Docker, '127.0.0.1' for local
RABBITMQ_PORT=5672
RABBITMQ_USER=admin
RABBITMQ_PASSWORD=admin123
RABBITMQ_VHOST=/
RABBITMQ_EXCHANGE=scrape_events
RABBITMQ_EXCHANGE_TYPE=topic
RABBITMQ_SCRAPE_QUEUE=scrape-events

# Communication Service Selection
COMMUNICATION_SERVICE=rabbitmq   # Use 'rabbitmq' or 'redis'

# Enable RabbitMQ in crawler (optional - for dual publishing)
RABBITMQ_ENABLED=false
```

### Docker Compose Override (Optional)

To customize RabbitMQ settings, create a `docker-compose.override.yml`:

```yaml
services:
  rabbitmq:
    environment:
      RABBITMQ_DEFAULT_USER: custom_user
      RABBITMQ_DEFAULT_PASS: custom_password
    ports:
      - "5673:5672"  # Use different external port
```

## Using RabbitMQ

### 1. Starting the Message Listener

**Using the new communication command** (recommended):

```bash
# Inside Laravel app container
docker-compose exec app php artisan communication:listen --service=rabbitmq

# Or specify custom queues
docker-compose exec app php artisan communication:listen --service=rabbitmq --channels=queue1,queue2
```

**Run in background**:

```bash
docker-compose exec -d app php artisan communication:listen --service=rabbitmq
```

### 2. Publishing Messages from Microservices

Your Python microservice should publish messages to RabbitMQ. Example configuration:

```python
import pika
import json

# Connect to RabbitMQ
connection = pika.BlockingConnection(
    pika.ConnectionParameters(
        host='rabbitmq',  # Docker service name
        port=5672,
        credentials=pika.PlainCredentials('admin', 'admin123')
    )
)
channel = connection.channel()

# Declare exchange
channel.exchange_declare(
    exchange='scrape_events',
    exchange_type='topic',
    durable=True
)

# Publish message
message = {
    'job_id': 'job-123',
    'event': 'job_completed',
    'data': {'status': 'success'},
    'timestamp': '2024-12-03T10:00:00Z'
}

channel.basic_publish(
    exchange='scrape_events',
    routing_key='scrape-events',  # Queue name
    body=json.dumps(message),
    properties=pika.BasicProperties(
        delivery_mode=2,  # Make message persistent
    )
)

connection.close()
```

### 3. Switching Between Redis and RabbitMQ

You can easily switch between communication services:

```bash
# Use Redis
docker-compose exec app bash -c "export COMMUNICATION_SERVICE=redis && php artisan communication:listen"

# Use RabbitMQ
docker-compose exec app bash -c "export COMMUNICATION_SERVICE=rabbitmq && php artisan communication:listen"
```

## Management UI

RabbitMQ includes a powerful management interface at http://localhost:15672

### Features:
- **Overview**: Queue statistics, message rates, resource usage
- **Connections**: View active connections from services
- **Channels**: Monitor AMQP channels
- **Exchanges**: Manage message routing exchanges
- **Queues**: View queue depth, consumer count, message rates
- **Admin**: User management, virtual hosts, policies

### Common Tasks:

1. **View Queue Messages**:
   - Go to "Queues" tab
   - Click on queue name (e.g., `scrape-events`)
   - Click "Get messages" to preview messages without consuming

2. **Publish Test Message**:
   - Go to "Exchanges" tab
   - Click exchange name (e.g., `scrape_events`)
   - Expand "Publish message" section
   - Set routing key and payload
   - Click "Publish message"

3. **Monitor Message Flow**:
   - Go to "Overview" tab
   - View message rates chart
   - Check "Queued messages" graph

## Troubleshooting

### RabbitMQ Container Won't Start

```bash
# Check logs
docker-compose logs rabbitmq

# Common issues:
# 1. Port already in use
docker ps | grep 5672

# 2. Volume permission issues
docker-compose down -v
docker-compose up -d rabbitmq
```

### Connection Refused from Laravel

```bash
# Verify RabbitMQ is accessible
docker-compose exec app nc -zv rabbitmq 5672

# Check if php-amqplib is installed
docker-compose exec app composer show php-amqplib/php-amqplib

# Install if missing
docker-compose exec app composer require php-amqplib/php-amqplib
```

### Messages Not Being Consumed

1. **Check listener is running**:
   ```bash
   docker-compose exec app ps aux | grep communication:listen
   ```

2. **Check queue has messages**:
   - Open Management UI
   - Go to Queues
   - Check "Ready" message count

3. **Check queue workers**:
   ```bash
   docker-compose exec app php artisan queue:work
   ```

### Connection Credentials Wrong

```bash
# Reset credentials by recreating container
docker-compose stop rabbitmq
docker-compose rm -f rabbitmq
docker volume rm rawki_rabbitmq_data
docker-compose up -d rabbitmq
```

## Performance Tuning

### Prefetch Count

Adjust how many messages a consumer fetches at once:

In `config/communication.php`:

```php
'rabbitmq' => [
    'prefetch_count' => 10,  // Default: 1
],
```

Higher values = better throughput but more memory usage.

### Queue Workers

Run multiple queue workers for parallel processing:

```bash
# Start 5 queue workers
for i in {1..5}; do
  docker-compose exec -d app php artisan queue:work
done
```

### Resource Limits

Adjust in `docker-compose.yml`:

```yaml
rabbitmq:
  deploy:
    resources:
      limits:
        cpus: '2'
        memory: 1G
```

## Monitoring

### Prometheus Metrics

RabbitMQ exposes Prometheus metrics at: http://localhost:15692/metrics

### Health Check

```bash
# Via Docker
docker-compose exec rabbitmq rabbitmq-diagnostics ping

# Via HTTP
curl http://localhost:15672/api/healthchecks/node
```

### Log Monitoring

```bash
# Follow RabbitMQ logs
docker-compose logs -f rabbitmq

# View specific errors
docker-compose logs rabbitmq | grep ERROR
```

## Data Persistence

RabbitMQ data is persisted in Docker volumes:

```bash
# List volumes
docker volume ls | grep rabbitmq

# Backup volume
docker run --rm -v rawki_rabbitmq_data:/data -v $(pwd):/backup alpine tar czf /backup/rabbitmq-backup.tar.gz /data

# Restore volume
docker run --rm -v rawki_rabbitmq_data:/data -v $(pwd):/backup alpine tar xzf /backup/rabbitmq-backup.tar.gz -C /
```

## Comparison: Redis vs RabbitMQ

| Feature | Redis Pub/Sub | RabbitMQ |
|---------|---------------|----------|
| **Message Persistence** | No (in-memory only) | Yes (durable queues) |
| **Message Acknowledgment** | No | Yes |
| **Dead Letter Queues** | No | Yes |
| **Routing Flexibility** | Basic (channels) | Advanced (exchanges, routing keys) |
| **Performance** | Very fast (in-memory) | Fast (disk-backed) |
| **Message Guarantees** | Fire-and-forget | At-least-once delivery |
| **Consumer Groups** | No | Yes |
| **Backpressure Handling** | Limited | Excellent (prefetch, QoS) |
| **Management UI** | No | Yes |
| **Best For** | Real-time, high-speed | Reliable, guaranteed delivery |

## Next Steps

1. **Install php-amqplib**:
   ```bash
   docker-compose exec app composer require php-amqplib/php-amqplib
   ```

2. **Update your Python microservice** to publish to RabbitMQ

3. **Test the setup**:
   ```bash
   # Start listener
   docker-compose exec app php artisan communication:listen --service=rabbitmq

   # Publish test message via Management UI
   ```

4. **Run queue workers**:
   ```bash
   docker-compose exec app php artisan queue:work
   ```

## References

- [RabbitMQ Documentation](https://www.rabbitmq.com/documentation.html)
- [RabbitMQ Docker Hub](https://hub.docker.com/_/rabbitmq)
- [php-amqplib GitHub](https://github.com/php-amqplib/php-amqplib)
- [Laravel Queues Documentation](https://laravel.com/docs/queues)
