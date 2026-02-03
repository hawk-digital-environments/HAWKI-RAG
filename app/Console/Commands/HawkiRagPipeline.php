<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class HawkiRagPipeline extends Command
{
    protected $signature = 'hawki_rag:pipeline
        {url : The starting URL to crawl}
        {--max-pages=100 : Maximum number of pages to crawl}
        {--output-dir= : Directory to store crawled data (defaults to shared root + label)}
        {--label= : Label for this crawl job}
        {--collection= : Qdrant collection name (defaults to output folder name)}
        {--skip-images : Skip downloading images}
        {--image-exceptions= : Comma-separated substrings to ignore for images}
        {--date= : CSS selector for updated_time, e.g. meta[property=\'og:updated_time\']}
        {--provider=ollama : Embedding provider}
        {--graph : Enable Neo4j triplet extraction}
        {--graph-engine=raganything : Graph engine}
        {--distance=Cosine : Qdrant distance (Cosine|Dot|Euclid)}
        {--chunk-chars=3200 : Chunk size in characters}
        {--chunk-overlap=100 : Chunk overlap}
        {--batch=64 : Docs per request}
        {--timeout=1800 : HTTP timeout seconds for ingest}
        {--base-url= : HAWKI RAG bridge base URL (default: HAWKI_RAG_BRIDGE_URL)}';

    protected $description = 'HAWKI RAG pipeline: crawl -> convert PDFs -> ingest to Qdrant/Neo4j.';

    public function handle(): int
    {
        $url = (string) $this->argument('url');
        $label = (string) $this->option('label');
        if ($label === '') {
            $label = $this->slugify(parse_url($url, PHP_URL_HOST) ?: 'crawl');
        }

        $sharedRoot = (string) config('hawki_rag.shared_root', storage_path('app/public'));
        $outputDir = (string) ($this->option('output-dir') ?: rtrim($sharedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $label);
        $collection = (string) ($this->option('collection') ?: basename($outputDir));

        $this->info('=== Step 1/3: Crawl + Convert ===');
        $crawlArgs = array_filter([
            'url' => $url,
            '--max-pages' => (string) $this->option('max-pages'),
            '--output-dir' => $outputDir,
            '--label' => $label,
            '--image-exceptions' => $this->option('image-exceptions'),
            '--date' => $this->option('date'),
            ($this->option('skip-images') ? '--skip-images' : null) => $this->option('skip-images') ? true : null,
        ], static fn ($v) => $v !== null);

        $crawlCode = $this->call('crawl:and-convert', $crawlArgs);
        if ($crawlCode !== 0) {
            $this->error("Crawler/converter exited with code {$crawlCode}.");
            return $crawlCode;
        }

        $this->info('=== Step 2/3: Ingest ===');
        $script = base_path('python_rag/ingest/ingest_crawled.py');
        if (!is_file($script)) {
            $this->error('ingest_crawled.py not found');
            return 1;
        }

        $baseUrl = (string) ($this->option('base-url') ?: env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000'));
        $statusPath = (string) config('hawki_rag.ingest_status_path', storage_path('logs/ingest_status.json'));
        $logPath = (string) config('hawki_rag.ingest_log_path', storage_path('logs/ingest_progress.log'));
        File::ensureDirectoryExists(dirname($statusPath));
        File::ensureDirectoryExists(dirname($logPath));

        $cmd = [
            'python3',
            $script,
            '--root', $outputDir,
            '--base-url', $baseUrl,
            '--provider', (string) $this->option('provider'),
            '--graph-engine', (string) $this->option('graph-engine'),
            '--collection', $collection,
            '--distance', (string) $this->option('distance'),
            '--chunk-chars', (string) $this->option('chunk-chars'),
            '--chunk-overlap', (string) $this->option('chunk-overlap'),
            '--batch', (string) $this->option('batch'),
            '--timeout', (string) $this->option('timeout'),
        ];
        if ($this->option('graph')) {
            $cmd[] = '--graph';
        }

        $status = [
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'running',
            'progress' => null,
            'last_line' => null,
            'summary_path' => storage_path('logs/ingest_summary.json'),
            'command' => $cmd,
            'path' => $outputDir,
        ];
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::append($logPath, 'PIPELINE_INGEST_STARTED ' . $outputDir . PHP_EOL);

        $process = new Process($cmd, base_path());
        $process->setTimeout(null);
        $process->start();

        $status['pid'] = $process->getPid();
        $status['updated_at'] = now()->toIso8601String();
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process->wait(function ($type, $buffer) use (&$status, $statusPath, $logPath) {
            $lines = preg_split("/\\r?\\n/", $buffer, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                File::append($logPath, $line . PHP_EOL);
                $status['last_line'] = $line;
                $progress = $this->extractProgress($line);
                if ($progress) {
                    $status['progress'] = $progress;
                }
                $status['updated_at'] = now()->toIso8601String();
                File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        });

        if (!$process->isSuccessful()) {
            $status['status'] = 'failed';
            $status['exit_code'] = $process->getExitCode();
            $status['updated_at'] = now()->toIso8601String();
            File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->error('Ingest failed.');
            return (int) ($process->getExitCode() ?? 1);
        }

        $status['status'] = 'completed';
        $status['updated_at'] = now()->toIso8601String();
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('=== Step 3/3: Done ✅ ===');
        return 0;
    }

    private function extractProgress(string $line): ?array
    {
        if (preg_match('/Sent\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
            return ['sent' => (int) $m[1], 'total' => (int) $m[2]];
        }
        if (preg_match('/Planned\\s+(\\d+)[\\/](\\d+)\\s+docs/i', $line, $m)) {
            return ['sent' => (int) $m[1], 'total' => (int) $m[2], 'mode' => 'dry'];
        }
        if (preg_match('/Found\\s+(\\d+)\\s+PDF/i', $line, $m)) {
            return ['found_pdfs' => (int) $m[1]];
        }
        return null;
    }

    private function slugify(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = trim($value, '-');
        return $value !== '' ? $value : 'crawl';
    }
}
