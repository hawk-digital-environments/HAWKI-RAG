<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\Document;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\Rag\RagLatestDocumentGraphReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RagLatestDocumentGraphReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_managed_document_reporting_when_available(): void
    {
        ManagedDocument::query()->create([
            'document_id' => 'adoc_report_1',
            'dataset_id' => 'report-dataset',
            'display_name' => 'report.pdf',
            'source_type' => 'upload',
            'source_url' => 'upload://report.pdf',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
            'latest_source_id' => 'source-report-1',
            'indexed_at' => Carbon::parse('2026-07-13T10:00:00Z'),
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_report_1',
            'bridge_document_id' => 'doc-report-1',
            'qdrant_collection' => 'hawki_report_dataset',
            'neo4j_namespace' => 'hawki_report_dataset',
            'chunk_count' => 12,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => Carbon::parse('2026-07-13T10:00:00Z'),
        ]);

        $managedBackingDocument = Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-report-1',
            'dataset_id' => 'report-dataset',
            'collection' => 'hawki_report_dataset',
            'source_type' => 'upload',
            'source_url' => 'upload://report.pdf',
            'original_filename' => 'report.pdf',
            'storage_path' => '/tmp/report-dataset/report.md',
            'mime_type' => 'text/markdown',
            'file_size' => 123,
            'checksum_sha256' => hash('sha256', 'managed-report'),
            'title' => 'Managed Report',
            'metadata_json' => [
                'source_id' => 'source-report-1',
                'qdrant_collection' => 'hawki_report_dataset',
                'neo4j_namespace' => 'hawki_report_dataset',
                'bridge_response' => [
                    'points' => 144,
                    'summary' => [
                        'graph' => ['enabled' => true],
                        'graph_preview' => [
                            'total_triplets' => 9,
                            'docs_with_triplets' => 1,
                        ],
                    ],
                ],
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);
        $managedBackingDocument->forceFill([
            'created_at' => Carbon::parse('2026-07-13T10:00:00Z'),
            'updated_at' => Carbon::parse('2026-07-13T10:01:00Z'),
        ])->save();

        $report = app(RagLatestDocumentGraphReporter::class)->report();

        $this->assertSame('adoc_report_1', $report['document_id']);
        $this->assertSame('doc-report-1', $report['external_id']);
        $this->assertSame('report-dataset', $report['dataset_id']);
        $this->assertSame('hawki_report_dataset', $report['collection']);
        $this->assertSame('Managed Report', $report['title']);
        $this->assertSame('upload://report.pdf', $report['source_url']);
        $this->assertSame('2026-07-13T10:01:00+00:00', $report['updated_at']);
        $this->assertSame(144, $report['qdrant_points']);
        $this->assertTrue($report['graph_enabled']);
        $this->assertSame(9, $report['graph_triplets']);
        $this->assertSame(1, $report['docs_with_triplets']);
    }

    public function test_it_falls_back_to_legacy_indexed_documents_when_no_managed_document_exists(): void
    {
        $document = Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-legacy-1',
            'dataset_id' => 'legacy-dataset',
            'collection' => 'hawki_legacy_dataset',
            'source_type' => 'upload',
            'source_url' => 'upload://legacy.pdf',
            'original_filename' => 'legacy.pdf',
            'storage_path' => '/tmp/legacy-dataset/legacy.md',
            'mime_type' => 'text/markdown',
            'file_size' => 55,
            'checksum_sha256' => hash('sha256', 'legacy-report'),
            'title' => 'Legacy Report',
            'metadata_json' => [
                'bridge_response' => [
                    'points' => 55,
                    'summary' => [
                        'graph' => ['enabled' => true],
                        'graph_preview' => [
                            'total_triplets' => 3,
                            'docs_with_triplets' => 1,
                        ],
                    ],
                ],
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);
        $document->forceFill([
            'created_at' => Carbon::parse('2026-07-13T10:59:00Z'),
            'updated_at' => Carbon::parse('2026-07-13T11:00:00Z'),
        ])->save();

        $report = app(RagLatestDocumentGraphReporter::class)->report();

        $this->assertSame($document->id, $report['document_id']);
        $this->assertSame('doc-legacy-1', $report['external_id']);
        $this->assertSame('legacy-dataset', $report['dataset_id']);
        $this->assertSame('hawki_legacy_dataset', $report['collection']);
        $this->assertSame('Legacy Report', $report['title']);
        $this->assertSame('upload://legacy.pdf', $report['source_url']);
        $this->assertSame('2026-07-13T11:00:00+00:00', $report['updated_at']);
        $this->assertSame(55, $report['qdrant_points']);
        $this->assertTrue($report['graph_enabled']);
        $this->assertSame(3, $report['graph_triplets']);
        $this->assertSame(1, $report['docs_with_triplets']);
    }
}
