<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use Illuminate\Filesystem\Filesystem;
use ZipArchive;

readonly class PipelineSmokeFixtureFactory
{
    public function __construct(private Filesystem $files)
    {
    }

    public function createDocx(string $fixtureDir, string $taskId): string
    {
        $this->files->ensureDirectoryExists($fixtureDir);
        $path = $fixtureDir . DIRECTORY_SEPARATOR . 'hawki-smoke.docx';

        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is required to create the smoke DOCX fixture.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create DOCX fixture at {$path}.");
        }

        $text = 'HAWKI RAG smoke test document. Laravel orchestrates RabbitMQ jobs. '
            . 'The scraper discovers a document, the converter extracts Markdown, and ingestion writes Qdrant points. '
            . "Smoke task {$taskId}.";
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
        $zip->addFromString('word/document.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>{$escaped}</w:t></w:r></w:p>
  </w:body>
</w:document>
XML);
        $zip->close();

        return $path;
    }
}
