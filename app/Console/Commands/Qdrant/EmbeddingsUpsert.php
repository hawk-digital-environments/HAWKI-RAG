<?php

/**
 * Orchestrator command: embeds & upserts pages, PDFs, and images into Qdrant. Supports --single-page and robust fallbacks.
 */

namespace App\Console\Commands\Qdrant;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Services\EmbeddingService;
use App\Models\QdrantEmbedding;
use App\Console\Commands\Qdrant\Handlers\PageIndexer;
use App\Console\Commands\Qdrant\Handlers\PdfChunkIndexer;
use App\Console\Commands\Qdrant\Handlers\ImageIndexer;

class EmbeddingsUpsert extends Command
{
    protected $signature = 'embeddings:upsert
        {--from-crawl= : Crawl root (default: CRAWL_DIR or storage/app/private/crawled-data)}
        {--single-page : Treat --from-crawl as one single page folder}
        {--batch=64 : Qdrant upsert batch size}
        {--distance= : Qdrant distance override (Cosine|Dot|Euclid)}
        {--dry-run : Count-only; no embeddings/enrichment/upserts}
        {--enrich : Generate summaries & keywords via app(\'ollama.provider\')}
        {--image-points : Create image points using generated descriptions}
        {--pdf-chunks : Index converted PDF markdown as chunk points (+ doc summary)}
        {--minimal-payload : Store minimal fields}
        {--id-scope=global : Numeric IDs scope (global|job|page)}';

    protected $description = 'Embed + upsert pages, PDF chunks, and images into Qdrant.';
    protected int $nextId = 1;

    public function handle(EmbeddingService $embed): int
    {
        $root        = $this->resolveCrawlRoot($this->option('from-crawl'));
        $singlePage  = (bool)$this->option('single-page');
        $batchSize   = (int)($this->option('batch') ?: 64);
        $distanceOpt = $this->option('distance') ?: null;
        $dryRun      = (bool)$this->option('dry-run');
        $doEnrich    = (bool)$this->option('enrich');
        $imagePoints = (bool)$this->option('image-points');
        $pdfChunks   = (bool)$this->option('pdf-chunks');
        $minimal     = (bool)$this->option('minimal-payload');
        $idScope     = (string)($this->option('id-scope') ?: 'global');

        if (!File::exists($root)) {
            $this->error("Crawl root not found: {$root}");
            return self::FAILURE;
        }

        $this->info("Crawl root: {$root}");
        if ($singlePage) {
            $label = basename($root);
            $this->info("Indexing single page folder: {$label}");
            $stats = $this->newStats();
            $res = $this->indexJob($embed, $root, $label, $batchSize, $distanceOpt, $dryRun, $doEnrich, $imagePoints, $pdfChunks, $minimal, $idScope, true, $stats);
            $this->printJobStats($label, $stats);
            return $res;
        }

        if ($dryRun) $this->warn("DRY-RUN: counting only. No embeddings/enrichment/Qdrant writes.");

        $jobs = collect(File::directories($root))
            ->filter(fn($d) => !Str::startsWith(Str::lower(basename($d)), 'temp'))
            ->values();

        if ($jobs->isEmpty()) {
            $label = basename($root);
            $stats = $this->newStats();
            $res = $this->indexJob($embed, $root, $label, $batchSize, $distanceOpt, $dryRun, $doEnrich, $imagePoints, $pdfChunks, $minimal, $idScope, false, $stats);
            $this->printJobStats($label, $stats);
            return $res;
        }

        $overall = $this->newStats();
        foreach ($jobs as $jobDir) {
            $label = basename($jobDir);
            $stats = $this->newStats();
            $this->info("Indexing job: {$label}");
            $this->indexJob($embed, $jobDir, $label, $batchSize, $distanceOpt, $dryRun, $doEnrich, $imagePoints, $pdfChunks, $minimal, $idScope, false, $stats);
            $this->printJobStats($label, $stats);
            $this->accumulateStats($overall, $stats);
        }
        $this->printOverallStats($overall);
        $this->info('Finished indexing all jobs.');
        return self::SUCCESS;
    }

    protected function indexJob(
        EmbeddingService $embed,
        string $jobDir,
        string $label,
        int $batchSize,
        ?string $distanceOverride,
        bool $dryRun,
        bool $doEnrich,
        bool $imagePoints,
        bool $pdfChunks,
        bool $minimal,
        string $idScope,
        bool $singlePageMode = false,
        array &$stats = null
    ): int {
        $stats ??= $this->newStats();

        $pageDirs = $singlePageMode ? [$jobDir] : $this->pageDirsInJob($jobDir);
        if (empty($pageDirs)) {
            $this->warn("No page directories found under job '{$label}'. Skipping.");
            return self::SUCCESS;
        }

        $this->line("Found " . count($pageDirs) . " page dir(s) in '{$label}'.");

        $collection  = config('model_provider.vector_stores.qdrant.collection', 'embeddings_hawk');
        $distanceCfg = config('model_provider.vector_stores.qdrant.distance', 'Cosine');
        $distance    = $distanceOverride ?: $distanceCfg;

        if ($idScope === 'global') {
            $this->nextId = $dryRun ? 1 : $this->loadNextIdCounter($collection);
        } elseif ($idScope !== 'page') {
            $this->nextId = 1;
        }

        $qdrant          = $dryRun ? null : new QdrantEmbedding();
        $vectorSize      = null;
        $pointsBuffer    = [];
        $upsertedCounter = 0;

        $enricher = (!$dryRun && $doEnrich && app()->bound('ollama.provider')) ? app('ollama.provider') : null;
        if (!$dryRun && $doEnrich && !$enricher) $this->warn("Enrichment requested but no provider bound under 'ollama.provider'. Skipping enrichment.");

        $pageHandler = new PageIndexer($this);
        $pdfHandler  = new PdfChunkIndexer($this);
        $imgHandler  = new ImageIndexer($this);

        foreach ($pageDirs as $dir) {
            if ($idScope === 'page') $this->nextId = 1;

            [$meta, $mdPath, $jsonPath, $text, $sourceFormat] = $this->readPageMaterials($dir);
            if ($text === '') {
                $this->line("skip empty: {$dir}");
                continue;
            }

            $title      = $this->firstStr($meta['title'] ?? null);
            $pageUrl    = $this->firstStr($meta['url'] ?? ($meta['page_url'] ?? null));
            $date       = $this->firstStr($meta['date'] ?? null);
            $metaImgUrl = $this->firstStr($meta['metaImageUrl'] ?? ($meta['meta_img_url'] ?? null));
            $imgs       = $this->toArrayList($meta['images'] ?? []);
            $pdfs       = $this->toArrayList($meta['pdfs'] ?? []);
            $pdfUrl     = $this->firstPdfUrl($pdfs);

            if ($dryRun) {
                $stats['pages']++;
                $stats['points']++;
            } else {
                $pageResult = $pageHandler->buildPagePoint(
                    $embed,
                    $enricher,
                    $distance,
                    $vectorSize,
                    $qdrant,
                    $minimal,
                    $dir,
                    $mdPath,
                    $jsonPath,
                    $text,
                    $sourceFormat,
                    $title,
                    $pageUrl,
                    $date,
                    $metaImgUrl,
                    $imgs,
                    $pdfs,
                    $pdfUrl,
                    $label
                );
                if ($pageResult['ok']) {
                    $vectorSize     = $pageResult['vectorSize'];
                    $pointsBuffer[] = ['id' => $this->nextId++, 'vector' => $pageResult['vector'], 'payload' => $pageResult['payload']];
                    $stats['pages']++;
                    $stats['points']++;
                    if (count($pointsBuffer) >= $batchSize) {
                        $qdrant->upsert($pointsBuffer);
                        $upsertedCounter += count($pointsBuffer);
                        $this->line("Upserted {$upsertedCounter} …");
                        $pointsBuffer = [];
                    }
                }
            }

            if ($pdfChunks) {
                $pdfOut = $pdfHandler->buildPdfPoints(
                    $dryRun,
                    $embed,
                    $enricher,
                    $distance,
                    $vectorSize,
                    $qdrant,
                    $minimal,
                    $dir,
                    $jsonPath,
                    $title,
                    $pageUrl,
                    $date,
                    $metaImgUrl,
                    $pdfUrl,
                    $label
                );
                $stats['pdf_docs']   += $pdfOut['docCount'];
                $stats['pdf_chunks'] += $pdfOut['chunkCount'];
                $stats['points']     += $pdfOut['pointCount'];
                if (!$dryRun && !empty($pdfOut['points'])) {
                    foreach ($pdfOut['points'] as $pt) {
                        $pt['id'] = $this->nextId++;
                        $pointsBuffer[] = $pt;
                    }
                    if (count($pointsBuffer) >= $batchSize) {
                        $qdrant->upsert($pointsBuffer);
                        $upsertedCounter += count($pointsBuffer);
                        $this->line("Upserted {$upsertedCounter} …");
                        $pointsBuffer = [];
                    }
                }
                $vectorSize = $pdfOut['vectorSize'] ?? $vectorSize;
            }

            if ($imagePoints) {
                $imgOut = $imgHandler->buildImagePoints(
                    $dryRun,
                    $embed,
                    $enricher,
                    $distance,
                    $vectorSize,
                    $qdrant,
                    $minimal,
                    $dir,
                    $jsonPath,
                    $text,
                    $title,
                    $pageUrl,
                    $date,
                    $metaImgUrl,
                    $imgs,
                    $label
                );
                $stats['images'] += $imgOut['imageCount'];
                $stats['points'] += $imgOut['pointCount'];
                if (!$dryRun && !empty($imgOut['points'])) {
                    foreach ($imgOut['points'] as $pt) {
                        $pt['id'] = $this->nextId++;
                        $pointsBuffer[] = $pt;
                    }
                    if (count($pointsBuffer) >= $batchSize) {
                        $qdrant->upsert($pointsBuffer);
                        $upsertedCounter += count($pointsBuffer);
                        $this->line("Upserted {$upsertedCounter} …");
                        $pointsBuffer = [];
                    }
                }
                $vectorSize = $imgOut['vectorSize'] ?? $vectorSize;
            }
        }

        if (!$dryRun && !empty($pointsBuffer)) {
            $qdrant->upsert($pointsBuffer);
            $upsertedCounter += count($pointsBuffer);
            $this->line("Upserted {$upsertedCounter} total in '{$label}'.");
        }

        if (!$dryRun && $idScope === 'global') {
            $this->saveNextIdCounter($collection, $this->nextId);
        }

        return self::SUCCESS;
    }

    protected function resolveCrawlRoot(?string $from): string
    {
        return $from !== null
            ? ($from === '' ? base_path(env('CRAWL_DIR', 'storage/app/private/crawled-data')) : $from)
            : base_path(env('CRAWL_DIR', 'storage/app/private/crawled-data'));
    }

    public function readPageMaterials(string $dir): array
    {
        $jsonPath = $this->findFirst($dir, '/\.json$/');
        $mdPath   = $this->findFirst($dir, '/\.(md|txt)$/');
        $meta   = $jsonPath ? json_decode((string) @File::get($jsonPath), true) : [];
        $text   = $mdPath ? (string) @File::get($mdPath) : ((string) ($meta['content'] ?? ''));
        $text   = trim($text);
        $format = $mdPath ? (Str::endsWith($mdPath, '.md') ? 'markdown' : 'txt') : 'txt';
        return [$meta, $mdPath, $jsonPath, $text, $format];
    }

    public function pageDirsInJob(string $jobDir): array
    {
        $subdirs = collect(File::directories($jobDir))->sort()->values()->all();
        $pageDirs = array_values(array_filter($subdirs, fn($d) => $this->dirLooksLikePage($d)));
        if (empty($pageDirs) && $this->dirLooksLikePage($jobDir)) $pageDirs = [$jobDir];
        return $pageDirs;
    }

    public function firstStr($v): ?string
    {
        if (is_array($v)) $v = reset($v);
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    public function toArrayList($v): array
    {
        if (is_string($v)) return [$v];
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map(fn($x) => $this->firstStr($x), $v)));
    }

    public function findFirst(string $dir, string $regex): ?string
    {
        foreach (File::files($dir) as $f) if (preg_match($regex, $f->getFilename())) return $f->getPathname();
        return null;
    }

    public function findMany(string $dir, string $regex): array
    {
        $out = [];
        foreach (File::allFiles($dir) as $f) if (preg_match($regex, $f->getFilename())) $out[] = $f->getPathname();
        return $out;
    }

    public function dirLooksLikePage(string $dir): bool
    {
        foreach (File::files($dir) as $f) {
            $name = $f->getFilename();
            if (preg_match('/\.json$/', $name) || preg_match('/\.(md|txt)$/', $name)) return true;
        }
        return false;
    }

    public function splitMarkdownIntoChunks(string $md, int $targetChars = 3200, int $overlap = 250): array
    {
        $md = trim($md);
        if ($md === '') return [];
        $len = mb_strlen($md);
        if ($len <= $targetChars) return [['text' => $md, 'range' => [0, $len]]];
        $chunks = [];
        $start  = 0;
        while ($start < $len) {
            $end   = min($len, $start + $targetChars);
            $slice = mb_substr($md, $start, $end - $start);
            $cut   = mb_strrpos($slice, "\n\n");
            if ($cut !== false && $cut > $targetChars * 0.6) $end = $start + $cut;
            $chunkText = trim(mb_substr($md, $start, $end - $start));
            if ($chunkText !== '') $chunks[] = ['text' => $chunkText, 'range' => [$start, $end]];
            if ($end >= $len) break;
            $start = max(0, $end - $overlap);
        }
        return $chunks;
    }

    public function bestImageUrl(array $imgUrls, string $fileBase): ?string
    {
        $needle = Str::before($fileBase, '.');
        foreach ($imgUrls as $u) {
            $path = parse_url($u, PHP_URL_PATH) ?? '';
            if (Str::contains(basename($path), $needle)) return $u;
        }
        return null;
    }

    public function firstPdfUrl(?array $pdfs): ?string
    {
        if (empty($pdfs)) return null;
        foreach ($pdfs as $u) {
            if (!is_string($u)) continue;
            $path = parse_url($u, PHP_URL_PATH) ?? '';
            if (Str::endsWith(Str::lower($path), '.pdf')) return $u;
        }
        foreach ($pdfs as $u) if (is_string($u) && $u !== '') return $u;
        return null;
    }

    public function fallbackKeywords(string $text, int $max = 10): array
    {
        $text = mb_strtolower((string)$text);
        $words = preg_split('/[^[:alpha:]\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'you', 'your', 'are', 'was', 'were', 'of', 'to', 'in', 'on', 'at', 'by', 'as', 'is', 'it', 'be', 'or', 'an', 'a', 'we', 'our', 'und', 'der', 'die', 'das', 'mit', 'den', 'von', 'für', 'auf', 'ist', 'sind', 'im', 'an', 'zu', 'eine', 'ein', 'einer', 'einem', 'eines', 'bei', 'dem', 'des', 'auch'];
        $counts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array($w, $stop, true)) continue;
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, $max);
        $seen = [];
        $out = [];
        foreach ($top as $t) if (!isset($seen[$t])) {
            $seen[$t] = true;
            $out[] = $t;
        }
        return $out;
    }

