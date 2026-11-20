<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PipelineRetryException extends \RuntimeException implements PipelineExceptionInterface
{
    public static function unavailableArtifact(string $stage): self
    {
        return new self("Cannot resume {$stage}: the completed stage's output directory is missing, empty, unreadable, or outside shared storage. Restore its artifacts or start a new pipeline task.");
    }

    public static function incompleteStage(string $stage): self
    {
        return new self("Cannot resume after {$stage}: this stage has no recorded successful completion. Start a new pipeline task if its output cannot be recovered.");
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $this->getMessage()], 409);
    }
}
