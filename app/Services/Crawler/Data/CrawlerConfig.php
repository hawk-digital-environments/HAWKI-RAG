<?php

namespace App\Services\Crawler\Data;

class CrawlerConfig
{
    public function __construct(
        public readonly string $url,
        public readonly int $maxPages,
        public readonly string $outputDir,
        public readonly string $label,
        public readonly bool $skipImages,
        public readonly int $startFromIndex,
        public readonly array $incompleteDirectories,
        public readonly array $emptyDirectoriesToReuse,
        public readonly string $sourceType,
        public readonly ?array $imageExceptions = null,
        public readonly ?string $dateSelector = null,
        public readonly ?array $urls = null,
        public readonly bool $isLocalFile = false,
        public readonly ?int $continueOffset = null,
    ) {}

    public function toArray(): array
    {
        $config = [
            'url' => $this->url,
            'maxPages' => $this->maxPages,
            'outputDir' => $this->outputDir,
            'label' => $this->label,
            'skipImages' => $this->skipImages,
            'startFromIndex' => $this->startFromIndex,
            'incompleteDirectories' => $this->incompleteDirectories,
            'emptyDirectoriesToReuse' => $this->emptyDirectoriesToReuse,
            'sourceType' => $this->sourceType,
        ];

        if ($this->imageExceptions !== null) {
            $config['imageExceptions'] = $this->imageExceptions;
        }

        if ($this->dateSelector !== null) {
            $config['dateSelector'] = $this->dateSelector;
        }

        if ($this->urls !== null) {
            $config['urls'] = $this->urls;
            $config['isLocalFile'] = true;
        }

        if ($this->continueOffset !== null) {
            $config['continueOffset'] = $this->continueOffset;
        }

        return $config;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
