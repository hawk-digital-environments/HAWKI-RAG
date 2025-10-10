<?php
/**
 * Scan crawled hawk-text directories and flag noisy entries.
 *
 * Heuristics:
 *  - Thin content: body has very few words or characters.
 *  - Structural noise: body dominated by navigation/footer/cookie language.
 *  - Duplicates: identical body text or repeated page_url.
 *
 * Output:
 *  - Prints counts per category.
 *  - Writes JSON report with directories and reasons to remove.
 *
 * The script does not delete anything; it only reports findings.
 */

declare(strict_types=1);

const DEFAULT_ROOT = __DIR__ . '/../storage/app/private/crawled-data/hawk-text';
const OUTPUT_FILENAME = 'noise_candidates.json';

$root = $argv[1] ?? DEFAULT_ROOT;
$root = realpath($root) ?: $root;

if (!is_dir($root)) {
    fwrite(STDERR, "Root directory not found: {$root}\n");
    exit(1);
}

$dirIterator = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);

$duplicateHashes = [];
$duplicateUrls = [];
$directoryReports = [];

$keywordPatterns = [
    'cookie', 'cookies', 'navigation', 'nav ', 'menu', 'menü', 'breadcrumb',
    'footer', 'impressum', 'datenschutz', 'privacy', 'all rights reserved',
    'newsletter', 'accept cookies', 'reject cookies', 'content skip',
    'toggle navigation', 'zum inhalt springen', 'zum hauptinhalt', 'legal notice',
    'tracking settings', 'consent', 'barrierefreiheit', 'accessibility statement'
];

foreach ($dirIterator as $dirInfo) {
    if (!$dirInfo->isDir()) {
        continue;
    }

    $dirPath = $dirInfo->getPathname();
    $dirName = $dirInfo->getBasename();

    [$textContent, $bodyOnly] = readTextContent($dirPath);
    if ($textContent === null) {
        // No text file at all: treat as thin.
        registerReason($directoryReports, $dirName, 'thin_content', $dirPath);
        continue;
    }

    $wordCount = str_word_count($bodyOnly);
    $charCount = strlen($bodyOnly);

    if ($wordCount < 80 || $charCount < 400) {
        registerReason($directoryReports, $dirName, 'thin_content', $dirPath, [
            'word_count' => $wordCount,
            'char_count' => $charCount,
        ]);
    }

    $lower = mb_strtolower($bodyOnly, 'UTF-8');
    $keywordHits = 0;
    foreach ($keywordPatterns as $keyword) {
        if (strpos($lower, $keyword) !== false) {
            $keywordHits++;
        }
    }

    $lineCount = max(1, substr_count($bodyOnly, "\n") + 1);
    $avgLineLength = $charCount / $lineCount;
    $shortLineRatio = calculateShortLineRatio($bodyOnly, 40);

    $uniqueWords = countUniqueWords($bodyOnly);
    $uniquenessRatio = $wordCount > 0 ? ($uniqueWords / $wordCount) : 0.0;

    $structuralNoise = false;
    if ($keywordHits >= 3 && $wordCount < 600) {
        $structuralNoise = true;
    } elseif ($shortLineRatio > 0.65 && $avgLineLength < 45) {
        $structuralNoise = true;
    } elseif ($uniquenessRatio < 0.35 && $wordCount >= 20) {
        $structuralNoise = true;
    }

    if ($structuralNoise) {
        registerReason($directoryReports, $dirName, 'structural_noise', $dirPath, [
            'keyword_hits' => $keywordHits,
            'word_count' => $wordCount,
            'uniqueness_ratio' => round($uniquenessRatio, 3),
        ]);
    }

    // Duplicate detection by normalized body text hash.
    $normalized = preg_replace('/\s+/u', ' ', $lower);
    $normalized = trim($normalized);
    if ($normalized !== '') {
        $hash = hash('sha256', $normalized);
        if (isset($duplicateHashes[$hash])) {
            registerReason($directoryReports, $dirName, 'duplicate_text', $dirPath, [
                'duplicate_of' => $duplicateHashes[$hash],
            ]);
        } else {
            $duplicateHashes[$hash] = $dirName;
        }
    }

    // Duplicate detection via page_url in JSON metadata.
    $pageUrl = readPageUrl($dirPath);
    if ($pageUrl !== null) {
        if (isset($duplicateUrls[$pageUrl])) {
            registerReason($directoryReports, $dirName, 'duplicate_url', $dirPath, [
                'duplicate_of' => $duplicateUrls[$pageUrl],
                'page_url' => $pageUrl,
            ]);
        } else {
            $duplicateUrls[$pageUrl] = $dirName;
        }
    }
}

