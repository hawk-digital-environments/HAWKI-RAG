<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Models\PipelineProfile;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PipelineProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communication.rabbitmq.pipeline_events.enabled', false);
    }

    public function test_profiles_can_be_created_listed_and_edited(): void
    {
        $this->withoutVite();

        $this->get('/pipeline-profiles')
            ->assertOk()
            ->assertSee('Pipeline Profiles');

        $this->postJson('/api/pipeline/profiles', [
            'profile_id' => 'hawk-main',
            'name' => 'HAWK main site',
            'description' => 'Main HAWK crawler profile',
            'start_urls' => [
                'https://www.hawk.de/de',
                'https://www.hawk.de/de/studium',
            ],
            'sitemap_url' => 'https://www.hawk.de/sitemap.xml',
            'max_pages' => 12,
            'allowed_file_types' => ['pdf', 'docx'],
            'graph_enabled' => true,
            'qdrant_collection' => 'hawki_hawk_main',
            'neo4j_namespace' => 'hawki_hawk_main',
            'metadata' => [
                'max_concurrency' => 2,
                'skip_images' => true,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('profile.profileId', 'hawk-main')
            ->assertJsonPath('profile.maxPages', 12)
            ->assertJsonPath('profile.graphEnabled', true);

        $this->getJson('/api/pipeline/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'profiles')
            ->assertJsonPath('profiles.0.profileId', 'hawk-main');

        $this->putJson('/api/pipeline/profiles/hawk-main', [
            'name' => 'HAWK main updated',
            'max_pages' => 20,
            'graph_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'HAWK main updated')
            ->assertJsonPath('profile.maxPages', 20)
            ->assertJsonPath('profile.graphEnabled', false);

        $this->assertDatabaseHas('pipeline_profiles', [
            'profile_id' => 'hawk-main',
            'name' => 'HAWK main updated',
            'max_pages' => 20,
            'graph_enabled' => false,
        ]);
    }

    public function test_task_can_be_started_from_profile_and_profile_settings_are_copied(): void
    {
        $this->profile('site-profile', [
            'start_urls' => [
                'https://www.hawk.de/de',
                'https://www.hawk.de/de/studium',
            ],
            'max_pages' => 8,
            'allowed_file_types' => ['pdf'],
            'graph_enabled' => true,
            'metadata' => [
                'max_concurrency' => 3,
                'skip_images' => true,
            ],
        ]);

        $this->postJson('/api/pipeline/profiles/site-profile/start-task', [
            'task_id' => 'task-from-profile',
        ])
            ->assertCreated()
            ->assertJsonPath('taskId', 'task-from-profile')
            ->assertJsonPath('task.profileId', 'site-profile')
            ->assertJsonPath('task.datasetId', 'site-profile')
            ->assertJsonPath('task.counters.queued', 2);

        $task = PipelineTask::query()->where('task_id', 'task-from-profile')->firstOrFail();
        $metadata = $task->metadata ?? [];
        $this->assertSame('site-profile', $metadata['request']['profile_id'] ?? null);
        $this->assertSame(8, $metadata['request']['metadata']['max_pages'] ?? null);
        $this->assertSame(['pdf'], $metadata['request']['metadata']['allowed_file_types'] ?? null);
        $this->assertTrue($metadata['request']['metadata']['graph'] ?? false);
        $this->assertSame('site-profile', $metadata['request']['metadata']['pipeline_profile']['profileId'] ?? null);
        $this->assertSame('hawki_site_profile', $metadata['dataset']['qdrant_collection'] ?? null);
        $this->assertSame('hawki_site_profile', $metadata['dataset']['neo4j_namespace'] ?? null);

        $job = PipelineJob::query()
            ->where('task_id', 'task-from-profile')
            ->where('source_url', 'https://www.hawk.de/de')
            ->firstOrFail();
        $this->assertSame(8, $job->metadata['max_pages'] ?? null);
        $this->assertSame(['pdf'], $job->metadata['allowed_file_types'] ?? null);
        $this->assertSame('site-profile', $job->metadata['pipeline_profile']['profileId'] ?? null);
    }

    public function test_direct_task_start_can_resolve_profile_id(): void
    {
        $this->profile('direct-profile', [
            'start_urls' => ['https://example.test/direct'],
            'max_pages' => 4,
        ]);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-direct-profile',
            'profile_id' => 'direct-profile',
        ])
            ->assertCreated()
            ->assertJsonPath('task.profileId', 'direct-profile')
            ->assertJsonPath('task.counters.queued', 1);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-direct-profile',
            'source_url' => 'https://example.test/direct',
            'status' => PipelineJob::STATUS_QUEUED,
        ]);
    }

    public function test_demo_pipeline_command_can_use_profile(): void
    {
        $this->profile('demo-profile', [
            'start_urls' => [
                'https://demo.example/a',
                'https://demo.example/b',
            ],
            'max_pages' => 2,
            'graph_enabled' => false,
        ]);

        $exitCode = Artisan::call('pipeline:demo', [
            '--dataset' => 'demo-profile-dataset',
            '--profile' => 'demo-profile',
            '--limit' => '1',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pipeline profile: demo-profile', Artisan::output());

        $task = PipelineTask::query()->where('dataset_id', 'demo-profile-dataset')->firstOrFail();
        $this->assertSame('demo-profile', $task->profile_id);
        $this->assertSame('demo-profile', $task->metadata['request']['metadata']['pipeline_profile']['profileId'] ?? null);
        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'source_url' => 'https://demo.example/a',
            'status' => PipelineJob::STATUS_QUEUED,
        ]);
        $this->assertSame(1, PipelineJob::query()->where('task_id', $task->task_id)->count());
    }

    private function profile(string $profileId, array $overrides = []): PipelineProfile
    {
        return PipelineProfile::query()->create(array_merge([
            'profile_id' => $profileId,
            'name' => $profileId,
            'description' => null,
            'start_urls' => ['https://example.test/start'],
            'sitemap_url' => null,
            'max_pages' => 1,
            'allowed_file_types' => ['pdf', 'docx'],
            'graph_enabled' => false,
            'qdrant_collection' => 'hawki_' . str_replace('-', '_', $profileId),
            'neo4j_namespace' => 'hawki_' . str_replace('-', '_', $profileId),
            'metadata' => [],
        ], $overrides));
    }
}
