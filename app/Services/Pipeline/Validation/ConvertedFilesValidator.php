<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ConvertedFilesValidator
{
    public function __construct(
        private MarkdownContentValidator $markdown,
        private PipelinePathSafetyValidator $paths,
    ) {
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(array $files): array
    {
        $errors = [];
        $warnings = [];
        $markdownFiles = 0;

        if ($files === []) {
            $errors[] = 'converter returned no files.';
        }

        foreach ($files as $relative => $content) {
            if (! is_string($relative) || trim($relative) === '') {
                $errors[] = 'converter returned a file with an empty relative path.';

                continue;
            }

            if ($this->paths->leavesRoot($relative)) {
                $errors[] = "converter returned unsafe relative path: {$relative}";

                continue;
            }

            if (str_ends_with(strtolower($relative), '.md')) {
                $markdownFiles++;
                $markdown = $this->markdown->validate(is_string($content) ? $content : null);
                foreach ($markdown['errors'] as $error) {
                    $errors[] = "{$relative}: {$error}";
                }
            }
        }

        if ($markdownFiles === 0) {
            $errors[] = 'converter output does not contain a Markdown file.';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }
}
