<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Services\TextIngestion\Exceptions\TextIngestionStorageException;
use App\Services\TextIngestion\Values\StoredTextArtifact;
use App\Services\TextIngestion\Values\TextIngestionInput;
use App\Services\TextIngestion\Values\TextIngestionMode;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

/**
 * Class Responsiblity:
 * It stores text or Markdown submitted through direct-text ingestion
 * as an immutable artifact that the indexer can read.
 * Each document is stored under:
 *
 *   <shared-root>/sources/<source-id>/revisions/<revision-hash>/markdown/
 *
 * The main files are:
 *   document.md
 *   rawki_passthrough.json
 * ==========================================================================
 * How is source_id created?
 *
 * The source ID is generated from:
 *
 *   dataset_id + external_document_id
 *
 * This means the same external document inside the same dataset always
 * gets the same logical source ID.
 * ==========================================================================
 * What is content_hash?
 *
 * content_hash is the SHA-256 hash of the actual document text.
 *
 * If the text changes, the content hash changes.
 * ==========================================================================
 * What is revision_hash?
 *
 * revision_hash represents the complete ingestion input, not only the text.
 *
 * For example, changing metadata can create a new revision even when the
 * document text stays the same.
 * ==========================================================================
 * Why are artifacts immutable?
 *
 * Once a revision is written, it should never be silently overwritten.
 * This makes retries safer and prevents two concurrent requests from
 * accidentally replacing each other's data.
 * The class first writes to a temporary file and then publishes it using
 * an atomic hard link.
 * If another request already created the same file, we compare the contents.
 * Same contents:
 *   The existing file is accepted.
 *
 * Different contents:
 *   The operation fails because an immutable artifact must never change.
 * ==========================================================================
 * What is reconciliation-required.json?
 *
 * Saving the artifact and saving the database records are two separate
 * operations.
 *
 * It is possible for this to happen:
 *
 *   artifact saved successfully
 *   database transaction failed
 *
 * In that situation, we create reconciliation-required.json.
 * The marker tells us that this revision may need to be checked later.
 *
 * Why don't we immediately delete the artifact after a database failure?
 *
 * Another request may be using the same artifact.
 *
 * Deleting it immediately could break a successful concurrent ingestion.
 * ==========================================================================
 * What happens after a successful retry?
 *
 * The reconciliation marker is removed.
 */
