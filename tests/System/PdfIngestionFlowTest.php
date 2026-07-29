<?php

declare(strict_types=1);

namespace Tests\System;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Vertical upload-to-orchestration scenario using a valid ten-page PDF.
 *
 * The test exercises the unlocked operator upload API, multipart validation,
 * physical shared-storage persistence, hashing, dataset, task/job/source
 * creation, and Temporal workflow-payload construction. Only
 * the Python Temporal endpoint is faked: workers, conversion, model inference,
 * Qdrant writes, Neo4j writes, and workflow completion are intentionally not
 * claimed by this test. Relational persistence uses the isolated SQLite test
 * connection rather than live PostgreSQL.
 */
class PdfIngestionFlowTest extends SystemTestCase
{
    use RefreshDatabase;

    private string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sharedRoot = storage_path('framework/testing/system-pdf-ingestion');
        File::deleteDirectory($this->sharedRoot);
        config()->set('temporal.storage.shared_root', $this->sharedRoot);
        config()->set('temporal.storage.mode', 'shared');
        config()->set('temporal.enabled', true);
        config()->set('temporal.ingestion.provider', 'ollama');
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);

        $settingsPath = storage_path('framework/testing/system-pdf-ingestion-settings.json');
        File::delete($settingsPath);
        config()->set('config.admin_settings_path', $settingsPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_real_ten_page_pdf_is_persisted_and_handed_to_temporal(): void
    {
        $bridgeEndpoint = rtrim((string) config('config.hawki_rag_bridge_url'), '/')
            .'/temporal/workflows/ingest';
        Http::fake([
            $bridgeEndpoint => Http::response([
                'workflow_id' => 'ingest-source-system-ten-page-pdf',
                'run_id' => 'system-ten-page-run-1',
            ]),
        ]);

        $pdf = $this->tenPagePdf();
        $this->assertSame(10, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);

        $response = $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'system-pdf-ten-pages',
            'graph' => 'true',
            'file' => UploadedFile::fake()->createWithContent('ten-page-handbook.pdf', $pdf),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', 'system-pdf-ten-pages')
            ->assertJsonPath('task.stages.scrape.status', 'n/a')
            ->assertJsonPath('task.stages.convert.status', 'processing')
            ->assertJsonPath('task.stages.ingest.status', PipelineJob::STATUS_QUEUED);

        $taskId = $response->json('task_id');
        $jobId = $response->json('job_id');
        $sourceId = $response->json('source_id');

        $this->assertIsString($taskId);
        $this->assertIsString($jobId);
        $this->assertIsString($sourceId);
        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'system-pdf-ten-pages',
            'status' => 'active',
            'qdrant_collection' => 'hawki_system_pdf_ten_pages',
            'neo4j_namespace' => 'hawki_system_pdf_ten_pages',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
        ]);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => $taskId,
            'dataset_id' => 'system-pdf-ten-pages',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $jobId,
            'task_id' => $taskId,
            'source_id' => $sourceId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'upload://ten-page-handbook.pdf',
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'temporal_workflow_id' => 'ingest-source-system-ten-page-pdf',
            'temporal_run_id' => 'system-ten-page-run-1',
        ]);
        $this->assertDatabaseHas('ingestion_sources', [
            'source_id' => $sourceId,
            'task_id' => $taskId,
            'dataset_id' => 'system-pdf-ten-pages',
            'source_url' => 'upload://ten-page-handbook.pdf',
            'index_status' => IngestionSource::STATUS_RUNNING,
            'temporal_workflow_id' => 'ingest-source-system-ten-page-pdf',
        ]);

        $storedPath = null;
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($bridgeEndpoint, $sourceId, &$storedPath): bool {
            $payload = $request->data();
            $storedPath = data_get($payload, 'workflow_input.upload.local_path');

            return $request->method() === 'POST'
                && $request->url() === $bridgeEndpoint
                && data_get($payload, 'workflow_id') === 'ingest-source-'.$sourceId
                && data_get($payload, 'workflow_input.source_id') === $sourceId
                && data_get($payload, 'workflow_input.dataset_id') === 'system-pdf-ten-pages'
                && data_get($payload, 'workflow_input.upload.original_filename') === 'ten-page-handbook.pdf'
                && data_get($payload, 'workflow_input.converter_mode') === 'native'
                && data_get($payload, 'workflow_input.ingestion.provider') === 'ollama'
                && data_get($payload, 'workflow_input.ingestion.embedding_model') === 'bge-m3'
                && data_get($payload, 'workflow_input.ingestion.collection') === 'hawki_system_pdf_ten_pages'
                && data_get($payload, 'workflow_input.ingestion.neo4j_namespace') === 'hawki_system_pdf_ten_pages'
                && data_get($payload, 'workflow_input.ingestion.graph') === true
                && is_string($storedPath);
        });

        $this->assertIsString($storedPath);
        $this->assertFileExists($storedPath);
        $storedPdf = File::get($storedPath);
        $this->assertSame($pdf, $storedPdf);
        $this->assertSame(10, preg_match_all('/\/Type\s*\/Page\b/', $storedPdf));
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $jobId,
            'local_path' => $storedPath,
            'content_hash' => hash('sha256', $pdf),
        ]);
    }

    /**
     * Build a deterministic PDF 1.4 document with ten independently rendered
     * pages, a shared Helvetica font, a valid xref table, and trailer.
     */
    private function tenPagePdf(): string
    {
        $pageObjectIds = [];
        for ($page = 1; $page <= 10; $page++) {
            $pageObjectIds[] = 3 + (($page - 1) * 2);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => sprintf(
                '<< /Type /Pages /Kids [%s] /Count 10 >>',
                implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $pageObjectIds)),
            ),
        ];

        for ($page = 1; $page <= 10; $page++) {
            $pageObjectId = 3 + (($page - 1) * 2);
            $contentObjectId = $pageObjectId + 1;
            $stream = "BT /F1 18 Tf 72 720 Td (HAWKI RAG system test page {$page}) Tj ET\n";
            $objects[$pageObjectId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 23 0 R >> >> /Contents %d 0 R >>',
                $contentObjectId,
            );
            $objects[$contentObjectId] = sprintf(
                "<< /Length %d >>\nstream\n%sendstream",
                strlen($stream),
                $stream,
            );
        }

        $objects[23] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $objectId => $object) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= "{$objectId} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 24\n";
        $pdf .= "0000000000 65535 f \n";
        for ($objectId = 1; $objectId <= 23; $objectId++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId]);
        }
        $pdf .= "trailer\n<< /Size 24 /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
