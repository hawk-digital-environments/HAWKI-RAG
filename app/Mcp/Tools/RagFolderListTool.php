<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class RagFolderListTool extends Tool
{
    public function description(): string
    {
        return 'List crawl folders under the shared root so you can pick one to ingest.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('root')->description('Shared root to scan (default: RAWKI_SHARED_ROOT)')
            ->boolean('include_hidden')->description('Include hidden directories (default: false)');
    }

    public function handle(array $arguments): ToolResult
    {
        $root = (string) ($arguments['root'] ?? config('rawki.shared_root', '/app/shared'));
        $includeHidden = (bool) ($arguments['include_hidden'] ?? false);

        if (!is_dir($root)) {
            return ToolResult::error("Shared root not found: {$root}");
        }

        $dirs = File::directories($root);
        $items = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (!$includeHidden && str_starts_with($name, '.')) {
                continue;
            }
            if (preg_match('/^sitemaps?$/i', $name)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'path' => $dir,
            ];
        }

        usort($items, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ToolResult::json([
            'root' => $root,
            'count' => count($items),
            'folders' => $items,
        ]);
    }
}
