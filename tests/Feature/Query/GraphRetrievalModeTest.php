<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GraphRetrievalModeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('retrievalModes')]
    public function test_mode_and_ready_ingestion_control_graph_retrieval(
        ?bool $fastMode,
        array $metadata,
        string $sourceStatus,
        bool $expectedGraph,
    ): void {
        $dataset = $this->prepareDataset();
        IngestionSource::query()->create([
            'source_id' => 'source-mode-test',
            'source_url' => 'https://example.test/source',
            'dataset_id' => $dataset->dataset_id,
            'index_status' => $sourceStatus,
            'metadata' => $metadata,
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'hits' => [], 'kg' => []])]);
        $payload = ['dataset_id' => $dataset->dataset_id, 'query' => 'Explain this dataset.'];
        if ($fastMode !== null) {
            $payload['fast_mode'] = $fastMode;
        }

        $this->postJson('/api/query', $payload)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === $expectedGraph
            && $request['fast_mode'] === ($fastMode ?? true)
        );
    }

    public static function retrievalModes(): array
    {
        return [
            'default stays fast even with graph content' => [null, ['graph' => true], 'ready', false],
            'explicit fast skips graph content' => [true, ['graph' => true], 'ready', false],
            'deep uses ready graph content' => [false, ['graph' => true], 'ready', true],
            'deep respects vector-only ingestion' => [false, ['graph' => false], 'ready', false],
            'deep respects nested ingestion override' => [false, ['graph' => true, 'request' => ['metadata' => ['graph' => false]]], 'ready', false],
            'deep supports explicit crawler graph setting' => [false, ['request' => ['metadata' => ['graph' => 'true']]], 'ready', true],
            'direct text cannot enable graph' => [false, ['ingestion_mode' => 'direct_text', 'graph' => true], 'ready', false],
            'unknown ingestion setting stays off' => [false, [], 'ready', false],
            'pending graph is not ready' => [false, ['graph' => true], 'running', false],
            'deleted graph source is excluded' => [false, ['graph' => true], 'deleted', false],
            'old upload source alone cannot enable graph' => [false, ['upload' => [], 'graph' => true], 'ready', false],
        ];
    }

    public function test_vector_only_dataset_does_not_require_a_graph_namespace(): void
    {
        $dataset = $this->prepareDataset();
        $dataset->update(['neo4j_namespace' => '']);
        Http::fake(['*' => Http::response(['ok' => true, 'hits' => [], 'kg' => []])]);

        $this->postJson('/api/query', [
            'dataset_id' => $dataset->dataset_id,
            'query' => 'Search vectors only.',
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === false
        );
    }

    public function test_deep_retrieval_tracks_active_file_graph_outputs(): void
    {
        $dataset = $this->prepareDataset();
        $document = ManagedDocument::query()->create([
            'document_id' => 'adoc_graph_mode',
            'dataset_id' => $dataset->dataset_id,
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);
        $output = ManagedDocumentOutput::query()->create([
            'document_id' => $document->document_id,
            'bridge_document_id' => 'doc_graph_mode',
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
            'active' => true,
            'status' => 'indexed',
        ]);
        $payload = ['dataset_id' => $dataset->dataset_id, 'query' => 'Find facts.', 'fast_mode' => false];

        // A graph-enabled file permits graph retrieval only while its outputs remain active.
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->postJson('/api/query', $payload)->assertOk();
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === true);

        $document->update(['graph_enabled' => false]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->postJson('/api/query', $payload)->assertOk();
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === false);

        $document->update(['graph_enabled' => true]);
        $output->update(['active' => false]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->postJson('/api/query', $payload)->assertOk();
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === false);
    }

    public function test_graph_only_search_does_not_contact_neo4j_for_vector_only_content(): void
    {
        $dataset = $this->prepareDataset();
        Http::fake();

        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id='.$dataset->dataset_id.'&q=research')
            ->assertStatus(409)
            ->assertJsonPath('error', 'dataset_graph_not_ready');

        Http::assertNothingSent();
    }

    public function test_deep_recognizes_a_completed_upload_before_document_details_are_viewed(): void
    {
        $dataset = $this->prepareDataset();
        $source = IngestionSource::query()->create([
            'source_id' => 'source-unprojected-upload',
            'source_url' => 'upload://research.md',
            'dataset_id' => $dataset->dataset_id,
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => ['upload' => [], 'request' => ['metadata' => ['graph' => true]]],
        ]);
        $document = ManagedDocument::query()->create([
            'document_id' => 'adoc_unprojected_graph',
            'dataset_id' => $dataset->dataset_id,
            'latest_source_id' => $source->source_id,
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_PROCESSING,
        ]);
        $payload = ['dataset_id' => $dataset->dataset_id, 'query' => 'Find facts.', 'fast_mode' => false];
        $assertGraph = function (bool $expected) use ($payload): void {
            Http::fake(['*' => Http::response(['ok' => true])]);
            $this->postJson('/api/query', $payload)->assertOk();
            Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.graph_enabled') === $expected);
        };

        $assertGraph(true);
        $document->update(['graph_enabled' => false]);
        $assertGraph(false);
        $document->update(['graph_enabled' => true, 'latest_source_id' => 'source-replacement']);
        $assertGraph(false);
        $document->update(['latest_source_id' => $source->source_id, 'status' => ManagedDocument::STATUS_DELETING]);
        $assertGraph(false);
        $document->update(['status' => ManagedDocument::STATUS_DELETED, 'deleted_at' => now()]);
        $assertGraph(false);
    }

    private function prepareDataset(): Dataset
    {
        $user = $this->actingAsApiUser();
        $dataset = Dataset::query()->create([
            'dataset_id' => 'retrieval-modes',
            'name' => 'Retrieval modes',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_retrieval_modes',
            'neo4j_namespace' => 'graph_retrieval_modes',
        ]);
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);

        return $dataset;
    }
}
