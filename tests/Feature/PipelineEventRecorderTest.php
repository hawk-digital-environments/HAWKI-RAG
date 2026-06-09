<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventNormalizer;
use App\Services\Pipeline\Events\PipelineEventRecorder;
use App\Services\Pipeline\Events\PipelineEventTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class PipelineEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_events_with_an_injected_clock_timestamp(): void
    {
        $recorder = new PipelineEventRecorder(
            new PipelineEventNormalizer(app(PipelineEventTypeRegistry::class)),
            new MockClock('2026-06-09T12:00:00+00:00'),
        );

        $record = $recorder->record(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => 'task-recorder-clock',
            'job_id' => 'job-recorder-clock',
            'source_url' => 'https://example.test/clock',
        ]);

        $this->assertNotNull($record);
        $this->assertSame('2026-06-09T12:00:00+00:00', $record->created_at?->format(\DateTimeInterface::ATOM));
    }
}
