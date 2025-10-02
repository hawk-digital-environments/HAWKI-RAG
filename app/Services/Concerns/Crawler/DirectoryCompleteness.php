<?php

namespace App\Services\Concerns\Crawler;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

trait DirectoryCompleteness
{
    /**
     * Check if a directory is complete
     */
    protected function isDirectoryComplete(string $dirPath, int $dirNumber): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }

        $formattedId = Str::padLeft($dirNumber, 5, '0');
        
        $requiredFiles = [
            "site_{$formattedId}.txt",
            "data_{$formattedId}.json"
        ];
        
        foreach ($requiredFiles as $file) {
            $filePath = $dirPath . '/' . $file;
            
            if (!File::exists($filePath) || File::size($filePath) === 0) {
                // Only call warn if method exists (Command context)
                if (method_exists($this, 'warn')) {
                    $this->warn("Directory $formattedId is incomplete: missing or empty file '$file'");
                }
                return false;
            }
        }
        
        // Validate JSON file
        $jsonFile = $dirPath . "/data_{$formattedId}.json";
        if (File::exists($jsonFile)) {
            $decoded = rescue(
                fn() => json_decode(File::get($jsonFile), true),
                null,
                report: false
            );
            
            if (blank($decoded)) {
                if (method_exists($this, 'warn')) {
                    $this->warn("Directory $formattedId is incomplete: corrupted JSON file");
                }
                return false;
            }
            
            if (!filled($decoded['title']) || !filled($decoded['page_url'])) {
                if (method_exists($this, 'warn')) {
                    $this->warn("Directory $formattedId is incomplete: JSON missing essential fields");
                }
                return false;
            }
        }
        
        return true;
    }

    /**
     * Scan all directories for completeness analysis
     */
    protected function scanAllDirectoriesForCompleteness(string $outputDir, string $label): array
    {
        $directories = $this->getExistingDirectories($outputDir, $label);
        
        if (blank($directories)) {
            return [
                'complete' => [],
                'incomplete' => [],
                'lastComplete' => 0,
                'incompleteUrls' => []
            ];
        }
        
        [$completeDirectories, $incompleteDirectories] = collect($directories)
            ->partition(function ($dirNumber) use ($outputDir, $label) {
                $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                return $this->isDirectoryComplete($dirPath, $dirNumber);
            });
        
        // Process incomplete directories for URL extraction
        $incompleteUrls = [];
        if (filled($this->urlService)) {
            $incompleteUrls = $incompleteDirectories
                ->mapWithKeys(function ($dirNumber) use ($outputDir, $label) {
                    $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                    $extractedUrl = $this->urlService->extractUrlFromIncompleteDirectory($dirPath, $dirNumber);
                    
                    return filled($extractedUrl) ? [$dirNumber => $extractedUrl] : [];
                })
                ->toArray();
        }
        
        $lastComplete = blank($completeDirectories) ? 0 : $completeDirectories->max();
        
        return [
            'complete' => $completeDirectories->values()->toArray(),
            'incomplete' => $incompleteDirectories->values()->toArray(),
            'lastComplete' => $lastComplete,
            'incompleteUrls' => $incompleteUrls
        ];
    }
}