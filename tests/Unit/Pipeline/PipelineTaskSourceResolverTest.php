<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineTaskSourceResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PipelineTaskSourceResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/pipeline-source-resolver'));

        parent::tearDown();
    }

    public function test_it_normalizes_deduplicates_and_falls_back_to_source_url(): void
    {
        $resolver = app(PipelineTaskSourceResolver::class);

        $this->assertSame(
            [
                'https://example.test/a',
                'https://example.test/b',
            ],
            $resolver->resolve([
                'urls' => "example.test/a\nhttps://example.test/b\n example.test/a ",
                'source_url' => 'https://ignored.example/page',
            ]),
        );

        $this->assertSame(
            ['https://single.example/page'],
            $resolver->resolve(['sourceUrl' => 'single.example/page']),
        );
    }

    public function test_it_reads_json_and_xml_sitemap_files(): void
    {
        $resolver = app(PipelineTaskSourceResolver::class);
        $root = storage_path('framework/testing/pipeline-source-resolver');
        File::ensureDirectoryExists($root);

        $jsonPath = "{$root}/sitemap.json";
        File::put($jsonPath, json_encode([
            'pages' => [
                ['url' => 'json.example/a'],
                ['sourceUrl' => 'https://json.example/b'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            [
                'https://json.example/a',
                'https://json.example/b',
            ],
            $resolver->resolve(['sitemap_path' => $jsonPath]),
        );

        $xmlPath = "{$root}/sitemap.xml";
        File::put($xmlPath, '<urlset><url><loc>xml.example/a</loc></url><url><loc>https://xml.example/b</loc></url></urlset>');

        $this->assertSame(
            [
                'https://xml.example/a',
                'https://xml.example/b',
            ],
            $resolver->resolve(['sitemapPath' => $xmlPath]),
        );
    }

    public function test_it_loads_remote_sitemap_only_when_no_urls_were_provided(): void
    {
        Http::fake([
            'https://remote.example/sitemap.xml' => Http::response(
                '<urlset><url><loc>remote.example/a</loc></url></urlset>',
                200,
            ),
        ]);

        $resolver = app(PipelineTaskSourceResolver::class);

        $this->assertSame(
            ['https://remote.example/a'],
            $resolver->resolve(['sitemap_url' => 'https://remote.example/sitemap.xml']),
        );

        $this->assertSame(
            ['https://provided.example/page'],
            $resolver->resolve([
                'urls' => ['provided.example/page'],
                'sitemap_url' => 'https://remote.example/sitemap.xml',
            ]),
        );
    }
}
