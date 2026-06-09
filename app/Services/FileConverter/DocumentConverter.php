<?php

namespace App\Services\FileConverter;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class DocumentConverter
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function requestDocumentToMarkdown(UploadedFile|\SplFileInfo $file): array
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

        $request = $this->http->timeout((int) $this->config->get('file_converter.timeout', 300))
            ->connectTimeout((int) $this->config->get('file_converter.connect_timeout', 10))
            ->accept('application/zip');

        if ($token = $this->config->get('file_converter.token')) {
            $authHeader = strtolower((string) $this->config->get('file_converter.auth_header', 'bearer'));
            $request = in_array($authHeader, ['x-api-key', 'x_api_key', 'api-key', 'apikey'], true)
                ? $request->withHeaders(['X-API-KEY' => $token])
                : $request->withToken($token);
        }

        try {
            $response = $request->attach(
                'file',
                $resource,
                $filename
            )->post($this->config->get('file_converter.url'));
        } finally {
            fclose($resource);
        }

        if (!$response->successful()) {
            throw new \Exception(sprintf(
                'Document extraction failed with HTTP %s: %s',
                $response->status(),
                $response->body(),
            ));
        }

        // Unzip files from response
        $zipContent = $response->body();
        $extractDir = $this->temporaryExtractDirectory();
        $this->files->ensureDirectoryExists($extractDir, 0700, true);

        try {
            $this->unzipContent($zipContent, $extractDir);

            // Optionally, read all extracted files and return as array [relative_path => file_content]
            $files = [];
            $finder = Finder::create()
                ->files()
                ->ignoreUnreadableDirs()
                ->in($extractDir);

            foreach ($finder as $fileinfo) {
                $relativePath = ltrim(str_replace('\\', '/', substr($fileinfo->getPathname(), strlen($extractDir))), '/');
                $files[$relativePath] = (string) $this->files->get($fileinfo->getPathname());
            }

            return $files;
        } finally {
            $this->files->deleteDirectory($extractDir);
        }
    }

    private function unzipContent(string $zipContent, string $extractToDirectory): bool
    {
        $tmpZip = $this->temporaryZipPath();
        $this->files->put($tmpZip, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) === true) {
            $zip->extractTo($extractToDirectory);
            $zip->close();
            $this->files->delete($tmpZip);
            return true;
        } else {
            $this->files->delete($tmpZip);
            throw new \Exception("Failed to open ZIP file.");
        }
    }

    private function temporaryExtractDirectory(): string
    {
        return $this->temporaryRoot() . DIRECTORY_SEPARATOR . 'document_extract_' . (string) Str::uuid();
    }

    private function temporaryZipPath(): string
    {
        $this->files->ensureDirectoryExists($this->temporaryRoot(), 0700, true);

        return $this->temporaryRoot() . DIRECTORY_SEPARATOR . 'unzipped_' . (string) Str::uuid() . '.zip';
    }

    private function temporaryRoot(): string
    {
        return rtrim((string) $this->config->get(
            'file_converter.temp_dir',
            storage_path('framework/cache/file-converter')
        ), DIRECTORY_SEPARATOR);
    }
}
