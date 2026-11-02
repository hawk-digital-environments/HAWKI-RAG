<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\Repositories\QueryCutoverInventoryRepository;
use App\Services\Dataset\Repositories\DatasetGraphAvailabilityRepository;
use App\Services\Rag\Clients\PythonQueryClient;
use App\Services\Rag\RagProxyService;
use App\Services\Rag\RagQueryService;
use App\Services\Settings\SettingsService;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class QueryCutoverToolsTest extends TestCase
{
    public function test_export_uses_effective_models_and_graph_readiness_without_secrets(): void
    {
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $inventory->expects(self::once())->method('datasets')->willReturn(collect([
            new Dataset(['dataset_id' => 'a', 'status' => 'active', 'qdrant_collection' => 'qa', 'neo4j_namespace' => 'na', 'embedding_provider' => 'ollama', 'embedding_model' => 'pinned-embedding']),
        ]));
        $graphs = $this->createMock(DatasetGraphAvailabilityRepository::class);
        $graphs->expects(self::once())->method('hasReadyGraph')->with('a')->willReturn(true);
        $settings = $this->createMock(SettingsService::class);
        $settings->expects(self::once())->method('modelRuntimeForProvider')->with('ollama')->willReturn([
            'provider' => 'ollama', 'graph_model' => 'effective-chat', 'vision_model' => 'effective-vision', 'embedding_model' => 'global-embedding',
        ]);
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);
        $this->app->instance(DatasetGraphAvailabilityRepository::class, $graphs);
        $this->app->instance(SettingsService::class, $settings);
        self::assertSame(0, Artisan::call('query:cutover-export'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('laravel', $report['backend']);
        self::assertSame(config('config.python_query_url'), $report['python_query_url']);
        self::assertSame(rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query', $report['legacy_bridge_query_url']);
        self::assertContains(public_path(), $report['key_forbidden_paths']);
        self::assertContains(storage_path(), $report['key_forbidden_paths']);
        self::assertTrue($report['datasets'][0]['graph_enabled']);
        self::assertSame('pinned-embedding', $report['datasets'][0]['embedding_model']);
        self::assertSame(['chat_model' => 'effective-chat', 'vision_model' => 'effective-vision'], $report['models']['ollama']);
        self::assertNull($report['key']);
        self::assertArrayNotHasKey('private_key_path', $report['bff']);
        self::assertStringNotContainsString('PRIVATE KEY', $output);
    }

    public function test_optional_key_inspection_exposes_only_public_fingerprint_and_metadata(): void
    {
        $fixture = new QueryBffFixture;
        config()->set('config.query_bff', $fixture->config->get('config.query_bff'));
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $inventory->method('datasets')->willReturn(collect());
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);
        try {
            self::assertSame(0, Artisan::call('query:cutover-export', ['--check-key' => true]));
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(2048, $report['key']['bits']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['key']['fingerprint']);
            self::assertStringNotContainsString('PRIVATE KEY', Artisan::output());
        } finally {
            $fixture->close();
        }
    }

    #[DataProvider('unsafePythonUrls')]
    public function test_export_refuses_unsafe_effective_python_url(string $url): void
    {
        config()->set('config.python_query_url', $url);
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $inventory->method('datasets')->willReturn(collect());
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);

        self::assertSame(1, Artisan::call('query:cutover-export'));
        self::assertStringNotContainsString($url, Artisan::output());
    }

    public static function unsafePythonUrls(): array
    {
        return [
            ['http://hawki_backend/v1/query'."\n"],
            ['http://user:secret@hawki_backend/v1/query'],
            ['http://hawki_backend/other/query'],
        ];
    }

    public function test_export_uses_laravels_effective_legacy_bridge_endpoint(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge/');
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $inventory->method('datasets')->willReturn(collect());
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);

        self::assertSame(0, Artisan::call('query:cutover-export'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('http://hawki_rag_bridge/query', $report['legacy_bridge_query_url']);
    }

    #[DataProvider('unsafeBridgeUrls')]
    public function test_export_refuses_unsafe_legacy_bridge_url(mixed $url): void
    {
        config()->set('config.hawki_rag_bridge_url', $url);
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $inventory->method('datasets')->willReturn(collect());
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);

        self::assertSame(1, Artisan::call('query:cutover-export'));
        self::assertStringNotContainsString('secret', Artisan::output());
    }

    public static function unsafeBridgeUrls(): array
    {
        return [
            [null],
            ['http://user:secret@hawki_rag_bridge'],
            ['http://hawki_rag_bridge?secret=1'],
            ['http://hawki_rag_bridge'."\n"],
            ['http://hawki_rag_bridge/token'],
            ['http://hawki_rag_bridge/%73ecret'],
        ];
    }

    public function test_probe_is_explicit_non_generating_and_never_dispatches_legacy_by_default(): void
    {
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $user = new User;
        $user->id = 7;
        $inventory->expects(self::once())->method('activeUser')->with('7')->willReturn($user);
        $python = $this->createMock(PythonQueryClient::class);
        $python->expects(self::once())->method('query')->with($user, ['dataset_id' => 'a', 'query' => 'handbook policy', 'generate' => false, 'top_k' => 5, 'fast_mode' => false])->willReturn(['status' => 200, 'payload' => ['ok' => true]]);
        $legacy = $this->createMock(RagProxyService::class);
        $legacy->expects(self::never())->method('query');
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);
        $this->app->instance(PythonQueryClient::class, $python);
        $this->app->instance(RagProxyService::class, $legacy);
        self::assertSame(0, Artisan::call('query:cutover-probe', ['user' => '7', 'dataset' => 'a', '--graph' => true]));
        self::assertSame('laravel', config('config.query_backend'));
    }

    public function test_legacy_probe_dispatches_laravels_rollback_service(): void
    {
        $inventory = $this->createMock(QueryCutoverInventoryRepository::class);
        $user = new User;
        $user->id = 7;
        $inventory->method('activeUser')->with('7')->willReturn($user);
        $python = $this->createMock(PythonQueryClient::class);
        $python->expects(self::never())->method('query');
        $legacy = $this->createMock(RagProxyService::class);
        $legacy->expects(self::once())->method('query')->with($user, ['dataset_id' => 'a', 'query' => 'handbook policy', 'generate' => false, 'top_k' => 5, 'fast_mode' => true])->willReturn(['status' => 200, 'payload' => ['ok' => true]]);
        $this->app->instance(QueryCutoverInventoryRepository::class, $inventory);
        $this->app->instance(PythonQueryClient::class, $python);
        $this->app->instance(RagProxyService::class, $legacy);

        self::assertSame(0, Artisan::call('query:cutover-probe', ['user' => '7', 'dataset' => 'a', '--legacy' => true]));
    }

    #[DataProvider('pythonErrors')]
    public function test_python_errors_keep_the_browser_envelope(int $status, mixed $detail, string $expected): void
    {
        $service = new RagQueryService(
            new Repository(['config' => ['query_backend' => 'python']]),
            $this->createMock(RagProxyService::class),
            $python = $this->createMock(PythonQueryClient::class),
            new NullLogger,
        );
        $python->method('query')->willReturn(['status' => $status, 'payload' => ['detail' => $detail]]);
        $result = $service->query(new User, []);
        self::assertSame($status, $result['status']);
        self::assertFalse($result['payload']['ok']);
        self::assertSame($expected, $result['payload']['error']);
        self::assertIsString($result['payload']['message']);
        self::assertArrayNotHasKey('detail', $result['payload']);
        if ($status === 422) {
            self::assertArrayHasKey('errors', $result['payload']);
        }
    }

    public static function pythonErrors(): array
    {
        return [
            [401, 'invalid_token', 'invalid_token'],
            [404, ['error' => 'dataset_not_found', 'message' => 'Dataset not found.'], 'dataset_not_found'],
            [422, [['type' => 'missing', 'loc' => ['body', 'query']]], 'validation_error'],
            [502, 'query_backend_unavailable', 'query_backend_unavailable'],
        ];
    }
}
