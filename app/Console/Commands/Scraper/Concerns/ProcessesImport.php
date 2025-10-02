<?php

namespace App\Console\Commands\Scraper\Concerns;

trait ProcessesImport
{
    /**
     * Perform the main import process
     */
    protected function performImport()
    {
        $directory = storage_path('app/private/crawled-data/default');
        
        if (!is_dir($directory)) {
            $this->error("Directory not found: {$directory}");
            return;
        }

        // Get all text and image files
        $allFiles = array_merge(
            glob($directory . '/*/site_*.txt'),
            glob($directory . '/*/images/*.png'),
            glob($directory . '/*/images/*.jpg'),
            glob($directory . '/*/images/*.gif')
        );

        if (blank($allFiles)) {
            $this->info('No files found to process.');
            return;
        }

        $lastIndex = $this->getLastProcessedIndex();
        $filesToProcess = $this->filterNewFiles($allFiles, $lastIndex);
        
        if (blank($filesToProcess)) {
            $this->info('No new files to process.');
            return;
        }

        $this->info("Processing " . count($filesToProcess) . " files...");
        
        $progressBar = $this->output->createProgressBar(count($filesToProcess));
        $progressBar->setFormat('Progress: %current%/%max% [%bar%] %percent:3s%% %memory:6s%');
        $progressBar->start();

        foreach ($filesToProcess as $index => $file) {
            $this->processFile($file, $lastIndex + $index);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        
        $this->deleteProgress();
        $this->info('Import completed successfully!');
    }

    /**
     * Process a single file (text or image)
     */
    protected function processFile($file, $currentIndex)
    {
        $parentDir = dirname($file);
        
        // For image files, the parent directory is one level up
        if (basename($parentDir) === 'images') {
            $parentDir = dirname($parentDir);
        }

        // Get JSON data for this directory
        $jsonFile = $parentDir . '/data_' . basename($parentDir) . '.json';
        
        if (!file_exists($jsonFile)) {
            $jsonFiles = glob($parentDir . '/data_*.json');
            if (empty($jsonFiles)) {
                $this->saveProgress($currentIndex + 1);
                return;
            }
            $jsonFile = $jsonFiles[0];
        }

        $data = json_decode(file_get_contents($jsonFile), true);
        
        if (!$data) {
            $this->saveProgress($currentIndex + 1);
            return;
        }

        $pageURL = $data['page_url'][0] ?? '';
        $title = $data['title'][0] ?? 'No Title';
        $metaImgUrl = $data['meta_img_url'][0] ?? '';
        $date = $data['date'][0] ?? '';
        
        // Process based on file type
        if (pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
            $this->processTextFile($file, 'txt', $pageURL, $metaImgUrl, $title, $date);
        } else {
            $sourceFormat = pathinfo($file, PATHINFO_EXTENSION);
            $this->processImageFile($file, $sourceFormat, $pageURL, $metaImgUrl, $data, $title, $date);
        }

        $this->saveProgress($currentIndex + 1);
    }
}
