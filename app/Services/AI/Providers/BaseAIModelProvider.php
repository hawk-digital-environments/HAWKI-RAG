<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Log;

abstract class BaseAIModelProvider
{
    /**
     * Provider configuration
     * 
     * @var array
     */
    protected $config;

    /**
     * Prompt templates
     * 
     * @var array
     */
    protected $prompts;

    /**
     * Create a new provider instance
     * 
     * @param string $providerName Name of the provider in configuration
     */
    public function __construct(string $providerName)
    {
        $this->config = config("model_providers.providers.{$providerName}");
        $this->prompts = config("model_prompts.prompts");
    }

    /**
     * Generate embeddings for a text
     *
     * @param string $text
     * @return array|null
     */
    abstract public function getEmbeddings(string $text);

    /**
     * Generate keywords for given input
     *
     * @param string $input
     * @return string
     */
    abstract public function generateKeywords(string $input);

    /**
     * Generate additional context for given input
     *
     * @param string $input
     * @return string
     */
    abstract public function generateAdditionalContext(string $input);

    /**
     * Generate context for an image
     *
     * @param string $imagePath
     * @param string $text
     * @return string
     */
    abstract public function generateImageContext(string $imagePath, string $text);

    /**
     * Generate optimized search term from conversation
     *
     * @param array $messages
     * @return string
     */
    abstract public function generateTermFromMessage(array $messages);

    /**
     * Generate a non-stream chat response
     *
     * @param array $messages
     * @param mixed $items
     * @return string
     */
    abstract public function generateNonStreamChatResponse(array $messages, $items);

    /**
     * Generate a stream chat response
     *
     * @param array $messages
     * @param mixed $items
     * @param callable $callback Function to process each chunk as it's received
     * @return void
     */
    abstract public function generateStreamChatResponse(array $messages, $items, callable $callback);

    /**
     * Process items into a standardized array format
     *
     * @param mixed $items
     * @return array
     */
    /**
     * Process items into a standardized array format
     *
     * @param mixed $items
     * @return array
     */
    protected function processItemsToArray($items): array
    {
        // Accept ['items'=>hits], Collection, or raw array of hits
        $hits = isset($items['items'])
            ? ($items['items'] instanceof \Illuminate\Support\Collection ? $items['items']->all() : (array)$items['items'])
            : ($items instanceof \Illuminate\Support\Collection ? $items->all() : (array)$items);

        $out = [];
        foreach ($hits as $hit) {
            $out[] = $this->normalizeQdrantHit($hit);
        }
        return $out;
    }

    /**
     * Normalize one Qdrant hit to a flat array our formatters can use.
     */
    protected function normalizeQdrantHit(array $hit): array
    {
        $p = $hit['payload'] ?? [];

        // Robust key mapping (adjust if your payload uses different keys)
        $title   = $p['title']        ?? $p['page_title'] ?? $p['doc_title'] ?? 'Untitled';
        $url     = $p['page_url']     ?? $p['source_url'] ?? $p['url']       ?? '';
        $content = $p['content']      ?? $p['text']       ?? $p['body']      ?? '';
        $image   = $p['meta_img_url'] ?? $p['meta_image'] ?? $p['image']     ?? '';
        $date    = $p['date']         ?? $p['published_at'] ?? '';
        $tags    = $p['tags']         ?? $p['keywords']   ?? null;

        // Make a snippet if none exists
        $snippet = $p['snippet'] ?? (is_string($content) ? mb_substr($content, 0, 800) : '');

        return [
            'title'       => is_string($title) ? $title : 'Untitled',
            'page_url'    => is_string($url) ? $url : '',
            'source_url'  => is_string($p['source_url'] ?? '') ? $p['source_url'] : '',
            'content'     => is_string($content) ? $content : '',
            'snippet'     => $snippet,
            'meta_img_url' => is_string($image) ? $image : '',
            'date'        => is_string($date) ? $date : '',
            'tags'        => is_array($tags) || is_string($tags) ? $tags : null,
            'score'       => $hit['score'] ?? null,
        ];
    }
    /**
     * Canonicalize a URL for deduping: strip locale (/en/, /de/), query, hash, trailing slashes.
     */
    protected function canonicalizeUrl(?string $url): string
    {
        if (!is_string($url) || $url === '') return '';
        // remove query + hash
        $u = preg_replace('~[?#].*$~', '', $url);
        // collapse multiple slashes
        $u = preg_replace('~/{2,}~', '/', $u);
        // strip trailing slash
        if ($u !== '/' && str_ends_with($u, '/')) $u = substr($u, 0, -1);
        // remove a single leading locale segment like /en or /de after domain
        // https://www.hawk.de/en/university/...  -> https://www.hawk.de/university/...
        $u = preg_replace('~^(https?://[^/]+)/(en|de)(/|$)~i', '$1$3', $u);
        return $u;
    }

