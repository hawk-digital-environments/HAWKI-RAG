<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScraperTaskUiProxyTest extends TestCase
{
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
