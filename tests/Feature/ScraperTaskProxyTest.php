<?php

namespace Tests\Feature;

use App\Models\PipelineStageState;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScraperTaskProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_scraper_ui_profile_and_stored_tasks_are_normalized_for_the_playground(): void
    {
        config()->set('scraper.task_ui_url', 'http://scraper-ui.test');

        Http::fake([
            'http://scraper-ui.test/ui/api/profiles' => Http::response([
                'profiles' => [
                    $this->profileEntry(),
                ],
            ], 200),
            'http://scraper-ui.test/ui/api/tasks' => Http::response([
                'tasks' => [
                    [
                        'id' => 'scheduled-goettingen',
                        'name' => 'Daily Goettingen Crawl',
                        'profileId' => 'site-goettingen',
                        'schedule' => '0 3 * * *',
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/scraper/tasks')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'tasks')
            ->assertJsonFragment([
                'id' => 'manual-site-goettingen',
                'label' => 'University Goettingen Manual Crawl',
                'profileId' => 'site-goettingen',
                'source' => 'scraper-task-ui',
                'type' => 'manual',
            ])
            ->assertJsonFragment([
                'id' => 'scheduled-goettingen',
                'label' => 'Daily Goettingen Crawl',
                'profileId' => 'site-goettingen',
                'schedule' => '0 3 * * *',
                'source' => 'scraper-task-ui',
                'type' => 'scheduled',
            ]);
    }

    public function test_scraper_tasks_use_mounted_task_ui_api_defaults(): void
    {
        config()->set('scraper.task_ui_url', 'http://scraper-ui.test');

        Http::fake([
            'http://scraper-ui.test/ui/api/profiles' => Http::response([
                'profiles' => [
                    $this->profileEntry(),
                ],
            ], 200),
            'http://scraper-ui.test/ui/api/tasks' => Http::response([
                'tasks' => [
                    [
                        'id' => 'scheduled-goettingen',
                        'name' => 'Daily Goettingen Crawl',
                        'profileId' => 'site-goettingen',
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/scraper/tasks')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'tasks')
            ->assertJsonFragment([
                'id' => 'scheduled-goettingen',
                'label' => 'Daily Goettingen Crawl',
                'profileId' => 'site-goettingen',
                'source' => 'scraper-task-ui',
            ]);
    }

    public function test_empty_scraper_tasks_show_build_message(): void
    {
        config()->set('scraper.task_ui_url', 'http://scraper-ui.test');
        config()->set('scraper.api_url', 'http://crawler.test');

        Http::fake([
            'http://scraper-ui.test/ui/api/profiles' => Http::response(['profiles' => []], 200),
            'http://scraper-ui.test/ui/api/tasks' => Http::response(['tasks' => []], 200),
            'http://crawler.test/tasks' => Http::response(['tasks' => []], 200),
        ]);

        $this->getJson('/scraper/tasks')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Please build the HAWKI-Scraper for getting available tasks.');
    }

    public function test_starting_scraper_task_returns_scraper_created_job_id(): void
    {
        config()->set('scraper.task_ui_url', 'http://scraper-ui.test');

        Http::fake([
            'http://scraper-ui.test/ui/api/profiles' => Http::response([
                'profiles' => [
                    $this->profileEntry(),
                ],
            ], 200),
            'http://scraper-ui.test/ui/api/tasks' => Http::response(['tasks' => []], 200),
            'http://scraper-ui.test/ui/api/profiles/site-goettingen' => Http::response($this->profileEntry(), 200),
            'http://scraper-ui.test/ui/api/crawler/submit' => Http::response([
                'event' => 'job_submitted',
                'job_id' => 'site-goettingen_123',
            ], 200),
        ]);

        $this
            ->withSession(['_token' => 'test-token'])
            ->postJson('/scraper/tasks/start', [
                'taskId' => 'manual-site-goettingen',
                'options' => [
                    'job_id' => 'site-goettingen_123',
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('taskId', 'manual-site-goettingen')
            ->assertJsonPath('jobId', 'site-goettingen_123');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://scraper-ui.test/ui/api/crawler/submit'
                && $request['job_id'] === 'site-goettingen_123'
                && $request['url'] === 'https://www.uni-goettingen.de'
                && $request['site_profile_path'] === '/app/profiles/site-goettingen.json'
                && $request['max_pages'] === 25
                && $request['max_concurrency'] === 2
                && $request['max_rpm'] === 30
                && $request['skip_images'] === true
                && $request['sitemap'] === true
                && $request['sitemap_base'] === 'https://www.uni-goettingen.de/sitemap.xml';
        });

        $this->assertDatabaseHas('pipeline_stage_states', [
            'job_id' => 'site-goettingen_123',
            'stage' => 'scrape',
            'status' => 'running',
        ]);

        $stage = PipelineStageState::query()
            ->where('job_id', 'site-goettingen_123')
            ->where('stage', 'scrape')
            ->firstOrFail();

        $this->assertSame('manual-site-goettingen', $stage->metadata['taskId'] ?? null);
        $this->assertSame('scraper-task-ui', $stage->metadata['source'] ?? null);
    }

    private function profileEntry(): array
    {
        return [
            'name' => 'site-goettingen',
            'hostPath' => '/host/profiles/site-goettingen.json',
            'containerPath' => '/app/profiles/site-goettingen.json',
            'match_hosts' => [
                'www.uni-goettingen.de',
                '*.uni-goettingen.de',
            ],
            'profile' => [
                'name' => 'University Goettingen',
                'sitemap' => [
                    'base_url' => 'https://www.uni-goettingen.de/sitemap.xml',
                ],
                'rescrape_failed' => true,
                'max_pages' => 25,
                'max_concurrency' => 2,
                'max_rpm' => 30,
                'skip_images' => true,
                'max_images_per_page' => 12,
                'max_link_density' => 0.2,
                'discovery_mode' => true,
            ],
        ];
    }
}
