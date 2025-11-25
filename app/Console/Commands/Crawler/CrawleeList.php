<?php
//
//namespace App\Console\Commands\Crawler;
//
//use App\Models\ScrapedPage;
//use App\Services\ScrapeService\Storage\CrawlerStorageManager;
//use Illuminate\Console\Command;
//use Carbon\Carbon;
//
//class CrawleeList extends Command
//{
//    protected $signature = 'crawlee:list';
//
//    protected $description = 'List all available crawl labels with statistics';
//
//    public function __construct(
//        private CrawlerStorageManager $storage
//    ) {
//        parent::__construct();
//    }
//
//    /**
//     * Execute the crawlee:list command.
//     */
//    public function handle(): int
//    {
//        try {
//            $this->info('Crawl Data Summary');
//            $this->newLine();
//
//            // Get labels from database
//            $dbLabels = ScrapedPage::selectRaw('crawler_label, COUNT(*) as page_count, MIN(crawled_at) as first_crawled, MAX(crawled_at) as last_crawled')
//                ->whereNotNull('crawler_label')
//                ->groupBy('crawler_label')
//                ->orderBy('last_crawled', 'desc')
//                ->get();
//
//            // Get labels from filesystem
//            $fsLabels = [];
//            $directories = $this->storage->directories();
//
//            foreach ($directories as $dir) {
//                $label = basename($dir);
//                $fsLabels[$label] = [
//                    'path' => $dir,
//                    'size' => $this->storage->directorySize($dir),
//                    'modified' => $this->storage->lastModified($dir),
//                ];
//            }
//
//            // Combine and display data
//            $allLabels = collect($dbLabels->pluck('crawler_label'))
//                ->merge(array_keys($fsLabels))
//                ->unique()
//                ->sort()
//                ->values();
//
//            if ($allLabels->isEmpty()) {
//                $this->warn('No crawl data found.');
//                return self::SUCCESS;
//            }
//
//            // Build table data
//            $tableData = [];
//            foreach ($allLabels as $label) {
//                $dbRecord = $dbLabels->firstWhere('crawler_label', $label);
//                $fsRecord = $fsLabels[$label] ?? null;
//
//                $row = [
//                    'label' => $label,
//                    'pages' => $dbRecord ? $dbRecord->page_count : '0',
//                    'storage' => $fsRecord ? $this->formatBytes($fsRecord['size']) : 'N/A',
//                    'last_updated' => $dbRecord
//                        ? Carbon::parse($dbRecord->last_crawled)->diffForHumans()
//                        : ($fsRecord ? date('Y-m-d H:i', $fsRecord['modified']) : 'N/A'),
//                ];
//
//                $tableData[] = $row;
//            }
//
//            // Display table
//            $this->table(
//                ['Label', 'Pages', 'Storage Size', 'Last Updated'],
//                array_map(fn($row) => [
//                    $row['label'],
//                    $row['pages'],
//                    $row['storage'],
//                    $row['last_updated'],
//                ], $tableData)
//            );
//
//            $this->newLine();
//            $this->info('Total labels: ' . count($tableData));
//            $this->info('Total pages: ' . $dbLabels->sum('page_count'));
//            $this->info('Storage disk: ' . $this->storage->diskName());
//
//            return self::SUCCESS;
//
//        } catch (\Throwable $e) {
//            $this->error('An error occurred: ' . $e->getMessage());
//            return self::FAILURE;
//        }
//    }
//
//    /**
//     * Format bytes to human-readable format.
//     */
//    private function formatBytes(int $bytes, int $precision = 2): string
//    {
//        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
//
//        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
//            $bytes /= 1024;
//        }
//
//        return round($bytes, $precision) . ' ' . $units[$i];
//    }
//}
