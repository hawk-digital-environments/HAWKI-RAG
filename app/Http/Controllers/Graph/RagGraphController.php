<?php

namespace App\Http\Controllers\Graph;

use App\Http\Controllers\Controller;
use App\Services\GraphService\Neo4jAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class RagGraphController extends Controller
{
    public function clearNeo4j(Neo4jAdmin $neo4j): JsonResponse
    {
        $result = $neo4j->clearAll();
        $status = ($result['ok'] ?? false) ? 200 : 502;
        if (($result['ok'] ?? false)) {
            $this->writeGraphSnapshot($this->emptyGraphSnapshot());
        }

        return response()->json($result, $status);
    }

    private function writeGraphSnapshot(array $snapshot): void
    {
        $path = public_path('neo4j_graph_visualization.json');
        $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (File::exists($path) && ! is_writable($path)) {
            File::delete($path);
        }

        File::put($path, $payload);
        @chmod($path, 0666);
    }

    private function emptyGraphSnapshot(): array
    {
        return [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'limit' => 250,
            'node_count' => 0,
            'relationship_count' => 0,
            'document_count' => 0,
            'nodes' => [],
            'links' => [],
        ];
    }
}
