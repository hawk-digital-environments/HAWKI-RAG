<?php

/**
 * Builds PDF doc-summary point and chunk points with trimmed inputs, retries, dedup, and clean tags.
 */

namespace App\Console\Commands\Qdrant\Handlers;

use App\Services\EmbeddingService;
use App\Models\QdrantEmbedding;
use Illuminate\Support\Str;

class PdfChunkIndexer
{
    protected $cmd;
    public function __construct($command)
    {
        $this->cmd = $command;
    }

    public function buildPdfPoints(
        bool $dryRun,
        EmbeddingService $embed,
        $enricher,
        string $distance,
        ?int $vectorSize,
        ?QdrantEmbedding $qdrant,
        bool $minimal,
        string $dir,
        ?string $jsonPath,
        ?string $title,
        ?string $pageUrl,
        ?string $date,
        ?string $metaImgUrl,
        ?string $pdfUrl,
        string $label
    ): array {
        $points = [];
        $docCount = 0;
        $chunkCount = 0;
        $pointCount = 0;

        $pdfMdFiles = $this->cmd->findMany($dir, '/content_markdown\.md$/');
        if (empty($pdfMdFiles)) {
            return ['points' => [], 'docCount' => 0, 'chunkCount' => 0, 'pointCount' => 0, 'vectorSize' => $vectorSize];
        }

        foreach ($pdfMdFiles as $pdfMdPath) {
            $docMd = trim((string) @file_get_contents($pdfMdPath));
            if ($docMd === '') continue;

            $sumInput = $this->capForEnrichment($docMd, 3500);
            $docSummary = '';
            if ($enricher) {
                $docSummary = $this->tryEnrich(function () use ($enricher, $sumInput) {
                    if (method_exists($enricher, 'generatePdfSummary')) return (string)$enricher->generatePdfSummary($sumInput);
                    return (string)$enricher->generateAdditionalContext($sumInput);
                });
            }
            if ($docSummary === '' || mb_strtolower($docSummary) === 'undefined') {
                $docSummary = $this->localParagraphSummary($docMd, 120);
            }

            $tags = [];
            if ($enricher) {
                $tags = $this->coerceKeywords($this->tryEnrich(function () use ($enricher, $docSummary, $docMd) {
                    if (method_exists($enricher, 'generateKeywords')) return $enricher->generateKeywords($docSummary ?: $docMd);
                    return $enricher->generateKeywords($docSummary ?: $docMd);
                }));
            }
            if (empty($tags)) $tags = $this->fallbackKeywords($docSummary ?: $docMd);

            if ($dryRun) {
                $docCount += 1;
                $pointCount += 1;
            } else {
                $payloadDoc = $minimal ? [
                    'title'                   => $this->cleanTitle($title),
                    'content'                 => null,
                    'page_url'                => $pageUrl,
                    'source_url'              => $pageUrl,
                    'pdf_url'                 => $pdfUrl,
                    'source_format'           => 'pdf',
                    'date'                    => $date,
                    'tags'                    => $tags,
                    'intermediate_formatting' => $docSummary,
                    'kind'                    => 'pdf_doc',
                    'label'                   => $label,
                    'parent_id'               => basename($dir),
                ] : [
                    'title'                   => $this->cleanTitle($title),
                    'content'                 => null,
                    'meta_img_url'            => $metaImgUrl,
                    'page_url'                => $pageUrl,
                    'source_url'              => $pageUrl,
                    'pdf_url'                 => $pdfUrl,
                    'source_format'           => 'pdf',
                    'date'                    => $date,
                    'tags'                    => $tags,
                    'intermediate_formatting' => $docSummary,
                    'dir'                     => $dir,
                    'pdf_path'                => $pdfMdPath,
                    'json_path'               => $jsonPath,
                    'hash'                    => sha1($pdfMdPath),
                    'collection'              => config('model_provider.vector_stores.qdrant.collection', 'embeddings_hawk'),
                    'source'                  => 'crawl',
                    'chunk_index'             => -1,
                    'parent_id'               => basename($dir),
                    'label'                   => $label,
                    'kind'                    => 'pdf_doc',
                ];
                $points[] = ['vector' => array_fill(0, $vectorSize ?? 8, 0.0), 'payload' => $payloadDoc];
                $docCount += 1;
                $pointCount += 1;
            }

            $chunks = $this->cmd->splitMarkdownIntoChunks($docMd, 3200, 250);
            $chunkIndex = 0;
            $seen = [];

            foreach ($chunks as $ch) {
                $chunkText = trim($ch['text'] ?? '');
                if ($chunkText === '') continue;
                $h = sha1($chunkText);
                if (isset($seen[$h])) continue;
                $seen[$h] = true;

                if ($dryRun) {
                    $chunkCount += 1;
                    $pointCount += 1;
                } else {
                    $chunkVec = $embed->embed(mb_strlen($chunkText) > 4000 ? mb_substr($chunkText, 0, 4000) : $chunkText);
                    if (empty($chunkVec)) continue;
                    if ($vectorSize === null && $qdrant) {
                        $vectorSize = count($chunkVec);
                        $qdrant->ensureCollection($vectorSize, $distance);
                    }
                    $payloadChunk = $minimal ? [
                        'title'         => $this->cleanTitle($title),
                        'content'       => $chunkText,
                        'page_url'      => $pageUrl,
                        'source_url'    => $pageUrl,
                        'pdf_url'       => $pdfUrl,
                        'source_format' => 'pdf',
                        'date'          => $date,
                        'tags'          => $tags,
                        'kind'          => 'pdf_chunk',
                        'label'         => $label,
                        'parent_id'     => basename($dir),
                        'chunk_index'   => $chunkIndex,
                    ] : [
                        'title'                   => $this->cleanTitle($title),
                        'content'                 => $chunkText,
                        'meta_img_url'            => $metaImgUrl,
                        'page_url'                => $pageUrl,
                        'source_url'              => $pageUrl,
                        'pdf_url'                 => $pdfUrl,
                        'source_format'           => 'pdf',
                        'date'                    => $date,
                        'tags'                    => $tags,
                        'intermediate_formatting' => null,
                        'dir'                     => $dir,
                        'pdf_path'                => $pdfMdPath,
                        'json_path'               => $jsonPath,
                        'hash'                    => $h,
                        'collection'              => config('model_provider.vector_stores.qdrant.collection', 'embeddings_hawk'),
                        'source'                  => 'crawl',
                        'chunk_index'             => $chunkIndex,
                        'parent_id'               => basename($dir),
                        'label'                   => $label,
                        'kind'                    => 'pdf_chunk',
                    ];
                    $points[] = ['vector' => array_map('floatval', $chunkVec), 'payload' => $payloadChunk];
                    $chunkCount += 1;
                    $pointCount += 1;
                }
                $chunkIndex++;
            }
        }

        return [
            'points'     => $points,
            'docCount'   => $docCount,
            'chunkCount' => $chunkCount,
            'pointCount' => $pointCount,
            'vectorSize' => $vectorSize,
        ];
    }

