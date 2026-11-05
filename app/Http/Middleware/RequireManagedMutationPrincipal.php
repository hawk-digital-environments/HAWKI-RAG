<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Authorization\ManagedMutationPrincipalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireManagedMutationPrincipal
{
    public function __construct(private ManagedMutationPrincipalService $principals) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->principals->resolve($request) === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
