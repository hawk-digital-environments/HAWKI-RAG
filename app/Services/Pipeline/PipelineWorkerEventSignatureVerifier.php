<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Exceptions\PipelineWorkerSignatureException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineWorkerEventSignatureVerifier
{
    public function __construct(
        #[Config('temporal.callbacks.secret')]
        private string $secret,
        #[Config('temporal.callbacks.max_age_seconds')]
        private int $maxAgeSeconds,
        private ClockInterface $clock = new Clock,
    ) {}

    public function verify(string $rawBody, ?string $timestamp, ?string $signature): void
    {
        if (trim($this->secret) === '' || $this->maxAgeSeconds < 1) {
            throw PipelineWorkerSignatureException::configurationMissing();
        }

        if ($timestamp === null || ! preg_match('/\A[0-9]{10,12}\z/', $timestamp)) {
            throw PipelineWorkerSignatureException::unauthorized();
        }

        $requestTimestamp = (int) $timestamp;
        if (abs($this->clock->now()->getTimestamp() - $requestTimestamp) > $this->maxAgeSeconds) {
            throw PipelineWorkerSignatureException::unauthorized();
        }

        if ($signature === null || ! preg_match('/\Av1=([a-fA-F0-9]{64})\z/', $signature, $matches)) {
            throw PipelineWorkerSignatureException::unauthorized();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
        if (! hash_equals($expected, strtolower($matches[1]))) {
            throw PipelineWorkerSignatureException::unauthorized();
        }
    }
}