    protected function tryEnrich(callable $fn, int $retries = 1): string
    {
        for ($i = 0; $i <= $retries; $i++) {
            try {
                $out = trim((string)$fn());
                if ($out !== '' && mb_strtolower($out) !== 'undefined') return $out;
            } catch (\Throwable $e) {
                usleep(200000);
            }
        }
        return '';
    }

    protected function capForEnrichment(string $text, int $maxChars = 3500): string
    {
        if (mb_strlen($text) <= $maxChars) return $text;
        $slice = mb_substr($text, 0, $maxChars);
        $cut = mb_strrpos($slice, "\n\n");
        if ($cut !== false && $cut > $maxChars * 0.6) return mb_substr($text, 0, $cut);
        return $slice;
    }

    protected function localParagraphSummary(string $text, int $maxWords = 120): string
    {
        $t = preg_replace('/\s+/', ' ', trim($text));
        if ($t === '') return '';
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [$t];
        $out = [];
        $wc = 0;
        foreach ($sentences as $s) {
            $w = preg_split('/\s+/', trim($s));
            $c = count($w);
            if ($c < 4) continue;
            $out[] = trim($s);
            $wc += $c;
            if ($wc >= $maxWords) break;
        }
        $joined = implode(' ', $out);
        if ($joined === '') $joined = mb_substr($t, 0, 500);
        return $joined;
    }

    protected function cleanTitle(?string $title): ?string
    {
        if ($title === null) return null;
        if (Str::startsWith($title, '[') && Str::endsWith($title, ']')) {
            $j = json_decode($title, true);
            if (is_array($j) && count($j) === 1) {
                $first = array_values($j)[0];
                if (is_string($first)) return trim($first);
            }
            $title = trim($title, "[] \t\n\r\0\x0B\"");
        }
        return $title;
    }

    protected function coerceKeywords($raw): array
    {
        if (is_array($raw)) return $this->sanitizeKeywordsArray($raw);
        $s = trim((string)$raw);
        if ($s === '' || mb_strtolower($s) === 'undefined') return [];
        $s = preg_replace('/^[^\n:]{0,200}:\s*/ui', '', $s);
        $s = preg_replace('/-\s*\d+\s*[\.\-]?\s*/u', "\n", $s);
        $s = preg_replace('/\s*\d+\s*[\.\)\:\-]\s*/u', "\n", $s);
        $lines = preg_split('/[\r\n]+/u', $s) ?: [];
        $parts = [];
        foreach ($lines as $ln) {
            $ln = preg_replace('/^\s*[\-\*\•\x{2022}]?\s*/u', '', $ln);
            foreach (preg_split('/[,;]+/u', $ln) ?: [] as $p) {
                $p = trim($p, " \t\n\r\0\x0B\"'`");
                if ($p !== '') $parts[] = $p;
            }
        }
        return $this->sanitizeKeywordsArray($parts);
    }

    protected function sanitizeKeywordsArray(array $arr, int $limit = 10): array
    {
        $out = [];
        $seen = [];
        foreach ($arr as $k) {
            if (is_array($k)) $k = implode(' ', array_map('strval', $k));
            else $k = (string)$k;
            $k = str_replace('-', ' ', $k);
            $k = preg_replace('/[^[:alpha:]\p{L}\s]/u', ' ', $k);
            $k = preg_replace('/\s+/u', ' ', trim($k));
            $k = mb_strtolower($k);
            if ($k === '' || mb_strlen($k) < 2) continue;
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $k;
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }

    protected function fallbackKeywords(string $text, int $max = 10): array
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
}
