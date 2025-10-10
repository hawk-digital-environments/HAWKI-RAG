<?php
/**
 * Remove duplicate hawk-text directories according to noise_candidates.json.
 *
 * Usage:
 *   php scripts/remove_duplicate_hawk_text.php [reportJson] [--dry-run]
 *
 * The script reads the JSON produced by filter_hawk_text_noise.php,
 * finds entries with the duplicate_text reason, and deletes each
 * duplicate directory while preserving the first instance.
 */

declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

$args = $argv;
array_shift($args); // drop script name

$dryRun = false;
foreach ($args as $index => $value) {
    if ($value === '--dry-run') {
        $dryRun = true;
        unset($args[$index]);
    }
}
$args = array_values($args);

$reportPath = $args[0] ?? __DIR__ . '/../storage/app/private/crawled-data/hawk-text/noise_candidates.json';

$reportReal = realpath($reportPath);
if ($reportReal === false || !is_file($reportReal)) {
    fwrite(STDERR, "Report file not found: {$reportPath}\n");
    exit(1);
}

$json = file_get_contents($reportReal);
if ($json === false) {
    fwrite(STDERR, "Failed to read report: {$reportReal}\n");
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data) || !isset($data['directories']) || !is_array($data['directories'])) {
    fwrite(STDERR, "Invalid report structure.\n");
    exit(1);
}

$root = $data['root'] ?? null;
$rootReal = $root ? realpath($root) : false;
if ($rootReal === false) {
    // Fallback to report directory.
    $rootReal = realpath(dirname($reportReal));
}
if ($rootReal === false) {
    fwrite(STDERR, "Unable to resolve root directory.\n");
    exit(1);
}
$rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);

$targets = [];
foreach ($data['directories'] as $entry) {
    if (!isset($entry['reasons']['duplicate_text'])) {
        continue;
    }
    $path = $entry['path'] ?? null;
    if (!is_string($path) || $path === '') {
        $dirName = $entry['directory'] ?? null;
        if (!is_string($dirName) || $dirName === '') {
            continue;
        }
        $path = $rootReal . DIRECTORY_SEPARATOR . $dirName;
    }

    $candidate = realpath($path);
    if ($candidate === false) {
        // Directory already removed.
        continue;
    }
    if (!is_dir($candidate)) {
        continue;
    }
    if (!str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)) {
        fwrite(STDERR, "Skipping suspicious path outside root: {$candidate}\n");
        continue;
    }
    $targets[$candidate] = true;
}

$targetPaths = array_keys($targets);
sort($targetPaths);
$count = count($targetPaths);

if ($count === 0) {
    echo "No duplicate directories found to remove.\n";
    exit(0);
}

printf("Identified %d duplicate directories under %s\n", $count, $rootReal);
if ($dryRun) {
    echo "Dry-run enabled; no directories will be removed.\n";
    $preview = array_slice($targetPaths, 0, 20);
    foreach ($preview as $path) {
        echo "[DRY-RUN] would remove: {$path}\n";
    }
    if ($count > count($preview)) {
        printf("... and %d more\n", $count - count($preview));
    }
    exit(0);
}

$removed = 0;
$errors = [];
foreach ($targetPaths as $path) {
    if (removeDirectory($path)) {
        $removed++;
        echo "Removed: {$path}\n";
    } else {
        $errors[] = $path;
        fwrite(STDERR, "Failed to remove: {$path}\n");
    }
}

printf("Removal complete. Removed %d directories; %d failures.\n", $removed, count($errors));
if ($errors) {
    fwrite(STDERR, "Failures:\n");
    foreach ($errors as $path) {
        fwrite(STDERR, "  {$path}\n");
    }
    exit(1);
}

exit(0);

/**
 * Recursively delete a directory.
 */
function removeDirectory(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $items = scandir($dir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            if (!removeDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($dir);
}