#[Singleton]
final readonly class TextIngestionArtifactStorage
{
    private const RECONCILIATION_MARKER = 'reconciliation-required.json';

    private const SHARED_DIRECTORY_MODE = 02775;

    private const SHARED_FILE_MODE = 0664;

    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
    ) {}

    public function store(TextIngestionInput $input): StoredTextArtifact
    {
        $sourceId = $this->sourceId($input);
        $sourceUrl = $input->sourceUrl
            ?? 'external://'.$input->externalDocumentId;
        $contentHash = hash('sha256', $input->text);
        $revisionHash = hash(
            'sha256',
            json_encode($input->toArray(), JSON_THROW_ON_ERROR),
        );

        $sourceDirectory = $this->sharedRoot()
            .DIRECTORY_SEPARATOR.'sources'
            .DIRECTORY_SEPARATOR.$sourceId;
        $revisionsDirectory = $sourceDirectory
            .DIRECTORY_SEPARATOR.'revisions';
        $revisionDirectory = $revisionsDirectory
            .DIRECTORY_SEPARATOR.$revisionHash;
        $markdownDirectory = $revisionDirectory
            .DIRECTORY_SEPARATOR.'markdown';

        $markdownPath = $markdownDirectory
            .DIRECTORY_SEPARATOR.'document.md';
        $metadataPath = $markdownDirectory
            .DIRECTORY_SEPARATOR.'rawki_passthrough.json';
        $metadata = json_encode([
            'ingestion_mode' => TextIngestionMode::DirectText->value,
            'external_document_id' => $input->externalDocumentId,
            'display_name' => $input->displayName,
            'content_format' => $input->contentFormat->value,
            'metadata' => $input->metadata,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            foreach ([$sourceDirectory, $revisionsDirectory, $revisionDirectory, $markdownDirectory] as $directory) {
                $this->ensureSharedDirectory($directory);
            }
            $this->writeImmutable($markdownPath, $input->text);
            $this->writeImmutable($metadataPath, $metadata."\n");
        } catch (TextIngestionStorageException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw TextIngestionStorageException::couldNotStore(
                $markdownPath,
                $exception,
            );
        }

        return StoredTextArtifact::fromStoredText(
            sourceId: $sourceId,
            sourceUrl: $sourceUrl,
            contentHash: $contentHash,
            markdownPath: $markdownPath,
        );
    }

    public function markForReconciliation(StoredTextArtifact $artifact): void
    {
        $markerPath = $this->reconciliationMarkerPath($artifact);
        $marker = json_encode([
            'source_id' => $artifact->sourceId,
            'content_hash' => $artifact->contentHash,
            'markdown_path' => $artifact->markdownPath,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            $this->writeImmutable($markerPath, $marker."\n");
        } catch (\Throwable $exception) {
            throw TextIngestionStorageException::couldNotStore(
                $markerPath,
                $exception,
            );
        }
    }

    public function clearReconciliationMarker(StoredTextArtifact $artifact): void
    {
        $this->files->delete($this->reconciliationMarkerPath($artifact));
    }

    private function ensureSharedDirectory(string $path): void
    {
        $this->files->ensureDirectoryExists($path, self::SHARED_DIRECTORY_MODE);

        if ($this->files->chmod($path, self::SHARED_DIRECTORY_MODE) !== true) {
            throw new \RuntimeException("Could not publish shared directory permissions [{$path}].");
        }
    }

    private function writeImmutable(string $path, string $contents): void
    {
        if ($this->files->exists($path)) {
            $this->assertStoredContents($path, $contents);

            return;
        }

        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
        try {
            if ($this->files->put($temporaryPath, $contents, true) === false) {
                throw new \RuntimeException("Could not write temporary artifact [{$temporaryPath}].");
            }
            if ($this->files->chmod($temporaryPath, self::SHARED_FILE_MODE) !== true) {
                throw new \RuntimeException("Could not publish shared artifact permissions [{$temporaryPath}].");
            }

            if (! @link($temporaryPath, $path)) {
                if (! $this->files->exists($path)) {
                    throw new \RuntimeException("Could not publish immutable artifact [{$path}].");
                }
                $this->assertStoredContents($path, $contents);
            }
        } finally {
            $this->files->delete($temporaryPath);
        }
    }

    private function assertStoredContents(string $path, string $expected): void
    {
        $stored = $this->files->get($path);
        if (! hash_equals(hash('sha256', $expected), hash('sha256', $stored))) {
            throw new \RuntimeException("Immutable artifact already exists with different contents [{$path}].");
        }
    }

    private function sourceId(TextIngestionInput $input): string
    {
        $identity = $input->datasetId.'|'.$input->externalDocumentId;

        return 'source_'.substr(hash('sha256', $identity), 0, 32);
    }

    private function reconciliationMarkerPath(StoredTextArtifact $artifact): string
    {
        return dirname(dirname($artifact->markdownPath))
            .DIRECTORY_SEPARATOR.self::RECONCILIATION_MARKER;
    }

    private function sharedRoot(): string
    {
        $root = trim((string) $this->config->get(
            'temporal.storage.shared_root',
            '/shared',
        ));

        $normalized = rtrim($root, DIRECTORY_SEPARATOR);
        if (
            $normalized === ''
            || ! str_starts_with($normalized, DIRECTORY_SEPARATOR)
            || preg_match('#(^|/)\.{1,2}(/|$)#', $normalized) === 1
        ) {
            throw TextIngestionStorageException::couldNotStore(
                $root,
                new \InvalidArgumentException('The shared storage root must be a canonical absolute directory.'),
            );
        }

        return $normalized;
    }
}
