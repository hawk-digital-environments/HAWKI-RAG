<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeElementValidator
{
    public function __construct(
        private PipelineValidationValueNormalizer $values,
    ) {
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(array $data): array
    {
        $errors = [];
        $warnings = [];

        $pageUrl = $this->values->firstScalar($data['page_url'] ?? null);
        $title = $this->values->firstScalar($data['title'] ?? null);
        $urlHash = $this->values->firstScalar($data['url_hash'] ?? null);

        if ($pageUrl === null || ! filter_var($pageUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'page_url is missing or invalid.';
        }

        if ($urlHash === null) {
            $errors[] = 'url_hash is missing.';
        }

        if ($title === null) {
            $warnings[] = 'title is missing.';
        }

        if (! array_key_exists('content_hash', $data) || $this->values->firstScalar($data['content_hash']) === null) {
            $warnings[] = 'content_hash is missing.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
