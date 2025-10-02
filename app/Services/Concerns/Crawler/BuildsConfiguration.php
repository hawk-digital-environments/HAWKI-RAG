<?php

namespace App\Services\Concerns\Crawler;

trait BuildsConfiguration
{
    /**
     * Build crawler configuration array
     */
    protected function buildCrawlerConfig(string $url, bool $isLocalFile, ?string $baseUrl, string $outputDir, string $label, int $startFromIndex, bool $shouldContinue, string $sourceType): array
    {
        $config = [
            'url' => $isLocalFile ? $baseUrl : $url,
            'maxPages' => method_exists($this, 'option') ? (int) $this->option('max-pages') : 100,
            'outputDir' => $outputDir,
            'label' => $label,
            'skipImages' => method_exists($this, 'option') ? $this->option('skip-images') : false,
            'startFromIndex' => $startFromIndex,
            'incompleteDirectories' => $shouldContinue ? $this->scanAllDirectoriesForCompleteness($outputDir, $label)['incompleteUrls'] : [],
            'emptyDirectoriesToReuse' => $shouldContinue ? array_diff($this->scanAllDirectoriesForCompleteness($outputDir, $label)['incomplete'], array_keys($this->scanAllDirectoriesForCompleteness($outputDir, $label)['incompleteUrls'])) : [],
            'sourceType' => $sourceType
        ];
        
        // Add image exceptions if provided
        if (method_exists($this, 'option') && $this->option('image-exceptions')) {
            $exceptions = collect(explode(',', $this->option('image-exceptions')))
                ->map(fn($item) => trim($item))
                ->filter(fn($item) => filled($item))
                ->values()
                ->toArray();
            
            if (method_exists($this, 'info')) {
                $this->info("Using image exceptions: " . implode(', ', $exceptions));
            }
            $config['imageExceptions'] = $exceptions;
        }
        
        // Add date selector if provided
        if (method_exists($this, 'option') && filled($this->option('date'))) {
            $config['dateSelector'] = $this->option('date');
            if (method_exists($this, 'info')) {
                $this->info("Using date selector: " . $config['dateSelector']);
            }
        }
        
        return $config;
    }

    /**
     * Handle URL continuation logic for different source types
     */
    protected function handleUrlContinuation(array $config, bool $shouldContinue, int $startFromIndex, string $sourceType, array $sitemapUrls, string $outputDir, string $label): array
    {
        if ($shouldContinue && $startFromIndex > 1) {
            $existingDirs = $this->getExistingDirectories($outputDir, $label);
            $continueOffset = isset($this->progressService) ? 
                $this->progressService->calculateContinueOffset($outputDir, $label, $sourceType, $sitemapUrls, $existingDirs) : 0;
            
            if ($sourceType === 'local') {
                $cleanUrls = array_slice($sitemapUrls, $continueOffset);
                if (method_exists($this, 'info')) {
                    $this->info("Skipping first $continueOffset URLs (already processed)");
                }
                
                if ($config['maxPages'] > 0) {
                    $cleanUrls = array_slice($cleanUrls, 0, (int)$config['maxPages']);
                }
                
                $config['urls'] = $cleanUrls;
                $config['isLocalFile'] = true;
            } else {
                $config['continueOffset'] = $continueOffset;
            }
        } elseif ($sourceType === 'local') {
            $cleanUrls = $sitemapUrls;
            
            if ($config['maxPages'] > 0) {
                $cleanUrls = array_slice($cleanUrls, 0, (int)$config['maxPages']);
            }
            
            $config['urls'] = $cleanUrls;
            $config['isLocalFile'] = true;
        }
        
        return $config;
    }
} 