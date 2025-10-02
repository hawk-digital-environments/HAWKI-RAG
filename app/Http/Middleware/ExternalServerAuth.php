<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ExternalServerAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the configuration data from config/externalservers.php
        $externalServers = config('externalservers');

        if (blank($externalServers)) {
            return response()->json(['message' => 'Access configuration is invalid.'], 500);
        }

        // Get the token from the request and properly parse it
        $bearerToken = $request->bearerToken();
        
        // For development/testing, if token is 'localhost', allow the request
        if ($bearerToken === 'localhost' && !app()->isProduction()) {
            return $next($request);
        }

        // Get the client's IP
        $clientIp = $request->ip();

        // Check if the IP and token match any configured external server
        foreach ($externalServers as $server) {
            if (
                filled(data_get($server, 'ip')) &&
                filled(data_get($server, 'token')) &&
                data_get($server, 'ip') === $clientIp &&
                data_get($server, 'token') === $bearerToken
            ) {
                return $next($request);
            }
        }

        // Log failed attempt for security monitoring
        Log::warning('External server authentication failed', [
            'ip' => $clientIp,
            'token_hash' => $bearerToken ? hash('sha256', $bearerToken) : null,
            'user_agent' => $request->userAgent(),
        ]);

        // If no match is found, deny access
        return response()->json(['message' => 'Access denied.'], 403);
    }
}