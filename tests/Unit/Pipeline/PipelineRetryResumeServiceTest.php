<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Exceptions\PipelineRetryException;
use App\Services\Pipeline\Recovery\PipelineRetryResumeService;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class PipelineRetryResumeServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pipeline-resume-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/crawler-output', 0777, true);
        mkdir($this->root.'/markdown', 0777, true);
        file_put_contents($this->root.'/crawler-output/page.html', '<p>Saved crawl</p>');
        file_put_contents($this->root.'/markdown/page.md', '# Saved Markdown');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_scrape_failure_restarts_scraping_without_requiring_artifacts(): void
    {
        $this->assertSame(['stage' => 'scrape'], $this->resume([
            $this->state('scrape', 'failed'),
        ]));
    }

    public function test_conversion_failure_reuses_the_actual_crawler_directory(): void
    {
        $this->assertSame([
            'stage' => 'convert',
            'raw_dir' => $this->root.'/crawler-output',
        ], $this->resume([
            $this->state('scrape', 'completed', $this->root.'/crawler-output'),
            $this->state('convert', 'failed'),
        ]));
    }

    public function test_ingestion_failure_reuses_markdown_even_when_raw_files_are_gone(): void
    {
        $this->assertSame([
            'stage' => 'ingest',
            'markdown_dir' => $this->root.'/markdown',
        ], $this->resume([
            $this->state('scrape', 'completed', $this->root.'/removed-raw'),
            $this->state('convert', 'completed', $this->root.'/markdown'),
            $this->state('ingest', 'failed'),
        ]));
    }

    public function test_timeout_after_scraping_resumes_conversion(): void
    {
        $this->assertSame('convert', $this->resume([
            $this->state('scrape', 'completed', $this->root.'/crawler-output'),
            $this->state('convert', 'running'),
        ])['stage']);
    }

    public function test_failure_before_any_stage_restarts_scraping(): void
    {
        $this->assertSame(['stage' => 'scrape'], $this->resume([]));
    }

    public function test_missing_raw_artifacts_does_not_silently_restart_the_scraper(): void
    {
        $this->expectException(PipelineRetryException::class);
        $this->expectExceptionMessage('output directory');
        $this->resume([
            $this->state('scrape', 'completed', $this->root.'/removed-raw'),
            $this->state('convert', 'failed'),
        ]);
    }

    public function test_conversion_cannot_resume_without_successful_scrape_evidence(): void
    {
        $this->expectException(PipelineRetryException::class);
        $this->expectExceptionMessage('no recorded successful completion');
        $this->resume([$this->state('convert', 'failed')]);
    }

    public function test_empty_markdown_output_blocks_ingestion_retry(): void
    {
        unlink($this->root.'/markdown/page.md');
        file_put_contents($this->root.'/markdown/converter.log', 'Only a log remains');
        $this->expectException(PipelineRetryException::class);
        $this->resume([
            $this->state('convert', 'completed', $this->root.'/markdown'),
            $this->state('ingest', 'failed'),
        ]);
    }

    public function test_converter_file_references_locate_the_actual_markdown_directory(): void
    {
        $completed = new PipelineStageState([
            'stage' => 'convert', 'status' => 'completed',
            'metadata' => ['artifacts' => [[
                'uri' => $this->root.'/markdown/page.md',
                'relative_path' => 'page.md', 'media_type' => 'text/markdown',
            ]]],
        ]);
        $this->assertSame($this->root.'/markdown', $this->resume([
            $completed, $this->state('ingest', 'failed'),
        ])['markdown_dir']);
    }

    public function test_ingestion_retry_rejects_a_missing_file_from_a_completed_conversion(): void
    {
        $completed = new PipelineStageState([
            'stage' => 'convert', 'status' => 'completed',
            'metadata' => ['artifacts' => [[
                'uri' => $this->root.'/markdown/removed.md',
                'relative_path' => 'removed.md', 'media_type' => 'text/markdown',
            ]]],
        ]);
        $this->expectException(PipelineRetryException::class);
        $this->resume([$completed, $this->state('ingest', 'failed')]);
    }

    public function test_directory_outside_shared_storage_is_rejected(): void
    {
        $this->expectException(PipelineRetryException::class);
        $this->resume([
            $this->state('scrape', 'completed', dirname($this->root)),
            $this->state('convert', 'failed'),
        ]);
    }

    /** @param list<PipelineStageState> $states */
    private function resume(array $states): array
    {
        $repository = $this->createMock(PipelineStageStateRepository::class);
        $repository->method('forPipelineJob')->willReturn(collect($states));
        $service = new PipelineRetryResumeService(
            $repository,
            new Filesystem,
            new Repository(['temporal' => ['storage' => ['shared_root' => $this->root]]]),
        );

        return $service->forJob(
            new PipelineJob(['current_stage' => 'temporal.workflow_failed']),
            new IngestionSource([
                'raw_storage_path' => $this->root.'/source/raw',
                'markdown_storage_path' => $this->root.'/markdown',
            ]),
        )->toArray();
    }

    private function state(string $stage, string $status, ?string $directory = null): PipelineStageState
    {
        return new PipelineStageState([
            'stage' => $stage,
            'status' => $status,
            'metadata' => ['artifacts' => $directory === null ? [] : [
                ['uri' => $directory, 'media_type' => 'inode/directory'],
            ]],
        ]);
    }
}
