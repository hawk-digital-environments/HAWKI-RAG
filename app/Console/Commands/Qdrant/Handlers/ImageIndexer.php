<?php

/**
 * Builds image points using visual+context summaries with trimmed inputs, retries, and clean tags.
 */

namespace App\Console\Commands\Qdrant\Handlers;

use App\Services\EmbeddingService;
use App\Models\QdrantEmbedding;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImageIndexer
{
    protected $cmd;
    public function __construct($command)
    {
        $this->cmd = $command;
    }

    public function buildImagePoints(
        bool $dryRun,
        EmbeddingService $embed,
        $enricher,
        string $distance,
        ?int $vectorSize,
        ?QdrantEmbedding $qdrant,
        bool $minimal,
        string $dir,
        ?string $jsonPath,
        string $pageText,
        ?string $title,
        ?string $pageUrl,
        ?string $date,
        ?string $metaImgUrl,
        array $imgs,
        string $label
    ): array {
        $points = [];
        $imageCount = 0;
        $pointCount = 0;

        $imgDir = $dir . DIRECTORY_SEPARATOR . 'images';
        if (!File::isDirectory($imgDir)) {
            return ['points' => [], 'imageCount' => 0, 'pointCount' => 0, 'vectorSize' => $vectorSize];
        }

        $files = File::files($imgDir);
        foreach ($files as $fimg) {
            if ($dryRun) {
                $imageCount += 1;
                $pointCount += 1;
                continue;
            }
            if (!$enricher || !method_exists($enricher, 'generateImageContext')) {
                continue;
            }

            $imgPath = $fimg->getPathname();
            $imgBase = $fimg->getFilename();
            $ctx = $this->capForEnrichment($pageText, 1500);

            $imgInter = $this->tryEnrich(function () use ($enricher, $imgPath, $ctx) {
                return (string)$enricher->generateImageContext($imgPath, $ctx);
            });
            if ($imgInter === '' || mb_strtolower($imgInter) === 'undefined') {
                $imgInter = "Image {$imgBase} related to page.";
            }

            $imgTags = [];
            if ($enricher) {
                $imgTags = $this->coerceKeywords($this->tryEnrich(function () use ($enricher, $imgInter) {
                    if (method_exists($enricher, 'generateKeywords')) return $enricher->generateKeywords($imgInter);
                    return $enricher->generateKeywords($imgInter);
                }));
            }
            if (empty($imgTags)) $imgTags = $this->fallbackKeywords($imgInter);

            $imgVec = $embed->embed(mb_strlen($imgInter) > 4000 ? mb_substr($imgInter, 0, 4000) : $imgInter);
            if (empty($imgVec)) continue;

            if ($vectorSize === null && $qdrant) {
                $vectorSize = count($imgVec);
                $qdrant->ensureCollection($vectorSize, $distance);
            }

            $sourceUrl = $this->cmd->bestImageUrl($imgs, $imgBase) ?: ($metaImgUrl ?: $pageUrl);

            $payload = $minimal ? [
                'title'                   => $this->cleanTitle($title),
                'content'                 => $imgBase,
                'page_url'                => $pageUrl,
                'source_url'              => $sourceUrl,
                'source_format'           => 'image',
                'date'                    => $date,
                'tags'                    => $imgTags,
                'intermediate_formatting' => $imgInter,
                'label'                   => $label,
                'parent_id'               => basename($dir),
            ] : [
                'title'                   => $this->cleanTitle($title),
                'content'                 => $imgBase,
                'meta_img_url'            => $metaImgUrl,
                'page_url'                => $pageUrl,
                'source_url'              => $sourceUrl,
                'source_format'           => 'image',
                'date'                    => $date,
                'tags'                    => $imgTags,
                'intermediate_formatting' => $imgInter,
                'images'                  => $imgs,
                'dir'                     => $dir,
                'image_path'              => $imgPath,
                'json_path'               => $jsonPath,
                'hash'                    => sha1($imgBase . '|' . ($imgInter ?? '')),
                'collection'              => config('model_provider.vector_stores.qdrant.collection', 'embeddings_hawk'),
                'source'                  => 'crawl',
                'chunk_index'             => 0,
                'parent_id'               => basename($dir),
                'label'                   => $label,
                'kind'                    => 'image',
            ];

            $points[] = ['vector' => array_map('floatval', $imgVec), 'payload' => $payload];
            $imageCount += 1;
            $pointCount += 1;
        }

        return [
            'points'     => $points,
            'imageCount' => $imageCount,
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

    protected function capForEnrichment(string $text, int $maxChars = 1500): string
    {
        if (mb_strlen($text) <= $maxChars) return $text;
        $slice = mb_substr($text, 0, $maxChars);
        $cut = mb_strrpos($slice, "\n\n");
        if ($cut !== false && $cut > $maxChars * 0.6) return mb_substr($text, 0, $cut);
        return $slice;
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
