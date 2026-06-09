<?php

namespace App\Services\Profile;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Laravel\Sanctum\NewAccessToken;

class ApiTokenService{

    public function createApiToken(string $name): NewAccessToken{
        /** @var User $user */
        $user = Auth::user();
        return $user->createToken($name);
    }


    public function fetchTokenList(): Collection{
        /** @var User $user */
        $user = Auth::user();
        // Retrieve all tokens associated with the authenticated user
        $tokens = $user->tokens()->get();
        // Construct an array of token data
        return $tokens->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
            ];
        });
    }


    public function revokeToken(int $tokenId): void{
        try{
            /** @var User $user */
            $user = Auth::user();
            $token = $user->tokens()->where('id', $tokenId);
            $token->delete();
        }
        catch(Exception $e){
            Log::error($e->getMessage());
            throw $e;
        }

    }

}
