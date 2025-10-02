<?php

namespace App\Console\Commands\Qdrant;

use Illuminate\Support\Facades\File;
use Carbon\Carbon;

trait QdrantManagesUpdates
{
    /**
     * Check for updates by comparing the JSON date in each directory with a provided
     * "known" date map (you can build it from your SQL legacy or other sources).
     *
     * @param  string              $rootDir  e.g., storage_path('app/private/crawled-data/default')
     * @param  array<string,string> $knownDatesByPageUrl  ['https://...'=> '2025-08-01', ...]
     * @param  string|null         $jobSubfolder If you want to limit to a single job (e.g. 'hawk-full')
     * @return int                 number of directories reprocessed
     */
    protected function checkForUpdatesQdrant(string $rootDir, array $knownDatesByPageUrl, ?string $jobSubfolder = null): int
    {
        $directory = $jobSubfolder
            ? rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobSubfolder
            : $rootDir;

        if (!File::isDirectory($directory)) {
            $this->warn("Directory not found: {$directory}");
            return 0;
        }

        $folders = glob($directory . '/*', GLOB_ONLYDIR);
        if (empty($folders)) {
            return 0;
        }

        $total     = count($folders);
        $processed = 0;

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('Checking: %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        foreach ($folders as $folder) {
            $progressBar->advance();

            $jsonFile = $folder . '/data_' . basename($folder) . '.json';
            if (!file_exists($jsonFile)) {
                $jsonFiles = glob($folder . '/data_*.json');
                if (empty($jsonFiles)) {
                    continue;
                }
                $jsonFile = $jsonFiles[0];
            }

            $data = json_decode(@file_get_contents($jsonFile), true);
            if (!$data) continue;

            $pageUrl = $data['page_url'][0] ?? $data['page_url'] ?? $data['url'] ?? null;
            if (!is_string($pageUrl) || $pageUrl === '') continue;

            $jsonDate      = $data['date'][0] ?? $data['date'] ?? '';
            $knownDateOrig = $knownDatesByPageUrl[$pageUrl] ?? '';

            if ($this->normalizeDateQdrant($jsonDate) !== $this->normalizeDateQdrant($knownDateOrig)) {
                $this->comment("\n🔄 UPDATE detected for {$pageUrl}");
                $this->comment("   Old date: {$knownDateOrig}");
                $this->comment("   New date: {$jsonDate}");

                // Pass full $data so we can reuse images list, meta image, etc.
                $this->reprocessDirectoryQdrant($folder, $data);
                $processed++;
            }
        }

        $progressBar->finish();
        $this->newLine();

        return $processed;
    }

    protected function normalizeDateQdrant($date): string
    {
        if (!$date) return '';
        try {
            return Carbon::parse($date)->toISOString();
        } catch (\Exception $e) {
            return (string)$date;
        }
    }
}
