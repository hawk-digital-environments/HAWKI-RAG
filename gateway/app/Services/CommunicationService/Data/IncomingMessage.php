<?php

namespace App\Services\CommunicationService\Data;

/**
 * Incoming Message DTO
 *
 * Represents a message received from any communication service
 * (Redis Pub/Sub, RabbitMQ, Kafka, etc.).
 *
 * This standardized format allows the application to handle messages
 * from different sources uniformly.
 */
class IncomingMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $rawPayload,
        public readonly array $decodedPayload,
        public readonly string $source,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly ?array $metadata = null
    ) {}

    /**
     * Create an IncomingMessage from a raw message string.
     *
     * @param string $channel
     * @param string $rawPayload
     * @param string $source
     * @param array|null $metadata
     * @return self
     * @throws \JsonException
     */
    public static function fromRaw(
        string $channel,
        string $rawPayload,
        string $source,
        ?array $metadata = null
    ): self {
        $decodedPayload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            channel: $channel,
            rawPayload: $rawPayload,
            decodedPayload: $decodedPayload,
            source: $source,
            receivedAt: new \DateTimeImmutable(),
            metadata: $metadata
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'raw_payload' => $this->rawPayload,
            'decoded_payload' => $this->decodedPayload,
            'source' => $this->source,
            'received_at' => $this->receivedAt->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if the message has valid JSON structure.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return !empty($this->decodedPayload);
    }
}
