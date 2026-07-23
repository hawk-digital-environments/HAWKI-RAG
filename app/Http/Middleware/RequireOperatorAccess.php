<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Profile\OperatorAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class RequireOperatorAccess
{
    public function __construct(private OperatorAccessService $access) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->access->allows($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Operator authentication required.',
            ], 401);
        }

        return response('Operator authentication required.', 401);
    }
}
