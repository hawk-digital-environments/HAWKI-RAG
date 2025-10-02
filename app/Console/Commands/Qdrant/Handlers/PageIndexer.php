<?php

/**
 * Builds a single page point (text) with robust enrichment (trimmed input, retries, heuristic fallback) and clean tags.
 */

namespace App\Console\Commands\Qdrant\Handlers;

use Illuminate\Support\Str;
use App\Services\EmbeddingService;
use App\Models\QdrantEmbedding;

class PageIndexer
{
    protected $cmd;
    public function __construct($command)
    {
        $this->cmd = $command;
    }

    public function buildPagePoint(
        EmbeddingService $embed,
        $enricher,
        string $distance,
        ?int $vectorSize,
        ?QdrantEmbedding $qdrant,
        bool $minimal,
        string $dir,
        ?string $mdPath,
        ?string $jsonPath,
        string $text,
        string $sourceFormat,
        ?string $title,
        ?string $pageUrl,
        ?string $date,
        ?string $metaImgUrl,
        array $imgs,
        array $pdfs,
        ?string $pdfUrl,
        string $label
    ): array {
        $title = $this->cleanTitle($title);
        $sumInput = $this->capForEnrichment($text, 3000);
        $intermediate = '';

        if ($enricher) {
            $intermediate = $this->tryEnrich(function () use ($enricher, $sumInput) {
                if (method_exists($enricher, 'generatePageSummary')) return (string)$enricher->generatePageSummary($sumInput);
                return (string)$enricher->generateAdditionalContext($sumInput);
            });
        }
        if ($intermediate === '' || mb_strtolower($intermediate) === 'undefined') {
            $intermediate = $this->localParagraphSummary($text, 120);
        }

        $tags = [];
        if ($enricher) {
            $tags = $this->coerceKeywords($this->tryEnrich(function () use ($enricher, $intermediate, $text) {
                if (method_exists($enricher, 'generateKeywords')) return $enricher->generateKeywords($intermediate ?: $text);
                return $enricher->generateKeywords($intermediate ?: $text);
            }));
        }
        if (empty($tags)) $tags = $this->fallbackKeywords($intermediate ?: $text);

        $snippet = mb_strlen($text) > 4000 ? mb_substr($text, 0, 4000) : $text;
        $vec     = $embed->embed($snippet);
        if (empty($vec)) return ['ok' => false];

        if ($vectorSize === null && $qdrant) {
            $vectorSize = count($vec);
            $qdrant->ensureCollection($vectorSize, $distance);
        }

        $payload = $minimal ? [
            'title'                   => $title,
            'content'                 => $text,
            'page_url'                => $pageUrl,
            'source_url'              => $pageUrl,
            'pdf_url'                 => $pdfUrl,
            'source_format'           => $sourceFormat,
            'date'                    => $date,
            'tags'                    => $tags,
            'intermediate_formatting' => $intermediate,
            'kind'                    => 'page',
            'label'                   => $label,
            'parent_id'               => basename($dir),
        ] : [
            'title'                   => $title,
            'content'                 => $text,
            'meta_img_url'            => $metaImgUrl,
            'images'                  => $imgs,
            'pdfs'                    => $pdfs,
            'page_url'                => $pageUrl,
            'source_url'              => $pageUrl,
            'pdf_url'                 => $pdfUrl,
            'source_format'           => $sourceFormat,
            'date'                    => $date,
            'tags'                    => $tags,
            'intermediate_formatting' => $intermediate,
            'dir'                     => $dir,
            'md_path'                 => $mdPath,
            'json_path'               => $jsonPath,
            'hash'                    => sha1($text),
            'collection'              => config('model_provider.vector_stores.qdrant.collection', 'embeddings_hawk'),
            'source'                  => 'crawl',
            'chunk_index'             => 0,
            'parent_id'               => basename($dir),
            'label'                   => $label,
            'kind'                    => 'page',
        ];

        return [
            'ok'         => true,
            'vector'     => array_map('floatval', $vec),
            'payload'    => $payload,
            'vectorSize' => $vectorSize ?? count($vec),
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

    protected function capForEnrichment(string $text, int $maxChars = 3000): string
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
