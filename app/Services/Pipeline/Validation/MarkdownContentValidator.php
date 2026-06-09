<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class MarkdownContentValidator
{
    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(?string $content): array
    {
        $errors = [];
        $warnings = [];

        if ($content === null) {
            $errors[] = 'Markdown content is not readable.';

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            $errors[] = 'Markdown content is empty.';
        }

        if ($trimmed !== '' && strlen($trimmed) < 20) {
            $warnings[] = 'Markdown content is very short.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
