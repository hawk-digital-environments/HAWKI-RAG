<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Profile\AdminAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class RequireAdminAccess
{
    public function __construct(private AdminAccessService $access) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->access->allows($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Admin authentication required.',
            ], 401);
        }

        return response('Admin authentication required.', 401);
    }
}
