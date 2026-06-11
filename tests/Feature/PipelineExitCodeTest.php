<?php

namespace Tests\Feature;

use App\Support\PipelineExitCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PipelineExitCodeTest extends TestCase
{
    public function test_scrape_command_returns_validation_failure_without_url_in_non_interactive_mode(): void
    {
        $exitCode = Artisan::call('scraper:scrape', [
            '--no-interaction' => true,
        ]);

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
    }

    public function test_convert_command_returns_validation_failure_for_missing_directory(): void
    {
        $exitCode = Artisan::call('convert:crawled-pdfs', [
            'outputDir' => '/tmp/hawki-rag-test-missing-convert-dir',
            '--no-interaction' => true,
        ]);

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
    }

    public function test_convert_command_uses_env_existing_mode_in_automation_mode(): void
    {
        $root = sys_get_temp_dir() . '/hawki-rag-test-convert-env-mode-' . uniqid();
        $filesDir = $root . '/page/files';
        File::ensureDirectoryExists($filesDir . '/converted_ok');
        File::put($filesDir . '/ok.pdf', 'ok document');
        File::put($filesDir . '/converted_ok/conversion_meta.json', json_encode([
            'converted_id' => 'cached',
        ]));

        config([
            'config.pipeline_automation' => true,
            'config.convert_existing_mode' => 'cancel',
        ]);

        try {
            $exitCode = Artisan::call('convert:crawled-pdfs', [
                'outputDir' => $root,
                '--extensions' => 'pdf',
                '--existing' => 'ask',
            ]);

            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $exitCode);
            $this->assertStringContainsString(
                "using existing output mode 'cancel'",
                Artisan::output()
            );
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_convert_command_requires_output_dir_in_automation_mode(): void
    {
        config([
            'config.pipeline_automation' => true,
        ]);

        $exitCode = Artisan::call('convert:crawled-pdfs');

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $exitCode);
        $this->assertStringContainsString('Output dir is required', Artisan::output());
    }

    public function test_convert_command_returns_partial_success_with_real_file_converter_when_one_document_fails(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required to build the DOCX fixture.');
        }

        $converterUrl = env(
            'FILE_CONVERTER_INTEGRATION_URL',
            env('FILE_CONVERTER_URL', 'http://127.0.0.1:8001/extract')
        );
        if (!$this->canReachHttpEndpoint((string) $converterUrl)) {
            $this->markTestSkipped("Real file converter is not reachable at {$converterUrl}.");
        }

        $root = sys_get_temp_dir() . '/hawki-rag-test-convert-partial-' . uniqid();
        $filesDir = $root . '/page/files';
        $failedJson = storage_path('logs/failed_conversion.json');
        File::ensureDirectoryExists($filesDir);
        $this->writeMinimalDocx($filesDir . '/ok.docx', 'This document should convert successfully.');
        File::put($filesDir . '/bad.docx', 'this is not a valid docx archive');
        @unlink($failedJson);

        config([
            'file_converter.url' => $converterUrl,
            'file_converter.timeout' => (int) env('FILE_CONVERTER_INTEGRATION_TIMEOUT', 120),
            'file_converter.connect_timeout' => 10,
            'file_converter.retries' => 0,
            'file_converter.retry_delay_ms' => 0,
        ]);

        try {
            $exitCode = Artisan::call('convert:crawled-pdfs', [
                'outputDir' => $root,
                '--extensions' => 'docx',
                '--existing' => 'continue',
                '--no-interaction' => true,
            ]);

            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $exitCode);
            $this->assertFileExists($filesDir . '/converted_ok/conversion_meta.json');
            $this->assertFileExists($filesDir . '/ok_converted.md');
            $this->assertFileDoesNotExist($filesDir . '/converted_bad/conversion_meta.json');

            $report = json_decode((string) file_get_contents($failedJson), true);
            $this->assertSame(2, $report['total'] ?? null);
            $this->assertSame(1, $report['processed'] ?? null);
            $this->assertSame(1, $report['failed'] ?? null);
            $this->assertStringContainsString('bad.docx', $report['failures'][0]['file_local_path'] ?? '');
        } finally {
            File::deleteDirectory($root);
            @unlink($failedJson);
        }
    }

    public function test_python_ingest_script_returns_validation_failure_for_missing_root(): void
    {
        $process = new Process([
            'python3',
            base_path('python_rag/application/cli/ingest/ingest_crawled.py'),
            '--root',
            '/tmp/hawki-rag-test-missing-ingest-root',
        ], base_path());

        $process->run();

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_python_ingest_script_validates_env_resume_mode(): void
    {
        $process = new Process([
            'python3',
            base_path('python_rag/application/cli/ingest/ingest_crawled.py'),
            '--root',
            '/tmp/hawki-rag-test-missing-ingest-root',
        ], base_path(), [
            'HAWKI_RAG_INGEST_RESUME_MODE' => 'invalid',
        ]);

        $process->run();

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Invalid HAWKI_RAG_INGEST_RESUME_MODE', $process->getErrorOutput());
    }

    public function test_python_ingest_script_returns_partial_success_when_no_pages_are_found(): void
    {
        $root = sys_get_temp_dir() . '/hawki-rag-test-empty-ingest-root-' . uniqid();
        $summaryPath = sys_get_temp_dir() . '/hawki-rag-test-empty-ingest-summary-' . uniqid() . '.json';
        mkdir($root, 0777, true);

        try {
            $process = new Process([
                'python3',
                base_path('python_rag/application/cli/ingest/ingest_crawled.py'),
                '--root',
                $root,
                '--summary-file',
                $summaryPath,
            ], base_path());

            $process->run();

            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('No pages found under root.', $process->getOutput());

            $summary = json_decode((string) file_get_contents($summaryPath), true);
            $this->assertSame('partial', $summary['status'] ?? null);
            $this->assertSame('no_pages_found', $summary['reason'] ?? null);
        } finally {
            @unlink($summaryPath);
            @rmdir($root);
        }
    }

    public function test_python_ingest_script_counts_empty_page_folders_as_partial_success(): void
    {
        $root = sys_get_temp_dir() . '/hawki-rag-test-empty-page-root-' . uniqid();
        $pageDir = $root . '/page-one';
        $summaryPath = sys_get_temp_dir() . '/hawki-rag-test-empty-page-summary-' . uniqid() . '.json';
        mkdir($pageDir, 0777, true);
        file_put_contents($pageDir . '/content.md', "   \n");

        try {
            $process = new Process([
                'python3',
                base_path('python_rag/application/cli/ingest/ingest_crawled.py'),
                '--root',
                $root,
                '--summary-file',
                $summaryPath,
                '--start',
            ], base_path());

            $process->run();

            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('Skipped empty page folder: page-one', $process->getErrorOutput());

            $summary = json_decode((string) file_get_contents($summaryPath), true);
            $this->assertSame('partial', $summary['status'] ?? null);
            $this->assertSame('no_ingestable_documents', $summary['reason'] ?? null);
            $this->assertSame(1, $summary['documents']['skipped_docs'] ?? null);
            $this->assertSame(1, $summary['documents']['empty_docs'] ?? null);
            $this->assertSame(['page-one'], $summary['documents']['empty_paths'] ?? null);
        } finally {
            @unlink($summaryPath);
            @unlink($pageDir . '/content.md');
            @rmdir($pageDir);
            @rmdir($root);
        }
    }

    public function test_prune_missing_docs_returns_validation_failure_for_missing_root(): void
    {
        $process = new Process([
            'python3',
            base_path('python_rag/application/cli/ingest/prune_missing_docs.py'),
            '--root',
            '/tmp/hawki-rag-test-missing-prune-root',
            '--collection',
            'test_collection',
        ], base_path());

        $process->run();

        $this->assertSame(PipelineExitCode::VALIDATION_FAILURE, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_prune_missing_docs_counts_delete_failures_as_runtime_failure(): void
    {
        $python = <<<'PY'
import sys
import types

import prune_missing_docs

class Response:
    status_code = 500
    text = "forced failure"

class Session:
    def delete(self, url, timeout=30):
        return Response()

prune_missing_docs.requests = types.SimpleNamespace(Session=lambda: Session())
failures = prune_missing_docs.delete_missing("http://example.invalid", {"doc-1", "doc-2"}, False)
assert failures == 2
raise SystemExit(prune_missing_docs.EXIT_RUNTIME_FAILURE if failures else prune_missing_docs.EXIT_SUCCESS)
PY;

        $process = new Process([
            'python3',
            '-c',
            $python,
        ], base_path(), [
            'PYTHONPATH' => base_path('python_rag/application/cli/ingest'),
        ]);

        $process->run();

        $this->assertSame(PipelineExitCode::RUNTIME_FAILURE, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_retry_ingest_docs_returns_partial_success_for_unmatched_doc_ids(): void
    {
        $root = sys_get_temp_dir() . '/hawki-rag-test-retry-unmatched-root-' . uniqid();
        $pageDir = $root . '/page-one';
        mkdir($pageDir, 0777, true);
        file_put_contents($pageDir . '/content.md', "# Page One\n\nRetry fixture content.");

        try {
            $process = new Process([
                'python3',
                base_path('python_rag/application/cli/ingest/retry_ingest_docs.py'),
                '--root',
                $root,
                '--doc-id',
                'definitely-not-present',
            ], base_path());

            $process->run();

            $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('were not found', $process->getErrorOutput());
        } finally {
            @unlink($pageDir . '/content.md');
            @rmdir($pageDir);
            @rmdir($root);
        }
    }

    public function test_python_api_ingest_isolates_embedding_failure_to_one_document(): void
    {
        $publicDir = sys_get_temp_dir() . '/hawki-rag-python-api-isolation-' . uniqid();
        mkdir($publicDir, 0777, true);

        $python = <<<'PY'
import json
import sys
import types
from pathlib import Path
from types import SimpleNamespace

public_dir = Path(sys.argv[1])

fastapi = types.ModuleType("fastapi")
class HTTPException(Exception):
    def __init__(self, status_code=500, detail=None):
        super().__init__(detail)
        self.status_code = status_code
        self.detail = detail
fastapi.HTTPException = HTTPException
sys.modules["fastapi"] = fastapi

utils_pkg = types.ModuleType("utils")
utils_pkg.__path__ = []
text_preprocessor = types.ModuleType("utils.text_preprocessor")
text_preprocessor.ensure_tags = lambda payload, text: None
text_preprocessor.split_text = lambda text, chars, overlap: [text]
sys.modules["utils"] = utils_pkg
sys.modules["utils.text_preprocessor"] = text_preprocessor

vectorstore_pkg = types.ModuleType("vectorstore")
vectorstore_pkg.__path__ = []
qdrant_http = types.ModuleType("vectorstore.qdrant_http")
class QdrantHTTP:
    def __init__(self):
        self.collection = "test_collection"
        self.points = []
    def ensure_collection(self, vector_size, distance="Cosine"):
        self.vector_size = vector_size
    def upsert_points(self, points, batch_size=64):
        self.points.extend(points)
qdrant_http.QdrantHTTP = QdrantHTTP
sys.modules["vectorstore"] = vectorstore_pkg
sys.modules["vectorstore.qdrant_http"] = qdrant_http

graph_pkg = types.ModuleType("graph")
graph_pkg.__path__ = []
neo4j_graph = types.ModuleType("graph.neo4j_graph")
class Neo4jGraph:
    def __init__(self, *args, **kwargs):
        pass
    def close(self):
        pass
neo4j_graph.Neo4jGraph = Neo4jGraph
graph_visualization = types.ModuleType("graph.graph_visualization")
graph_visualization.write_graph_visualization = lambda *args, **kwargs: None
graph_utils = types.ModuleType("graph.graph_utils")
graph_utils.clean_triplets = lambda triplets: triplets
sys.modules["graph"] = graph_pkg
sys.modules["graph.neo4j_graph"] = neo4j_graph
sys.modules["graph.graph_visualization"] = graph_visualization
sys.modules["graph.graph_utils"] = graph_utils

observability = types.ModuleType("pipeline.observability")
observability.pipeline_log = lambda *args, **kwargs: None
sys.modules["pipeline.observability"] = observability

validation = types.ModuleType("pipeline.validation")
def validate_ingest_document(doc):
    errors = []
    if not getattr(doc, "id", ""):
        errors.append("id is required")
    if not getattr(doc, "text", ""):
        errors.append("text is required")
    return errors, []
validation.validate_ingest_document = validate_ingest_document
validation.normalize_ingest_metadata = lambda doc: dict(getattr(doc, "payload", {}) or {})
sys.modules["pipeline.validation"] = validation

from pipeline.ingest_logic import ingest_documents

class Provider:
    embed_model = "fake"
    def embed(self, text):
        if "FAIL_EMBED" in text:
            raise RuntimeError("forced embedding failure")
        return [0.1, 0.2, 0.3]

body = SimpleNamespace(
    dry_run=False,
    docs=[
        SimpleNamespace(id="ok-doc", text="this embeds", payload={"title": "OK", "source_url": "manual://ok"}),
        SimpleNamespace(id="bad-doc", text="FAIL_EMBED", payload={"title": "Bad", "source_url": "manual://bad"}),
    ],
    graph=False,
    graph_only=False,
    collection="test_collection",
    provider="fake",
    embedding_model=None,
    chunk_chars=1200,
    chunk_overlap=100,
    batch_size=64,
    distance="Cosine",
    graph_engine="raganything",
    neo4j_database=None,
)

result = ingest_documents(
    body,
    rag_service=None,
    get_provider=lambda provider_name: Provider(),
    public_dir=public_dir,
)

print(json.dumps(result))
assert result["ok"] is True
assert result["points"] == 1
assert result["summary"]["documents"]["processed_docs"] == 1
assert result["summary"]["documents"]["skipped_docs"] == 1
assert result["summary"]["documents"]["embedding_failed_docs"] == 1
assert result["summary"]["documents"]["embedding_failed_chunks"] == 1
PY;

        try {
            $process = new Process([
                'python3',
                '-c',
                $python,
                $publicDir,
            ], base_path(), [
                'PYTHONPATH' => base_path('python_rag'),
            ]);

            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true);
            $this->assertTrue($result['ok'] ?? false);
            $this->assertSame(1, $result['points'] ?? null);
            $this->assertSame(1, $result['summary']['documents']['embedding_failed_docs'] ?? null);
        } finally {
            @unlink($publicDir . '/ingest_summary.json');
            @rmdir($publicDir);
        }
    }

    public function test_ingest_status_endpoint_persists_detached_process_exit_code(): void
    {
        $statusPath = sys_get_temp_dir() . '/hawki-rag-ingest-status-test.json';
        $logPath = sys_get_temp_dir() . '/hawki-rag-ingest-log-test.log';

        @unlink($statusPath);
        @unlink($logPath);

        config([
            'config.ingest_status_path' => $statusPath,
            'config.ingest_log_cache_path' => $logPath,
        ]);

        file_put_contents($statusPath, json_encode([
            'ingests' => [[
                'id' => 'test-ingest',
                'status' => 'running',
                'started_at' => '2026-05-12T00:00:00+00:00',
            ]],
        ], JSON_PRETTY_PRINT));
        file_put_contents($logPath, implode(PHP_EOL, [
            'Scanning: /tmp/example',
            'INGEST_EXIT_CODE=' . PipelineExitCode::PARTIAL_SUCCESS,
            'INGEST_FAILED',
            '',
        ]));

        $response = $this->getJson('/ingest/status?mode=default');

        $response
            ->assertOk()
            ->assertJsonPath('status.status', 'failed')
            ->assertJsonPath('status.exit_code', PipelineExitCode::PARTIAL_SUCCESS);

        $persisted = json_decode((string) file_get_contents($statusPath), true);
        $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $persisted['ingests'][0]['exit_code'] ?? null);

        @unlink($statusPath);
        @unlink($logPath);
    }

    private function canReachHttpEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (!$host) {
            return false;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 2.0);
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);
        return true;
    }

    private function writeMinimalDocx(string $path, string $text): void
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Unable to create DOCX fixture.');

        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
        $zip->addFromString('word/document.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>{$escaped}</w:t></w:r></w:p>
  </w:body>
</w:document>
XML);
        $zip->close();
    }
}
