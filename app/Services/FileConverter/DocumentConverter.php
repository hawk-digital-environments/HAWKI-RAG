<?php

namespace App\Services\FileConverter;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class DocumentConverter
{

    function requestDocumentToMarkdown($file)
    {
        if ($file instanceof UploadedFile) {
            $realPath = $file->getRealPath();
            $resource = $realPath ? @fopen($realPath, 'r') : false;
            $filename = $file->getClientOriginalName();
        } elseif ($file instanceof \SplFileInfo) {
            $resource = @fopen($file->getPathname(), 'r');
            $filename = $file->getFilename();
        } else {
            throw new \InvalidArgumentException("Invalid file input. Expected UploadedFile or SplFileInfo.");
        }

        if ($resource === false) {
            throw new \RuntimeException("Unable to open document for conversion: {$filename}");
        }

        $request = Http::timeout((int) config('file_converter.timeout', 300))
            ->connectTimeout((int) config('file_converter.connect_timeout', 10));

        if ($token = config('file_converter.token')) {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->attach(
                'file',
                $resource,
                $filename
            )->post(config('file_converter.url'));
        } finally {
            fclose($resource);
        }

        if (!$response->successful()) {
            throw new \Exception('Document extraction failed: ' . $response->body());
        }

        // Unzip files from response
        $zipContent = $response->body();
        $extractDir = sys_get_temp_dir() . '/document_extract_' . uniqid();
        if (!mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $extractDir));
        }

        try {
            $this->unzipContent($zipContent, $extractDir);

            // Optionally, read all extracted files and return as array [relative_path => file_content]
            $files = [];
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
            foreach ($rii as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $relativePath = substr($fileinfo->getPathname(), strlen($extractDir) + 1);
                    $files[$relativePath] = file_get_contents($fileinfo->getPathname());
                }
            }

            return $files;
        } finally {
            $this->deleteDirectory($extractDir);
        }
    }

    private function unzipContent($zipContent, $extractToDirectory)
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'unzipped_') . '.zip';
        file_put_contents($tmpZip, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) === true) {
            $zip->extractTo($extractToDirectory);
            $zip->close();
            unlink($tmpZip);
            return true;
        } else {
            unlink($tmpZip);
            throw new \Exception("Failed to open ZIP file.");
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
