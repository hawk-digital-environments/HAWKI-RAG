<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Pipeline\PipelineWorkerEventSignatureVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class VerifyPipelineWorkerSignature
{
    public function __construct(private PipelineWorkerEventSignatureVerifier $signatures) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $this->signatures->verify(
            (string) $request->getContent(),
            $request->headers->get('X-Hawki-Timestamp'),
            $request->headers->get('X-Hawki-Signature'),
        );

        return $next($request);
    }
}
