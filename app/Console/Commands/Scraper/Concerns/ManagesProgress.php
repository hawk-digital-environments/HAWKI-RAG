<?php

namespace App\Console\Commands\Scraper\Concerns;

trait ManagesProgress
{
    /**
     * Progress file name
     */
    protected $progressFile = 'import_scraping_progress.txt';

    /**
     * Get the full path to the progress file
     */
    protected function getProgressPath()
    {
        $directory = storage_path('app/private/import');
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        return $directory . '/' . $this->progressFile;
    }

    /**
     * Save the current progress index
     */
    protected function saveProgress($index)
    {
        file_put_contents($this->getProgressPath(), $index);
    }

    /**
     * Get the last processed file index
     */
    protected function getLastProcessedIndex()
    {
        if (file_exists($this->getProgressPath())) {
            return (int)file_get_contents($this->getProgressPath());
        }
        return 0;
    }

    /**
     * Delete the progress file
     */
    protected function deleteProgress()
    {
        if (file_exists($this->getProgressPath())) {
            unlink($this->getProgressPath());
        }
    }
}
