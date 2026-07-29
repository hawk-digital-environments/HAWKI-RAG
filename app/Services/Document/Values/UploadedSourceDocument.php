<?php

declare(strict_types=1);

namespace App\Services\Document\Values;

readonly class UploadedSourceDocument
{
    public function __construct(
        public string $path,
        public string $downloadName,
    ) {
    }
}
