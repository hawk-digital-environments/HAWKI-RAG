<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Profile\Values\ApiTokenAbility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireTextIngestionToken
{
    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        if (! in_array(ApiTokenAbility::RagTextIngest->value, $abilities, true)) {
            throw new AuthorizationException('This token cannot ingest text.');
        }

        return $next($request);
    }
}
