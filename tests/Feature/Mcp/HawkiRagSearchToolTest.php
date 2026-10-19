<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\HawkiRagSearchTool;
use App\Models\User;
use App\Services\RagSearch\RagSearcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(HawkiRagSearchTool::class)]
class HawkiRagSearchToolTest extends TestCase
{
    use RefreshDatabase;

    public function testDerivesDistinctDocumentsFromTheHitMetadata(): void
    {
        $user = User::create(['username' => 'mcp-user', 'email' => 'mcp@example.test', 'ip' => '127.0.0.1']);
        $this->actingAs($user);

        $searcher = $this->createMock(RagSearcher::class);
        $searcher->method('withQuery')->willReturnSelf();
        $searcher->method('withTopK')->willReturnSelf();
        $searcher->method('forDataset')->willReturnSelf();
        $searcher->method('execute')->willReturn([
            'results' => [
                // Direct-text ingestion: the caller-owned document id
                // round-trips per chunk next to the display title.
                [
                    'metadata' => ['title' => 'Article.pdf', 'external_document_id' => 'attach-uuid-1'],
                    'content' => 'text-mode chunk',
                ],
                [
                    'metadata' => ['title' => 'Article.pdf', 'external_document_id' => 'attach-uuid-1'],
                    'content' => 'same document, another chunk',
                ],
                // Managed document ingestion.
                [
                    'metadata' => ['title' => 'Lecture.pdf', 'document_id' => 'adoc_test1'],
                    'content' => 'file-mode chunk',
                ],
                // Web-style hit: no document identity at all.
                [
                    'metadata' => ['title' => 'Web page', 'url' => 'https://example.com/page'],
                    'content' => 'web chunk',
                ],
            ],
            'kg' => [],
            'rewrite_terms' => [],
        ]);

        $request = new Request(['query' => 'how do students learn', 'dataset_id' => 'assistant_12']);
        $this->app->instance(Request::class, $request);

        $tool = new HawkiRagSearchTool($searcher);

        /** @var ResponseFactory $result */
        $result = $this->app->call([$tool, 'handle']);

        $structured = $result->mergeStructuredContent([])['structuredContent'];

        static::assertSame([
            ['kind' => 'attachments', 'document_id' => 'attach-uuid-1', 'name' => 'Article.pdf'],
            ['kind' => 'documents', 'document_id' => 'adoc_test1', 'name' => 'Lecture.pdf'],
        ], $structured['documents'], 'one entry per distinct document, none for identity-less hits');

        $firstHit = $structured['response']['results'][0];
        static::assertSame('attach-uuid-1', $firstHit['metadata']['external_document_id']);
        static::assertSame('Article.pdf', $firstHit['metadata']['title']);
    }
}
