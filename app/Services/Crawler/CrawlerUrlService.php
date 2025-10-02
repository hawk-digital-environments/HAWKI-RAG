<?php

namespace App\Services\Crawler;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class CrawlerUrlService
{
    /**
     * Count how many URLs from the list have actually been processed
     */
    public function countProcessedUrls(string $outputDir, string $label, array $allUrls): int
    {
        $dataDir = "$outputDir/$label";
        
        if (!is_dir($dataDir)) {
            return 0;
        }
        
        $processedUrls = collect(scandir($dataDir))
            ->filter(fn($dir) => preg_match('/^\d{5}$/', $dir))
            ->mapWithKeys(function ($dir) use ($dataDir) {
                $jsonFile = "$dataDir/$dir/data_$dir.json";
                
                $data = rescue(
                    fn() => json_decode(File::get($jsonFile), true),
                    [],
                    report: false
                );
                
                return filled($data['page_url'][0] ?? null) 
                    ? [$data['page_url'][0] => true]
                    : [];
            });
        
        // Count how many URLs from our list have been processed
        $processedCount = 0;
        foreach ($allUrls as $url) {
            if ($processedUrls->has($url)) {
                $processedCount++;
            } else {
                // Once we hit an unprocessed URL, we know where to continue
                break;
            }
        }
        
        return $processedCount;
    }

    /**
     * Extract URL from an incomplete directory
     */
    public function extractUrlFromIncompleteDirectory(string $dirPath, int $dirNumber): ?string
    {
        return rescue(function () use ($dirPath, $dirNumber) {
            $formattedId = Str::padLeft($dirNumber, 5, '0');
            $jsonFile = $dirPath . "/data_{$formattedId}.json";
            
            if (File::exists($jsonFile) && File::size($jsonFile) > 0) {
                $decoded = json_decode(File::get($jsonFile), true);
                
                if (filled($decoded['page_url']) && is_array($decoded['page_url'])) {
                    return Arr::first($decoded['page_url']);
                }
            }
            
            return null;
        }, null, report: false);
    }
} 