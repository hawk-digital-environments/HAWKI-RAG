<?php
//
//namespace App\Console\Commands\Crawler;
//
//use App\Models\ScrapedPage;
//use App\Services\ScrapeService\Storage\CrawlerStorageManager;
//use Illuminate\Console\Command;
//
//class CrawleeCleanup extends Command
//{
//    protected $signature = 'crawlee:cleanup
//                            {label : The label of the crawl data to remove}
//                            {--force : Skip confirmation prompt}';
//
//    protected $description = 'Remove scraped data from storage and database by label';
//
//    public function __construct(
//        private CrawlerStorageManager $storage
//    ) {
//        parent::__construct();
//    }
//
//    /**
//     * Execute the crawlee:cleanup command.
//     */
//    public function handle(): int
//    {
//        $label = $this->argument('label');
//        $force = $this->option('force');
//
//        if (blank($label)) {
//            $this->error('Label is required');
//            return self::FAILURE;
//        }
//
//        try {
//            // Check if data exists
//            $hasStorage = $this->storage->isDirectory($label);
//            $pageCount = ScrapedPage::where('crawler_label', $label)->count();
//            $hasDatabase = $pageCount > 0;
//
//            // Check for progress file
//            $progressFile = $this->storage->progressFilePath($label);
//            $hasProgressFile = $this->storage->exists($progressFile);
//
//            // Check for temp directories
//            $tempDirs = $this->storage->getTempDirectories();
//            $hasTempDirs = count($tempDirs) > 0;
//
//            if (!$hasStorage && !$hasDatabase && !$hasProgressFile && !$hasTempDirs) {
//                $this->warn("No data found for label: {$label}");
//                return self::SUCCESS;
//            }
//
//            // Display what will be removed
//            $this->info("Found data for label: {$label}");
//            if ($hasStorage) {
//                $this->info("  Storage directory: {$label}");
//            }
//            if ($hasDatabase) {
//                $this->info("  Database records: {$pageCount} pages");
//            }
//            if ($hasProgressFile) {
//                $this->info("  Progress file: {$progressFile}");
//            }
//            if ($hasTempDirs) {
//                $this->warn("  Temp directories: " . count($tempDirs) . " found (may be from incomplete scrapes)");
//                foreach ($tempDirs as $tempDir) {
//                    $this->warn("    - " . basename($tempDir));
//                }
//            }
//
//            // Confirm deletion
//            if (!$force) {
//                $confirm = $this->confirm('Are you sure you want to delete this data? This action cannot be undone.');
//                if (!$confirm) {
//                    $this->info('Cleanup cancelled.');
//                    return self::SUCCESS;
//                }
//            }
//
//            // Remove storage directory
//            if ($hasStorage) {
//                $this->info('Removing storage directory...');
//                $this->storage->deleteDirectory($label);
//                $this->info('Storage directory removed.');
//            }
//
//            // Remove database records (soft delete)
//            if ($hasDatabase) {
//                $this->info('Removing database records...');
//                $deleted = ScrapedPage::where('crawler_label', $label)->delete();
//                $this->info("Removed {$deleted} database records.");
//            }
//
//            // Remove progress file
//            if ($hasProgressFile) {
//                $this->info('Removing progress file...');
//                $this->storage->delete($progressFile);
//                $this->info('Progress file removed.');
//            }
//
//            // Remove temp directories
//            if ($hasTempDirs) {
//                $this->info('Removing temp directories...');
//                foreach ($tempDirs as $tempDir) {
//                    $this->storage->deleteDirectory(basename($tempDir));
//                    $this->info('  Removed: ' . basename($tempDir));
//                }
//            }
//
//            $this->newLine();
//            $this->info("Successfully cleaned up data for label: {$label}");
//
//            return self::SUCCESS;
//
//        } catch (\Throwable $e) {
//            $this->error('An error occurred: ' . $e->getMessage());
//            return self::FAILURE;
//        }
//    }
//}
