<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\RagSearch\HawkiKnowledgeBaseSearch;
use App\Services\RagSearch\RagSearchSchemaFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Psr\Log\LoggerInterface;

#[Title('HAWKI Knowledge Base')]
#[Name('hawki-knowldgeBase')]
#[Description('Search the HAWK university knowledge and project archives. Every call searches only FULL_EMB_HAWK and FULL_PROJ_HAWK. Cite the returned source titles and metadata URLs.')]
class HawkiKnowledgeBaseTool extends Tool
{
    public function __construct(
        private readonly HawkiKnowledgeBaseSearch $search,
        private readonly RagSearchSchemaFactory $schemas,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->max(6000)
                ->description('A precise question about HAWK university or its projects.')
                ->required(),
            'top_k' => $schema->integer()->min(1)->max(50)
                ->description('Maximum chunks per collection; defaults to 5, returning at most 10 chunks across both collections.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->schemas->make($schema),
            'collections' => $schema->array()->items($schema->string())
                ->description('The two collections searched.')
                ->required(),
        ];
    }

    public function handle(Request $request, LoggerInterface $log): ResponseFactory|Response
    {
        $validated = $request->validate([
            'query' => 'required|string|max:6000',
            'top_k' => 'sometimes|integer|min:1|max:50',
            'dataset_id' => 'prohibited',
            'datasetId' => 'prohibited',
            'collection' => 'prohibited',
            'collections' => 'prohibited',
            'qdrant_collection' => 'prohibited',
            'authorized_scope' => 'prohibited',
            'filters' => 'prohibited',
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication is required to search the HAWKI knowledge base.');
        }

        try {
            return Response::structured($this->search->search(
                $user,
                $validated['query'],
                (int) ($validated['top_k'] ?? 5),
            ));
        } catch (\Throwable $exception) {
            $log->error('HAWKI knowledge base search failed.', ['exception' => $exception]);

            return Response::error('The HAWKI knowledge base could not be searched. Both datasets must be available and authorized.');
        }
    }
}
