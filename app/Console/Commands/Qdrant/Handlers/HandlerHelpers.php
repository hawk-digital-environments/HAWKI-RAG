<?php

namespace App\Console\Commands\Qdrant\Handlers;

use Illuminate\Support\Str;

trait HandlerHelpers
{
    /* --------------------- Enrichment / parsing helpers --------------------- */

    protected function isUndefined(?string $s): bool
    {
        if ($s === null) return true;
        $t = mb_strtolower(trim($s));
        return $t === '' || $t === 'undefined' || $t === '(undefined)';
    }

    /** Parse keywords from either comma-separated or numbered/bulleted lists. */
    protected function parseKeywords(?string $raw, int $max = 10): array
    {
        if (!$raw) return [];
        $raw = trim($raw);

        // Prefer comma-separated
        if (strpos($raw, ',') !== false) {
            $parts = array_map('trim', explode(',', $raw));
        } else {
            // Fallback: split on newlines
            $parts = preg_split('/\r?\n+/', $raw);
        }

        $out = [];
        foreach ($parts as $p) {
            // Remove leading numbering/bullets (e.g., "1. ", "- ", "• ")
            $p = preg_replace('/^\s*(?:\d+[\.\)]\s*|[-•–—]\s*)/u', '', $p);

            // Skip any prose lines like "domain-relevant keywords for ..."
            if (preg_match('/\bkeywords?\b\s+for\b/i', $p)) continue;

            $p = mb_strtolower(trim($p, " \t\n\r\0\x0B\"'"));
            if ($p === '' || mb_strlen($p) < 3) continue;

            // Collapse spaces -> hyphen so multi-word tokens become keyword-like
            $p = preg_replace('/\s+/u', '-', $p);

            $out[] = $p;
            if (count($out) >= $max) break;
        }

        // De-duplicate, keep order
        $seen = [];
        return array_values(array_filter($out, function ($k) use (&$seen) {
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));
    }

    /** Fallback keyword generator (EN+DE stoplist). */
    protected function fallbackKeywords(string $text, int $max = 10): array
    {
        $text = mb_strtolower((string) $text);
        $words = preg_split('/[^[:alpha:]\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $stop = [
            // EN
            'the',
            'and',
            'for',
            'with',
            'that',
            'this',
            'from',
            'you',
            'your',
            'are',
            'was',
            'were',
            'of',
            'to',
            'in',
            'on',
            'at',
            'by',
            'as',
            'is',
            'it',
            'be',
            'or',
            'an',
            'a',
            'we',
            'our',
            // DE
            'und',
            'der',
            'die',
            'das',
            'mit',
            'den',
            'von',
            'für',
            'auf',
            'ist',
            'sind',
            'im',
            'in',
            'an',
            'zu',
            'eine',
            'ein',
            'einer',
            'einem',
            'eines',
            'bei',
            'dem',
            'des',
            'auch'
        ];

        $counts = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array($w, $stop, true)) continue;
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }

        arsort($counts);
        $top = array_slice(array_keys($counts), 0, $max);

        // Ensure uniqueness
        $seen = [];
        $out = [];
        foreach ($top as $t) {
            if (!isset($seen[$t])) {
                $seen[$t] = true;
                $out[] = $t;
            }
        }
        return $out;
    }

    protected function clampStr(string $s, int $max = 650): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    /* --------------------- Chunking / dedup helpers --------------------- */

    /** Normalize chunk text so headers/footers don’t create false differences. */
    protected function normalizeForHash(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);             // collapse whitespace
        $s = preg_replace('/\bPage\s+\d+\b/i', '', $s);   // remove "Page N"
        $s = preg_replace('/^\s*[-–—•\*]\s*/m', '', $s);  // bullet artifacts
        // Example site-specific header removal (tweak as needed):
        $s = preg_replace('/\bHAWK\b.*?\bKunst\b/i', '', $s, 1);
        return trim(mb_strtolower($s));
    }

    /** Cheap Jaccard similarity on token sets to drop near-duplicates. */
    protected function isNearDuplicate(string $a, string $b, float $threshold = 0.92): bool
    {
        $ta = array_unique(preg_split('/\W+/u', $this->normalizeForHash($a), -1, PREG_SPLIT_NO_EMPTY));
        $tb = array_unique(preg_split('/\W+/u', $this->normalizeForHash($b), -1, PREG_SPLIT_NO_EMPTY));
        if (empty($ta) || empty($tb)) return false;
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        return $union ? ($inter / $union) >= $threshold : false;
    }

    protected function splitMarkdownIntoChunks(string $md, int $targetChars = 3200, int $overlap = 250): array
    {
        $md = trim($md);
        if ($md === '') return [];
        $len = mb_strlen($md);
        if ($len <= $targetChars) {
            return [['text' => $md, 'range' => [0, $len]]];
        }

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

    /* --------------------- Small utilities --------------------- */

    /** Truncate to 4k for embedding providers. */
    protected function embedText($embed, string $text): ?array
    {
        $snippet = mb_strlen($text) > 4000 ? mb_substr($text, 0, 4000) : $text;
        $vec = $embed->embed($snippet);
        return (is_array($vec) && !empty($vec)) ? array_map('floatval', $vec) : null;
    }

    /** Choose the best image URL from crawled list that matches filename. */
    protected function bestImageUrl(array $imgUrls, string $fileBase): ?string
    {
        $needle = Str::before($fileBase, '.');
        foreach ($imgUrls as $u) {
            $path = parse_url($u, PHP_URL_PATH) ?? '';
            if (Str::contains(basename($path), $needle)) return $u;
        }
        return null;
    }
}
