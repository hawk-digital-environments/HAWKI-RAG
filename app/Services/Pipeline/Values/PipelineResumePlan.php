<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

readonly class PipelineResumePlan
{
    public function __construct(
        public PipelineStage $stage,
        public ?string $rawDir = null,
        public ?string $markdownDir = null,
    ) {}

    /** @return array{stage: string, raw_dir?: string, markdown_dir?: string} */
    public function toArray(): array
    {
        $resume = ['stage' => $this->stage->value];

        if ($this->rawDir !== null) {
            $resume['raw_dir'] = $this->rawDir;
        }
        if ($this->markdownDir !== null) {
            $resume['markdown_dir'] = $this->markdownDir;
        }

        return $resume;
    }
}
