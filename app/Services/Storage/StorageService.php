<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\StorageReadException;
use Illuminate\Contracts\Filesystem\Filesystem;


/**
 *  helper function to access stored files
 */
class StorageService
{
    public function __construct(
        protected Filesystem $filesystem,
        protected UrlGenerator $urlGenerator
    )
    {

    }

    /*----------------------
               JOBS
    -----------------------*/
    /**
     * Retrieves markdown content
     * @param string $id job id
     * @param string $type Type of report: job_state / summary / urls_tracking
     * @return array json to array
     * @throws StorageReadException
     */
    public function fetchJobReport(string $id, string $type): array{
        $folder = $this->buildFolder($id);
        $path = $folder. '/' . $type . '.json';

        if (!$this->filesystem->exists($path)) {
            throw StorageReadException::jobReportNotFound($path);
        }

        $json = $this->filesystem->get($path);

        if (!$json) {
            throw StorageReadException::jobReportUnreadable($path);
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw StorageReadException::invalidJobReportJson($path, json_last_error_msg());
        }
        return $data;
    }

    /**
     * Fetch and combine urls index chunks.
     * @param string $id
     * @return array
     * @throws StorageReadException
     */
    public function fetchUrlsList(string $id): array{
        $folder = $this->buildFolder($id) . '/url_chunks';

        if (!$this->filesystem->exists($folder)) {
            throw StorageReadException::urlChunksFolderNotFound($folder);
        }

        $files = $this->filesystem->files($folder);

        if (empty($files)) {
            throw StorageReadException::urlChunksEmpty($folder);
        }

        $urls = [];
        foreach ($files as $file) {
            $json = $this->filesystem->get($file);
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw StorageReadException::invalidUrlChunkJson((string) $file, json_last_error_msg());
            }

            // The data is an object with URLs as keys, convert to array of values
            foreach ($data as $url => $urlData) {
                $urls[] = $urlData;
            }
        }

        return $urls;
    }


    /*----------------------
            ELEMENTS
    -----------------------*/
    /**
     * Retrieves markdown content
     * @param string $id job id
     * @param string $urlHash url hash
     * @return string text
     */
    public function fetchElementContent(string $id, string $urlHash): string{
        $folder = $this->buildFolder($id , $urlHash);
        return $this->filesystem->get($folder. '/' . 'content.md');
    }

    /**
     * Retrieves markdown content
     * @param string $id job id
     * @param string $urlHash url hash
     * @return array text
     * @throws StorageReadException
     */
    public function fetchElementData(string $id, string $urlHash): array
    {
        $folder = $this->buildFolder($id , $urlHash);
        $path = $folder. '/' . 'data.json';

        if (!$this->filesystem->exists($path)) {
            throw StorageReadException::elementDataNotFound($path);
        }

        $json = $this->filesystem->get($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw StorageReadException::invalidElementDataJson($path, json_last_error_msg());
        }

        return $data;
    }

    /**
     * Retrieves markdown content
     * @param string $id job id
     * @param string $urlHash url hash
     * @return array files
     */
    public function fetchImages(string $id, string $urlHash): array
    {
        $folder = $this->buildFolder($id , $urlHash);
        $path = $folder. '/images';

        if (!$this->filesystem->exists($path)) {
            return [];
        }

        return $this->filesystem->files($path);
    }


    public function getUrl(string $id, string $urlHash, string $name, ?string $type = null): ?string
    {
        $folder = $this->buildFolder($id , $urlHash);
        if($type){
           $folder = $folder . '/' . $type;
        }

        if(!$this->filesystem->exists($folder. '/' . $name)){
            return null;
        }

        $path = $folder . '/' . $name;
        return $this->urlGenerator->generate($path);
    }

    protected function buildFolder(string $id, ?string $urlHash = null): string{
        if($urlHash !== null){
            $subStr = str_split(substr($urlHash, 0, 4), 1);
            $dir = join('/', $subStr);
            return $id . '/' . $dir . '/' . $urlHash;
        }
        else{
            return $id;
        }
    }

}
