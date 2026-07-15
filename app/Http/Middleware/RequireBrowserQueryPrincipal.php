<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Authorization\BrowserQueryPrincipalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class RequireBrowserQueryPrincipal
{
    public function __construct(
        private BrowserQueryPrincipalService $principals,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->principals->resolve($request) === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
