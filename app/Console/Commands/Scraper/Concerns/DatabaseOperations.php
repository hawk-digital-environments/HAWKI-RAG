<?php

namespace App\Console\Commands\Scraper\Concerns;

use Illuminate\Support\Facades\Artisan;
use App\Models\Embedding;

trait DatabaseOperations
{
    /**
     * Reset the embeddings table using migrations
     */
    protected function resetEmbeddingsTable()
    {
        $this->info('Resetting embeddings table...');
        
        // Run migration rollback and then migrate to recreate the table
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2024_12_19_120000_create_embeddings_table.php'
        ]);
        
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2024_12_19_120000_create_embeddings_table.php'
        ]);
        
        $this->info('Embeddings table reset successfully.');
    }

    /**
     * Get count of processed directories from database
     */
    protected function getProcessedDirectoryCount()
    {
        return Embedding::distinct('page_url')->count();
    }

    /**
     * Filter files to only include those from directories not yet processed
     */
    protected function filterNewFiles($files, $lastIndex)
    {
        // Get all combinations of page_url + source_format that have been processed
        $processedFiles = Embedding::withoutBinary()
            ->select('page_url', 'source_format')
            ->get()
            ->map(function ($embedding) {
                return $embedding->page_url . '|' . $embedding->source_format;
            })
            ->toArray();
        
        $newFiles = [];
        
        foreach ($files as $index => $file) {
            if ($index < $lastIndex) {
                continue; // Skip files that have been processed according to progress
            }
            
            $parentDir = dirname($file);
            
            // For image files, the parent directory is one level up
            if (basename($parentDir) === 'images') {
                $parentDir = dirname($parentDir);
            }
            
            $jsonFile = $parentDir . '/data_' . basename($parentDir) . '.json';
            
            if (!file_exists($jsonFile)) {
                $jsonFiles = glob($parentDir . '/data_*.json');
                if (empty($jsonFiles)) {
                    continue;
                }
                $jsonFile = $jsonFiles[0];
            }
            
            $data = json_decode(file_get_contents($jsonFile), true);
            
            if (!$data || !isset($data['page_url'][0])) {
                continue;
            }
            
            $pageURL = $data['page_url'][0];
            $sourceFormat = pathinfo($file, PATHINFO_EXTENSION);
            
            // Check if this specific file type for this page URL has been processed
            $fileKey = $pageURL . '|' . $sourceFormat;
            if (!in_array($fileKey, $processedFiles)) {
                $newFiles[] = $file;
            }
        }
        
        return $newFiles;
    }
}