    public function newStats(): array
    {
        return ['pages' => 0, 'pdf_docs' => 0, 'pdf_chunks' => 0, 'images' => 0, 'points' => 0];
    }

    public function accumulateStats(array &$total, array $add): void
    {
        foreach ($total as $k => $v) if (array_key_exists($k, $add)) $total[$k] += (int)$add[$k];
    }

    public function printJobStats(string $label, array $stats): void
    {
        $this->line("");
        $this->info("Job summary: {$label}");
        $this->line(str_repeat('-', 40));
        $this->line("Pages:       " . number_format($stats['pages']));
        $this->line("PDF (docs):  " . number_format($stats['pdf_docs']));
        $this->line("PDF (chunks):" . number_format($stats['pdf_chunks']));
        $this->line("Images:      " . number_format($stats['images']));
        $this->line("-------------------------------");
        $this->line("TOTAL points: " . number_format($stats['points']));
        $this->line("");
    }

    public function printOverallStats(array $stats): void
    {
        $this->line("");
        $this->info("OVERALL SUMMARY");
        $this->line(str_repeat('=', 40));
        $this->line("Pages:       " . number_format($stats['pages']));
        $this->line("PDF (docs):  " . number_format($stats['pdf_docs']));
        $this->line("PDF (chunks):" . number_format($stats['pdf_chunks']));
        $this->line("Images:      " . number_format($stats['images']));
        $this->line("-------------------------------");
        $this->line("TOTAL points: " . number_format($stats['points']));
        $this->line("");
    }

    protected function loadNextIdCounter(string $collection): int
    {
        $f = storage_path('app/qdrant_next_id_' . $collection . '.txt');
        if (!file_exists($f)) return 1;
        $v = (int)trim((string)@file_get_contents($f));
        return max(1, $v);
    }

    protected function saveNextIdCounter(string $collection, int $next): void
    {
        $f = storage_path('app/qdrant_next_id_' . $collection . '.txt');
        @file_put_contents($f, (string)$next);
    }
}
