<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Exceptions\PipelineWorkerSignatureException;
use App\Services\Pipeline\PipelineWorkerEventSignatureVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PipelineWorkerEventSignatureVerifierTest extends TestCase
{
    private const SECRET = 'unit-test-worker-secret';

    public function test_it_verifies_the_exact_raw_body_and_timestamp(): void
    {
        $clock = new MockClock('2026-08-03T12:00:00+00:00');
        $timestamp = (string) $clock->now()->getTimestamp();
        $body = '{"event_id":"evt_exact","counts":{"processed":1}}';
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
        $verifier = new PipelineWorkerEventSignatureVerifier(self::SECRET, 300, $clock);

        $verifier->verify($body, $timestamp, $signature);

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_signature_for_a_different_body(): void
    {
        $clock = new MockClock('2026-08-03T12:00:00+00:00');
        $timestamp = (string) $clock->now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.{"value":1}', self::SECRET);
        $verifier = new PipelineWorkerEventSignatureVerifier(self::SECRET, 300, $clock);

        try {
            $verifier->verify('{"value":2}', $timestamp, $signature);
            $this->fail('Expected an invalid worker signature exception.');
        } catch (PipelineWorkerSignatureException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_it_rejects_timestamps_outside_the_replay_window(): void
    {
        $clock = new MockClock('2026-08-03T12:00:00+00:00');
        $timestamp = (string) ($clock->now()->getTimestamp() - 301);
        $body = '{}';
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
        $verifier = new PipelineWorkerEventSignatureVerifier(self::SECRET, 300, $clock);

        try {
            $verifier->verify($body, $timestamp, $signature);
            $this->fail('Expected an expired worker signature exception.');
        } catch (PipelineWorkerSignatureException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_it_fails_closed_when_the_secret_is_empty(): void
    {
        $clock = new MockClock('2026-08-03T12:00:00+00:00');
        $verifier = new PipelineWorkerEventSignatureVerifier('', 300, $clock);

        try {
            $verifier->verify('{}', (string) $clock->now()->getTimestamp(), 'v1='.str_repeat('0', 64));
            $this->fail('Expected an unavailable worker signature exception.');
        } catch (PipelineWorkerSignatureException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }
}
