<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\User;
use App\Services\Profile\Values\ApiTokenAbility;
use App\Services\User\Values\UserRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate dataset management before the BFF mints an admin token.
 *
 * A dashboard session is accepted only for an administrator. A personal access
 * token must carry the explicit admin ability. The tenant is never taken from
 * the caller; the BFF signs the server-configured tenant and the Python
 * authority derives it from that trusted token.
 */
final readonly class RequireDatasetManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $token = $user->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken && is_array($token->abilities)
            ? $token->abilities
            : [];

        if ($user->role !== UserRole::Admin
            && ! in_array(ApiTokenAbility::Admin->value, $abilities, true)) {
            throw new AuthorizationException('This principal cannot manage datasets.');
        }

        return $next($request);
    }
}
