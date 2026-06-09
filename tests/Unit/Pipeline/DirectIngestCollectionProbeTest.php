<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\DirectIngest\DirectIngestCollectionProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectIngestCollectionProbeTest extends TestCase
{
    public function test_it_detects_existing_qdrant_collection(): void
    {
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'qdrant.test/collections' => Http::response([
                'result' => [
                    'collections' => [
                        ['name' => 'hawki_docs'],
                    ],
                ],
            ]),
        ]);

        $this->assertTrue(app(DirectIngestCollectionProbe::class)->exists('hawki_docs'));
        $this->assertFalse(app(DirectIngestCollectionProbe::class)->exists('missing'));
    }

    public function test_it_returns_false_for_blank_or_unavailable_collection_probe(): void
    {
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'qdrant.test/collections' => Http::response([], 500),
        ]);

        $this->assertFalse(app(DirectIngestCollectionProbe::class)->exists(''));
        $this->assertFalse(app(DirectIngestCollectionProbe::class)->exists('hawki_docs'));
    }
}
