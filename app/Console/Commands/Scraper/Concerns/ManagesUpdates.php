<?php

namespace App\Console\Commands\Scraper\Concerns;

use App\Models\Embedding;
use Carbon\Carbon;

trait ManagesUpdates
{
    /**
     * Check for updates in existing embeddings by comparing dates
     */
    protected function checkForUpdates()
    {
        $directory = storage_path('app/private/crawled-data/default');
        $folders = glob($directory . '/*', GLOB_ONLYDIR);
        
        if (empty($folders)) {
            return 0;
        }
        
        // Get all existing embeddings with their page URLs and dates
        $existingEmbeddings = Embedding::withoutBinary()
            ->whereNotNull('page_url')
            ->where('source_format', 'txt') // Only check text embeddings as main reference
            ->get()
            ->keyBy('page_url');
        
        if ($existingEmbeddings->isEmpty()) {
            return 0;
        }
        
        $updatesProcessed = 0;
        $progressBar = $this->output->createProgressBar($existingEmbeddings->count());
        $progressBar->setFormat('Checking: %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();
        
        foreach ($existingEmbeddings as $pageUrl => $embedding) {
            $progressBar->advance();
            
            // Find the corresponding directory and JSON file
            $directoryPath = $this->findDirectoryByPageUrl($folders, $pageUrl);
            
            if (!$directoryPath) {
                continue; // Skip if directory not found
            }
            
            $jsonFile = $directoryPath . '/data_' . basename($directoryPath) . '.json';
            
            if (!file_exists($jsonFile)) {
                // Try to find any data_*.json file in the directory
                $jsonFiles = glob($directoryPath . '/data_*.json');
                if (empty($jsonFiles)) {
                    continue;
                }
                $jsonFile = $jsonFiles[0];
            }
            
            $data = json_decode(file_get_contents($jsonFile), true);
            
            if (!$data) {
                continue;
            }
            
            $jsonDate = $data['date'][0] ?? '';
            $embeddingDate = $embedding->date ?? '';
            
            // Compare dates - if they differ, reprocess the directory
            if ($this->normalizeDate($jsonDate) !== $this->normalizeDate($embeddingDate)) {
                $this->comment("\n🔄 UPDATE detected for {$pageUrl}");
                $this->comment("   Old date: {$embeddingDate}");
                $this->comment("   New date: {$jsonDate}");
                
                $this->reprocessDirectory($directoryPath, $data, $embedding->id);
                $updatesProcessed++;
            }
        }
        
        $progressBar->finish();
        $this->newLine();
        
        return $updatesProcessed;
    }

    /**
     * Normalize date for comparison
     */
    protected function normalizeDate($date)
    {
        if (!$date) {
            return '';
        }
        
        try {
            return Carbon::parse($date)->toISOString();
        } catch (\Exception $e) {
            return $date; // Return as-is if parsing fails
        }
    }
}
