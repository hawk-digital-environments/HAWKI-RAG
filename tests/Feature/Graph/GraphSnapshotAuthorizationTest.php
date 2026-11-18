<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\User\Values\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphSnapshotAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_ownership_controls_listing_loading_and_deletion_under_query_all(): void
    {
        $directory = sys_get_temp_dir().'/epoch14-owned-snapshots-'.bin2hex(random_bytes(6));
        config()->set(['config.graph_snapshot_path' => $directory, 'config.query_bff.tenant' => 'tenant-a',
            'config.query_auth.all_datasets_by_default' => true,
            'config.graph_ops_backend' => 'laravel', 'config.dataset_backend' => 'laravel']);
        $this->app->forgetInstance(DatasetQueryAuthorizationService::class);
        Http::fake();
        $users = [];
        foreach (['a', 'b'] as $id) {
            $users[$id] = User::query()->create(['username' => $id, 'email' => $id.'@example.test', 'ip' => $id === 'a' ? '127.0.0.1' : '127.0.0.2']);
            Dataset::query()->create(['dataset_id' => $id, 'name' => $id, 'status' => 'active',
                'qdrant_collection' => 'vector-'.$id, 'neo4j_namespace' => 'graph-'.$id]);
            DatasetGrant::query()->create(['dataset_id' => $id, 'principal_type' => 'user',
                'principal_id' => (string) $users[$id]->getAuthIdentifier(), 'permission' => 'query']);
        }
        try {
            $this->actingAs($users['a']);
            $response = $this->postJson('/api/rag/neo4j/graph/snapshots', ['dataset_id' => 'a',
                'tenant_id' => 'spoofed', 'name' => 'Private search name',
                'scene' => ['nodes' => [], 'edges' => [], 'search' => 'Private search text']])
                ->assertOk()->assertJsonPath('snapshot.tenant_id', 'tenant-a');
            $id = $response->json('snapshot.id');
            $stored = json_decode(file_get_contents($directory.'/'.$id.'.json'), true);
            $this->assertSame('a', $stored['dataset_id']);
            $this->assertSame('tenant-a', $stored['tenant_id']);
            $this->getJson('/api/rag/neo4j/graph/snapshots')->assertOk()->assertJsonPath('snapshots.0.id', $id);
            $this->getJson('/api/rag/neo4j/graph/snapshots/'.$id)->assertOk()->assertJsonPath('snapshot.scene.search', 'Private search text');

            $this->actingAs($users['b']);
            $this->getJson('/api/rag/neo4j/graph/snapshots?dataset_id=b&allow_legacy=true')
                ->assertOk()->assertExactJson(['ok' => true, 'snapshots' => []])
                ->assertDontSee('Private search')->assertDontSee($id);
            $this->getJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=b&allow_legacy=true')->assertNotFound()->assertDontSee('Private search');
            $this->deleteJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=b')->assertNotFound();
            $this->assertFileExists($directory.'/'.$id.'.json');
            $this->postJson('/api/rag/neo4j/graph/snapshots', ['dataset_id' => 'a', 'scene' => ['nodes' => []]])->assertNotFound();

            $this->actingAs($users['a']);
            config()->set('config.query_bff.tenant', 'tenant-b');
            $this->getJson('/api/rag/neo4j/graph/snapshots')->assertExactJson(['ok' => true, 'snapshots' => []]);
            $this->getJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=a')->assertNotFound();
            $this->deleteJson('/api/rag/neo4j/graph/snapshots/'.$id)->assertNotFound();
            config()->set('config.query_bff.tenant', 'tenant-a');
            $this->deleteJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=b')->assertOk();
            $this->assertFileDoesNotExist($directory.'/'.$id.'.json');

            foreach ([['id' => 'legacy'], ['id' => 'old-bound', 'dataset_id' => 'a']] as $legacy) {
                file_put_contents($directory.'/'.$legacy['id'].'.json', json_encode($legacy + [
                    'name' => 'Private legacy name', 'scene' => ['search' => 'Dataset A legacy scene']]));
                foreach ([$users['b'], $users['a']->forceFill(['role' => UserRole::Admin])] as $user) {
                    $this->actingAs($user);
                    $this->getJson('/api/rag/neo4j/graph/snapshots/'.$legacy['id'].'?dataset_id=b&allow_legacy=true')->assertNotFound()->assertDontSee('Dataset A');
                    $this->deleteJson('/api/rag/neo4j/graph/snapshots/'.$legacy['id'].'?dataset_id=b&allow_legacy=true')->assertNotFound();
                    $this->getJson('/api/rag/neo4j/graph/snapshots')->assertExactJson(['ok' => true, 'snapshots' => []]);
                }
            }
            Http::assertNothingSent();
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
