<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Authorization\BrowserQueryPrincipalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class RequireBrowserQueryPrincipal
{
    private const SINGLE_USER_ERROR = 'single_user_query_principal_unavailable';

    private const SINGLE_USER_MESSAGE = 'Query access requires exactly one active user.';

    public function __construct(
        private BrowserQueryPrincipalService $principals,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $hasExplicitCredentials = $request->user() !== null
            || $request->headers->has('Authorization');

        if ($this->principals->resolve($request) === null) {
            if ($hasExplicitCredentials) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return response()->json([
                'message' => self::SINGLE_USER_MESSAGE,
                'error' => self::SINGLE_USER_ERROR,
            ], 503);
        }

        return $next($request);
    }
}
