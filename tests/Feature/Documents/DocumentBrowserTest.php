<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\PipelineJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class DocumentBrowserTest extends TestCase
{
    use RefreshDatabase;

    private ?QueryBffFixture $queryBff = null;

    private array $pythonReads = [];

    protected function tearDown(): void
    {
        $this->queryBff?->close();
        parent::tearDown();
    }

    public function test_documents_page_redirects_to_dataset_browser(): void
    {
        $this->withoutVite();

        $this->get('/documents')
            ->assertRedirect('/datasets');
    }

    public function test_document_detail_returns_not_found_for_non_numeric_placeholder_id(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/documents/sample-document-id')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_uploaded_source_document_can_be_downloaded_by_upload_uri(): void
    {
        config()->set('config.managed_document_backend', 'python');
        $sourceUrl = 'upload://NordVPN_Instructions_V1.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $path = $uploadRoot.'/task-upload/NordVPN_Instructions_V1.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF test document');
        $this->configureUploadDownloadRoot($uploadRoot);

        PipelineJob::query()->create([
            'job_id' => 'ingest-upload-download',
            'task_id' => 'task-upload-download',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $path,
            'content_hash' => hash_file('sha256', $path),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [
                'upload' => [
                    'original_filename' => 'NordVPN_Instructions_V1.pdf',
                    'local_path' => $path,
                ],
            ],
        ]);

        $this->actingAsApiUser();

        $this->get('/api/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
            ->assertOk()
            ->assertDownload('NordVPN_Instructions_V1.pdf');
    }

    public function test_uploaded_source_document_can_be_recovered_from_shared_upload_storage_without_database_record(): void
    {
        $sourceUrl = 'upload://NordVPN_Instructions_V1.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $path = $uploadRoot.'/task_controller_upload_20260623_021720_3zvjvk/nordvpn-instructions-v1-7svxes3l.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF recovered document');
        $this->configureUploadDownloadRoot($uploadRoot);

        $this->actingAsApiUser();

        $this->get('/api/documents/uploads/download?'.http_build_query([
            'source_url' => $sourceUrl,
            'content_hash' => '1c693e60d1cda122f4e3d61a7cbf8c4eb72b82228da494d27455885b0a7dc54f',
        ]))
            ->assertOk()
            ->assertDownload('NordVPN_Instructions_V1.pdf');
    }

    public function test_uploaded_source_document_download_rejects_paths_outside_shared_roots(): void
    {
        $sourceUrl = 'upload://outside.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $outsidePath = storage_path('framework/testing/outside/outside.pdf');
        File::ensureDirectoryExists(dirname($outsidePath));
        File::put($outsidePath, 'outside file');
        $this->configureUploadDownloadRoot($uploadRoot);

        PipelineJob::query()->create([
            'job_id' => 'ingest-upload-outside',
            'task_id' => 'task-upload-outside',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $outsidePath,
            'content_hash' => hash_file('sha256', $outsidePath),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        $this->actingAsApiUser();

        $this->getJson('/api/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public static function pythonPublicationCases(): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/python_managed_upload.fixture'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (array $case): array => [$case], [$fixture, ...$fixture['cases']]);
    }

    #[DataProvider('pythonPublicationCases')]
    public function test_python_managed_upload_can_be_downloaded_without_legacy_rows(array $fixture): void
    {
        $root = sys_get_temp_dir().'/python-managed-download-'.bin2hex(random_bytes(6));
        $metadata = $this->pythonUpload($root, $fixture);
        $path = $root.'/'.$metadata['upload']['relative_path'];
        $this->actingAsApiUser();
        try {
            $this->assertDatabaseCount('pipeline_jobs', 0);
            $this->assertDatabaseCount('ingestion_sources', 0);
            $response = $this->get('/api/documents/uploads/download?'.http_build_query([
                'document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename'], 'content_hash' => hash_file('sha256', $path),
            ]))->assertOk()->assertDownload();
            if (preg_match('/[^\x20-\x7E]/', $fixture['filename'])) {
                $this->assertStringContainsString("filename*=utf-8''".rawurlencode($fixture['filename']), $response->headers->get('Content-Disposition'));
            } else {
                $response->assertDownload($fixture['filename']);
            }
            $this->assertSame(realpath($path), $response->baseResponse->getFile()->getRealPath());
            Http::assertSentCount(1);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_authorized_task_wins_over_newer_same_named_upload_without_caller_hash(): void
    {
        $root = sys_get_temp_dir().'/python-managed-isolation-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $foreign = $root.'/task_managed_'.str_repeat('b', 32).'/nordvpn-instructions-v1-bbbbbbbb.pdf';
        File::ensureDirectoryExists(dirname($foreign));
        File::put($foreign, 'Foreign task contents');
        touch($foreign, time() + 60);
        $this->actingAsApiUser();
        try {
            $response = $this->get('/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename']]))->assertOk();
            $this->assertSame(realpath($root.'/'.$fixture['relative_path']), $response->baseResponse->getFile()->getRealPath());
            $this->assertNotSame(realpath($foreign), $response->baseResponse->getFile()->getRealPath());
        } finally {
            File::deleteDirectory($root);
        }
    }

    public static function pythonOriginalFilenamePunctuationCases(): array
    {
        return array_filter(self::pythonPublicationCases(), static fn (array $case): bool => in_array($case[0]['filename'], [
            'report [final].pdf', 'report (final).pdf', 'report & final.pdf', 'résumé [final].pdf',
        ], true));
    }

    #[DataProvider('pythonOriginalFilenamePunctuationCases')]
    public function test_original_filename_punctuation_never_selects_an_alternate_artifact(array $fixture): void
    {
        $root = sys_get_temp_dir().'/python-managed-punctuation-'.bin2hex(random_bytes(6));
        $metadata = $this->pythonUpload($root, $fixture);
        $path = $root.'/'.$fixture['relative_path'];
        $foreign = $root.'/task_managed_'.str_repeat('b', 32).'/'.basename($path);
        File::ensureDirectoryExists(dirname($foreign));
        foreach ([$foreign, dirname($path).'/'.$fixture['filename'], dirname($path).'/report f.pdf'] as $decoy) {
            File::put($decoy, $fixture['contents']);
        }
        $this->actingAsApiUser();
        $url = '/api/documents/uploads/download?'.http_build_query([
            'document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename'],
        ]);
        try {
            $response = $this->get($url)->assertOk();
            $this->assertSame(realpath($path), $response->baseResponse->getFile()->getRealPath());
            File::delete($path);
            $this->getJson($url)->assertNotFound();
            Http::assertSentCount(2);
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[TestWith(['wrong-request-hash'])]
    #[TestWith(['tampered-artifact'])]
    #[TestWith(['missing-artifact'])]
    public function test_hash_mismatch_or_missing_owned_file_never_falls_back_to_another_task(string $failure): void
    {
        $root = sys_get_temp_dir().'/python-managed-hash-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $foreign = $root.'/task_managed_'.str_repeat('b', 32).'/nordvpn-instructions-v1-bbbbbbbb.pdf';
        File::ensureDirectoryExists(dirname($foreign));
        File::put($foreign, $fixture['contents']);
        $hash = $metadata['upload']['sha256'];
        if ($failure === 'wrong-request-hash') {
            $hash = str_repeat('f', 64);
        } elseif ($failure === 'tampered-artifact') {
            File::put($root.'/'.$fixture['relative_path'], 'Changed owned artifact');
        } else {
            File::delete($root.'/'.$fixture['relative_path']);
        }
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename'], 'content_hash' => $hash]))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[TestWith(['file'])]
    #[TestWith(['directory'])]
    #[TestWith(['outside-root'])]
    public function test_authorized_task_cannot_follow_symlink_to_another_task_with_identical_bytes(string $kind): void
    {
        $root = sys_get_temp_dir().'/python-managed-symlink-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $path = $root.'/'.$fixture['relative_path'];
        $foreign = ($kind === 'outside-root' ? $root.'-outside' : $root.'/task_managed_'.str_repeat('b', 32)).'/'.basename($path);
        File::ensureDirectoryExists(dirname($foreign));
        File::put($foreign, $fixture['contents']);
        if ($kind !== 'directory') {
            File::delete($path);
            symlink($foreign, $path);
        } else {
            File::deleteDirectory(dirname($path));
            symlink(dirname($foreign), dirname($path));
        }
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename']]))->assertNotFound();
        } finally {
            if ($kind === 'directory') {
                unlink(dirname($path));
            }
            File::deleteDirectory($root);
            File::deleteDirectory($root.'-outside');
        }
    }

    public function test_python_upload_requires_a_query_principal_and_rejects_invalid_explicit_credentials(): void
    {
        $root = sys_get_temp_dir().'/python-managed-principal-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $url = '/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename']]);
        try {
            $this->getJson($url)->assertUnauthorized();
            $user = $this->actingAsApiUser();
            $this->withToken('invalid')->getJson($url)->assertUnauthorized();
            $this->withToken($user->createToken('no-query', ['rag:ingest'])->plainTextToken)->getJson($url)->assertUnauthorized();
            Http::assertNothingSent();
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[TestWith(['upload', null])]
    #[TestWith(['upload.relative_path', 'task_managed_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/nordvpn-instructions-v1-bbbbbbbb.pdf'])]
    #[TestWith(['upload.relative_path', '../escaped.pdf'])]
    #[TestWith(['upload.relative_path', '/shared/escaped.pdf'])]
    #[TestWith(['upload.relative_path', 'task_managed_0123456789abcdef0123456789abcdef/*.pdf'])]
    #[TestWith(['upload.source_id', 'source_foreign'])]
    #[TestWith(['latest_task_id', 'task_foreign'])]
    #[TestWith(['document_id', 'adoc_foreign'])]
    public function test_missing_or_mismatched_authoritative_identity_cannot_resolve(string $field, mixed $value): void
    {
        $root = sys_get_temp_dir().'/python-managed-identity-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        data_set($this->pythonReads[$metadata['document_id']]['payload']['document'], $field, $value);
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename']]))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_python_upload_without_authorized_document_cannot_use_filename_or_legacy_row(): void
    {
        $root = sys_get_temp_dir().'/python-managed-no-owner-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $legacyGuess = $root.'/task_controller_upload_legacy/nordvpn-instructions-v1-legacy.pdf';
        File::ensureDirectoryExists(dirname($legacyGuess));
        File::put($legacyGuess, 'An unproven Python request must not guess a legacy artifact either');
        $this->actingAsApiUser();
        try {
            $query = ['source_url' => 'upload://'.$fixture['filename']];
            $this->getJson('/api/documents/uploads/download?'.http_build_query($query))->assertNotFound();
            $this->getJson('/api/documents/uploads/download?'.http_build_query([...$query, 'document_id' => 'adoc_forged']))->assertNotFound();
            foreach (['task_id' => $fixture['task_id'], 'source_id' => $metadata['latest_source_id']] as $field => $value) {
                $this->getJson('/api/documents/uploads/download?'.http_build_query([...$query, $field => $value]))->assertUnprocessable();
            }
            PipelineJob::query()->create(['job_id' => 'legacy-python-path', 'source_url' => $query['source_url'], 'local_path' => $root.'/'.$fixture['relative_path'], 'status' => 'completed']);
            $this->getJson('/api/documents/uploads/download?'.http_build_query($query))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([404])]
    #[TestWith([503])]
    public function test_python_authorization_failure_never_falls_back_to_legacy_download(int $status): void
    {
        $root = sys_get_temp_dir().'/python-managed-denied-'.bin2hex(random_bytes(6));
        $fixture = self::pythonPublicationCases()[0][0];
        $metadata = $this->pythonUpload($root, $fixture);
        $this->pythonReads[$metadata['document_id']] = ['status' => $status, 'payload' => ['success' => false]];
        $legacy = $root.'/task_controller_upload_legacy/legacy.pdf';
        File::ensureDirectoryExists(dirname($legacy));
        File::put($legacy, 'Legacy bytes must not substitute for denied Python identity');
        PipelineJob::query()->create(['job_id' => 'legacy-same-name', 'source_url' => 'upload://'.$fixture['filename'], 'local_path' => $legacy, 'status' => 'completed']);
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['document_id' => $metadata['document_id'], 'source_url' => 'upload://'.$fixture['filename']]))->assertStatus($status);
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function pythonUpload(string $root, array $fixture): array
    {
        if ($this->queryBff === null) {
            $this->queryBff = new QueryBffFixture;
            config()->set(['config.python_managed_document_url' => 'http://python.test', 'config.query_bff' => $this->queryBff->config->get('config.query_bff')]);
            Http::preventStrayRequests();
            Http::fake(['http://python.test/v1/documents/*' => function ($request) {
                $result = $this->pythonReads[rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)))] ?? ['status' => 404, 'payload' => ['success' => false]];

                return Http::response($result['payload'], $result['status']);
            }]);
        }
        $path = $root.'/'.$fixture['relative_path'];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $fixture['contents']);
        $this->configureUploadDownloadRoot($root);
        config()->set('config.managed_document_backend', 'python');
        $document = ['document_id' => 'adoc_python', 'dataset_id' => 'only-python', 'source_type' => 'upload', 'status' => 'indexed', 'latest_task_id' => $fixture['task_id'], 'latest_source_id' => 'source_'.str_repeat('a', 32), 'upload' => ['task_id' => $fixture['task_id'], 'source_id' => 'source_'.str_repeat('a', 32), 'relative_path' => $fixture['relative_path'], 'filename' => $fixture['filename'], 'sha256' => hash_file('sha256', $path)]];
        $this->pythonReads[$document['document_id']] = ['status' => 200, 'payload' => ['success' => true, 'document' => $document]];

        return $document;
    }

    #[TestWith(['../example.pdf'])]
    #[TestWith(['%2e%2e%2fexample.pdf'])]
    #[TestWith(['/shared/example.pdf'])]
    #[TestWith(['..\\example.pdf'])]
    #[TestWith(['*.pdf'])]
    #[TestWith(['[example].pdf'])]
    public function test_upload_download_rejects_traversal_and_glob_input(string $name): void
    {
        $root = sys_get_temp_dir().'/python-managed-unsafe-'.bin2hex(random_bytes(6));
        $path = $root.'/task_managed_0123456789abcdef0123456789abcdef/example-89abcdef.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF must not be exposed by unsafe input');
        $this->configureUploadDownloadRoot($root);
        config()->set('config.managed_document_backend', 'python');
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['source_url' => 'upload://'.$name]))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_python_upload_lookup_does_not_expose_unrelated_files_or_symlink_escapes(): void
    {
        $root = sys_get_temp_dir().'/python-managed-bounded-'.bin2hex(random_bytes(6));
        $files = [
            $root.'/task_managed_0123456789abcdef0123456789abcdef/unrelated-89abcdef.pdf',
            $root.'/unrelated-directory/example-89abcdef.pdf',
            $root.'/task_managed_0123456789abcdef0123456789abcdef/nested/example-89abcdef.pdf',
            $root.'-outside/example-89abcdef.pdf',
        ];
        foreach ($files as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, '%PDF unrelated file');
        }
        symlink($files[3], $root.'/task_managed_0123456789abcdef0123456789abcdef/example-89abcdef.pdf');
        $this->configureUploadDownloadRoot($root);
        config()->set('config.managed_document_backend', 'python');
        $this->actingAsApiUser();
        try {
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['source_url' => 'upload://example.pdf']))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($root.'-outside');
        }
    }

    private function configureUploadDownloadRoot(string $root): void
    {
        File::ensureDirectoryExists($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('config.pipeline_root', $root);
        config()->set('config.shared_root', $root);
        config()->set('config.crawled_data_root', $root);
    }
}