outputSummary($directoryReports, $root);

/**
 * Reads the primary text file in the directory.
 *
 * Returns [fullContent, bodyOnly] or [null, null] if missing.
 */
function readTextContent(string $dirPath): array
{
    $files = glob($dirPath . '/site_*.txt');
    if (!$files) {
        return [null, null];
    }

    $content = @file_get_contents($files[0]);
    if ($content === false) {
        return [null, null];
    }
    $content = trim($content);
    $lines = preg_split('/\R/u', $content);
    if ($lines === false || count($lines) === 0) {
        return [$content, ''];
    }
    $bodyOnly = implode("\n", array_slice($lines, 1));
    return [$content, trim($bodyOnly)];
}

/**
 * Attempts to extract the first page_url value from JSON metadata.
 */
function readPageUrl(string $dirPath): ?string
{
    $jsonFiles = glob($dirPath . '/data_*.json');
    if (!$jsonFiles) {
        return null;
    }
    $raw = @file_get_contents($jsonFiles[0]);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $pageUrl = $data['page_url'] ?? ($data['url'] ?? null);
    if (is_array($pageUrl)) {
        $pageUrl = $pageUrl[0] ?? null;
    }
    return is_string($pageUrl) ? trim($pageUrl) : null;
}

/**
 * Calculate ratio of lines shorter than threshold length.
 */
function calculateShortLineRatio(string $content, int $lengthThreshold): float
{
    $lines = preg_split('/\R/u', $content);
    if (!$lines) {
        return 0.0;
    }
    $short = 0;
    foreach ($lines as $line) {
        if (mb_strlen(trim($line), 'UTF-8') <= $lengthThreshold) {
            $short++;
        }
    }
    return count($lines) > 0 ? $short / count($lines) : 0.0;
}

/**
 * Approximate unique word count.
 */
function countUniqueWords(string $content): int
{
    $content = mb_strtolower($content, 'UTF-8');
    $content = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $content);
    $words = preg_split('/\\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return 0;
    }
    return count(array_unique($words));
}

/**
 * Register a reason for marking a directory.
 */
function registerReason(array &$reports, string $dirName, string $reason, string $dirPath, array $meta = []): void
{
    if (!isset($reports[$dirName])) {
        $reports[$dirName] = [
            'path' => $dirPath,
            'reasons' => [],
        ];
    }
    $reports[$dirName]['reasons'][$reason] = $meta ?: new stdClass();
}

/**
 * Output summary counts and write JSON report.
 */
function outputSummary(array $reports, string $root): void
{
    $reasonCounts = [
        'thin_content' => 0,
        'structural_noise' => 0,
        'duplicate_text' => 0,
        'duplicate_url' => 0,
    ];

    foreach ($reports as $entry) {
        foreach ($entry['reasons'] as $reason => $_) {
            if (isset($reasonCounts[$reason])) {
                $reasonCounts[$reason]++;
            } else {
                $reasonCounts[$reason] = 1;
            }
        }
    }

    echo "Noise candidate summary for {$root}:\n";
    foreach ($reasonCounts as $reason => $count) {
        printf("  %-17s : %d\n", $reason, $count);
    }
    printf("  %-17s : %d\n", 'total_directories', count($reports));

    $outputPath = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . OUTPUT_FILENAME;
    $payload = [
        'generated_at' => date('c'),
        'root' => $root,
        'totals' => $reasonCounts,
        'directories' => [],
    ];

    foreach ($reports as $dirName => $data) {
        $payload['directories'][] = [
            'directory' => $dirName,
            'path' => $data['path'],
            'reasons' => $data['reasons'],
        ];
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode JSON report.\n");
        exit(1);
    }
    if (file_put_contents($outputPath, $json) === false) {
        fwrite(STDERR, "Failed to write report to {$outputPath}\n");
        exit(1);
    }

    echo "Report written to {$outputPath}\n";
}
