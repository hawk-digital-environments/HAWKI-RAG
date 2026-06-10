<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventDecoder;
use App\Services\Pipeline\Exceptions\PipelineEventException;
use JsonException;
use Tests\TestCase;

class PipelineEventDecoderTest extends TestCase
{
    public function test_it_decodes_and_normalizes_pipeline_events(): void
    {
        $event = app(PipelineEventDecoder::class)->decode(json_encode([
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'task_id' => 'task-decoder',
            'job_id' => 'job-decoder',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $event['event_type']);
        $this->assertSame('task-decoder', $event['task_id']);
        $this->assertSame('job-decoder', $event['job_id']);
        $this->assertIsArray($event['metadata']);
        $this->assertArrayHasKey('schema_version', $event);
    }

    public function test_it_rejects_non_object_payloads(): void
    {
        $this->expectException(PipelineEventException::class);
        $this->expectExceptionMessage('Pipeline event payload must be a JSON object.');

        app(PipelineEventDecoder::class)->decode('"not an object"');
    }

    public function test_it_rejects_invalid_json_payloads(): void
    {
        $this->expectException(JsonException::class);

        app(PipelineEventDecoder::class)->decode('{not-json');
    }
}
