<?php

declare(strict_types=1);

namespace Tests\Feature\Scraping;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScraperTaskUiProxyTest extends TestCase
{
    public function test_embedded_ui_cannot_bypass_python_execution_authority(): void
    {
        config()->set('config.crawler_ingestion_backend', 'python');
        Http::fake();
        $this->post('/ui/api/crawler/submit', ['url' => 'https://example.test'])->assertStatus(409);
        $this->post('/ui/api/tasks/one/start')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_task_ui_new_page_is_proxied_from_configured_custom_crawler_ui(): void
    {
        config()->set('scraper.task_ui_url', 'http://scraper-ui.test');

        Http::fake([
            'http://scraper-ui.test/ui/tasks/new' => Http::response('<!doctype html><title>New task</title>', 200, [
                'content-type' => 'text/html; charset=utf-8',
            ]),
        ]);

        $this->get('/ui/tasks/new')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8')
            ->assertSee('New task', false);
    }
}
