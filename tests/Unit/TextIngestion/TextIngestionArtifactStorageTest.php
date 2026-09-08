<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\TextIngestion\Exceptions\TextIngestionStorageException;
use App\Services\TextIngestion\TextIngestionArtifactStorage;
use App\Services\TextIngestion\Values\TextIngestionInput;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class TextIngestionArtifactStorageTest extends TestCase
{
    private Filesystem $files;

    private string $sharedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->sharedRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'rawki-text-ingestion-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->sharedRoot);

        parent::tearDown();
    }

    public function test_it_stores_text_as_a_markdown_artifact(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-123',
            'dataset_id' => 'default',
            'text' => "# Example\n\nDocument content.",
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/document-123',
            'metadata' => ['assistant_id' => 'assistant-42'],
        ]);

        $artifact = $this->storage()->store($input);

        $expectedSourceId = 'source_'.substr(
            hash('sha256', 'default|document-123'),
            0,
            32,
        );

        self::assertSame($expectedSourceId, $artifact->sourceId);
        self::assertSame(
            'https://hawki.example/document-123',
            $artifact->sourceUrl,
        );
        self::assertSame(
            hash('sha256', $input->text),
            $artifact->contentHash,
        );
        self::assertFileExists($artifact->markdownPath);
        self::assertSame(
            $input->text,
            $this->files->get($artifact->markdownPath),
        );
        self::assertSame([
            'ingestion_mode' => 'direct_text',
            'external_document_id' => 'document-123',
            'display_name' => 'API document',
            'content_format' => 'markdown',
            'metadata' => ['assistant_id' => 'assistant-42'],
        ], json_decode(
            $this->files->get(dirname($artifact->markdownPath).'/rawki_passthrough.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function test_it_generates_a_source_url_when_none_is_given(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-456',
            'dataset_id' => 'default',
            'text' => 'Plain text content.',
            'content_format' => 'plain_text',
        ]);

        $artifact = $this->storage()->store($input);

        self::assertSame(
            'external://document-456',
            $artifact->sourceUrl,
        );
    }

    public function test_it_does_not_overwrite_an_existing_revision(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-immutable',
            'dataset_id' => 'default',
            'text' => 'Original text.',
            'content_format' => 'plain_text',
        ]);
        $storage = $this->storage();
        $artifact = $storage->store($input);
        $this->files->put($artifact->markdownPath, 'Unexpected replacement.');

        $this->expectException(TextIngestionStorageException::class);

        $storage->store($input);
    }

    public function test_reconciliation_marker_contains_identifiers_but_not_document_text(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-reconciliation',
            'dataset_id' => 'default',
            'text' => 'Private text that must not appear in the marker.',
            'content_format' => 'plain_text',
        ]);
        $storage = $this->storage();
        $artifact = $storage->store($input);
        $markerPath = dirname(dirname($artifact->markdownPath))
            .DIRECTORY_SEPARATOR.'reconciliation-required.json';

        $storage->markForReconciliation($artifact);

        self::assertFileExists($markerPath);
        $marker = $this->files->get($markerPath);
        self::assertStringContainsString($artifact->sourceId, $marker);
        self::assertStringContainsString($artifact->contentHash, $marker);
        self::assertStringNotContainsString($input->text, $marker);

        $storage->clearReconciliationMarker($artifact);

        self::assertFileDoesNotExist($markerPath);
        self::assertFileExists($artifact->markdownPath);
    }

    private function storage(): TextIngestionArtifactStorage
    {
        return new TextIngestionArtifactStorage(
            new ConfigRepository([
                'temporal' => [
                    'storage' => [
                        'shared_root' => $this->sharedRoot,
                    ],
                ],
            ]),
            $this->files,
        );
    }
}