    /** Very quick language guess for short queries (EN vs DE). */
    protected function guessLang(string $text): string
    {
        $t = mb_strtolower($text);
        // common German markers
        $deHints = [' der ', ' die ', ' das ', ' und ', ' mit ', ' für ', ' an ', ' ist ', ' verantwortung', 'anschrift', 'sprechzeiten', 'leitung', 'fakultät'];
        foreach ($deHints as $h) {
            if (str_contains(' ' . $t . ' ', $h)) return 'de';
        }
        // simple heuristic: if "äöüß" present, assume DE
        if (preg_match('/[äöüß]/iu', $t)) return 'de';
        return 'en';
    }

    /**
     * Keep only items with meaningful snippet, dedupe by canonical URL,
     * prefer items matching target language when duplicates exist.
     */
    protected function filterAndDedupeItems(array $items, int $minSnippetLen = 60, ?string $targetLang = null): array
    {
        $groups = []; // canonicalUrl => [ 'en'=>item, 'de'=>item, 'other'=>[] ]

        foreach ($items as $it) {
            $url   = trim((string)($it['page_url'] ?? ''));
            $canon = $this->canonicalizeUrl($url);
            $snip  = trim((string)($it['snippet'] ?? ''));
            $content = trim((string)($it['content'] ?? ''));

            // Ensure we have some text; drop pure image/file-name “snippets”
            if (mb_strlen($snip) < $minSnippetLen) {
                if (mb_strlen($content) >= $minSnippetLen) {
                    $it['snippet'] = mb_substr($content, 0, 800);
                } else {
                    continue; // drop as low-value
                }
            }

            // Guess item language from text
            $lang = $this->guessLang($snip . ' ' . $content);

            if (!isset($groups[$canon])) $groups[$canon] = ['en' => null, 'de' => null, 'other' => []];
            if ($lang === 'de') {
                // keep the higher score one
                $prev = $groups[$canon]['de'];
                if ($prev === null || (($it['score'] ?? 0) > ($prev['score'] ?? 0))) {
                    $groups[$canon]['de'] = $it;
                }
            } elseif ($lang === 'en') {
                $prev = $groups[$canon]['en'];
                if ($prev === null || (($it['score'] ?? 0) > ($prev['score'] ?? 0))) {
                    $groups[$canon]['en'] = $it;
                }
            } else {
                $groups[$canon]['other'][] = $it;
            }
        }

        // Rebuild list preferring targetLang, then the other, then any
        $out = [];
        $target = $targetLang ?: 'en';
        foreach ($groups as $canon => $bucket) {
            $pick = $bucket[$target] ?? $bucket[($target === 'en') ? 'de' : 'en'] ?? null;
            if ($pick === null) {
                // fallback to best 'other' or any available
                if (!empty($bucket['other'])) {
                    usort($bucket['other'], fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
                    $pick = $bucket['other'][0];
                } else {
                    $pick = $bucket['en'] ?? $bucket['de'] ?? null;
                }
            }
            if ($pick !== null) $out[] = $pick;
        }

        // Keep a reasonable cap (e.g., top 5 by score) to save tokens
        usort($out, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        return array_slice($out, 0, 5);
    }


    /**
     * Format basic page information for prompts
     *
     * @param array $itemsArray
     * @return array
     */
    protected function formatBasicPagesList(array $itemsArray): array
    {
        $list = [];
        foreach ($itemsArray as $i => $it) {
            $score = isset($it['score']) ? ' (score: ' . round((float)$it['score'], 3) . ')' : '';
            $url   = $it['page_url'] ?: ($it['source_url'] ?: 'No page URL available');
            $img   = $it['meta_img_url'] ?: 'No meta image URL available';
            // NOTE: fixed the "\Page URL" bug — it should be "\nPage URL"
            $list[] = sprintf(
                "- %s%s\nMeta Image: %s\nPage URL: %s",
                $it['title'] ?: 'Untitled page',
                $score,
                $img,
                $url
            );
        }
        return $list;
    }

    /**
     * Format detailed page information for prompts
     *
     * @param array $itemsArray
     * @return array
     */
    protected function formatExtendedPagesList(array $itemsArray): array
    {
        $list = [];
        foreach ($itemsArray as $i => $it) {
            $tags = is_array($it['tags']) ? implode(', ', $it['tags']) : ($it['tags'] ?? 'No tags or keywords available');
            $snip = trim((string)$it['snippet']);
            if ($snip === '' && is_string($it['content'])) {
                $snip = mb_substr($it['content'], 0, 800);
            }
            $list[] = sprintf(
                "Extended details for \"%s\":\n" .
                    "  Meta Image: %s\n" .
                    "  Page URL: %s\n" .
                    "  Source URL: %s\n" .
                    "  Date: %s\n" .
                    "  Tags: %s\n" .
                    "  Snippet:\n%s",
                $it['title'] ?: 'Untitled page',
                $it['meta_img_url'] ?: 'No meta image URL available',
                $it['page_url'] ?: 'No page URL available',
                $it['source_url'] ?: 'No source URL available',
                $it['date'] ?: 'No date available',
                $tags,
                $snip ?: 'No snippet available'
            );
        }
        return $list;
    }
}
