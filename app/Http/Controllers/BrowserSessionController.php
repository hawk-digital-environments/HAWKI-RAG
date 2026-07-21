<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Authorization\BrowserQueryPrincipalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class BrowserSessionController extends Controller
{
    public function store(Request $request, BrowserQueryPrincipalService $principals): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user instanceof User ? $user->currentAccessToken() : null;

        if (
            ! $user instanceof User
            || $user->cannot('access-query-principal')
            || ! $accessToken instanceof PersonalAccessToken
            || ! $accessToken->can('query')
        ) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $principals->establishSession($request, $user);

        return response()->json(['authenticated' => true]);
    }
}
