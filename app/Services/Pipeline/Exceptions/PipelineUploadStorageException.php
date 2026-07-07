<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

class PipelineUploadStorageException extends \RuntimeException implements PipelineExceptionInterface
{
    /**
     * @param array<string, mixed> $logContext
     */
    private function __construct(
        string $message,
        private readonly string $responseMessage,
        private readonly string $logMessage,
        private readonly array $logContext,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function directoryNotWritable(string $taskRoot, ?\Throwable $previous = null): self
    {
        return new self(
            "Upload task directory is not writable: {$taskRoot}",
            'The upload storage path is not writable. No heap, task, or job was created.',
            'Pipeline controller could not prepare upload storage.',
            ['task_root' => $taskRoot],
            $previous,
        );
    }

    public static function moveFailed(string $taskRoot, string $targetName, \Throwable $previous): self
    {
        return new self(
            'Uploaded file could not be moved into pipeline storage: ' . $previous->getMessage(),
            'The uploaded file could not be stored. No heap, task, or job was created.',
            'Pipeline controller could not move uploaded file.',
            [
                'task_root' => $taskRoot,
                'target_name' => $targetName,
            ],
            $previous,
        );
    }

    public function responseMessage(): string
    {
        return $this->responseMessage;
    }

    public function logMessage(): string
    {
        return $this->logMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return $this->logContext;
    }
}
