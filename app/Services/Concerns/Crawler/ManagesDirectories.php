<?php

namespace App\Services\Concerns\Crawler;

trait ManagesDirectories
{
    /**
     * Get existing directories for a label
     */
    protected function getExistingDirectories(string $outputDir, string $label): array
    {
        $crawlDir = "$outputDir/$label";
        if (!is_dir($crawlDir)) {
            return [];
        }

        return collect(scandir($crawlDir))
            ->filter(fn($item) => !in_array($item, ['.', '..']))  // Filter out . and ..
            ->filter(function ($item) use ($crawlDir) {
                $itemPath = "$crawlDir/$item";
                return is_dir($itemPath) && preg_match('/^\d{5}$/', $item);
            })
            ->map(fn($item) => (int)$item)
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Recursively empty a directory
     */
    private function emptyDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }
}