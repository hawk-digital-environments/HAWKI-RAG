<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class StorageService
{
    public function __construct(
        private StorageJobReportReader $jobs,
        private StorageElementReader $elements,
    ) {
    }

    public function fetchJobReport(string $id, string $type): array
    {
        return $this->jobs->fetchJobReport($id, $type);
    }

    public function fetchUrlsList(string $id): array
    {
        return $this->jobs->fetchUrlsList($id);
    }

    public function fetchElementContent(string $id, string $urlHash): string
    {
        return $this->elements->fetchElementContent($id, $urlHash);
    }

    public function fetchElementData(string $id, string $urlHash): array
    {
        return $this->elements->fetchElementData($id, $urlHash);
    }

    public function fetchImages(string $id, string $urlHash): array
    {
        return $this->elements->fetchImages($id, $urlHash);
    }

    public function getUrl(string $id, string $urlHash, string $name, ?string $type = null): ?string
    {
        return $this->elements->getUrl($id, $urlHash, $name, $type);
    }
}
