<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

readonly class RequireOperatorAccess
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->localBypassIsAllowed()) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('sanctum')->user();
        if ($user !== null && ! (bool) ($user->isRemoved ?? false)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('query', 'settings/config', 'rag/*', 'pipeline/*', 'scraper/*', 'datasets/data*', 'documents/data*')) {
            return response()->json([
                'message' => 'Operator authentication required.',
            ], 401);
        }

        return response('Operator authentication required.', 401);
    }

    private function localBypassIsAllowed(): bool
    {
        if (! filter_var($this->config->get('config.operator_auth.bypass', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $environments = $this->config->get('config.operator_auth.bypass_environments', ['local', 'testing']);
        if (! is_array($environments)) {
            $environments = preg_split('/[\s,]+/', (string) $environments) ?: [];
        }

        $environments = array_values(array_filter(array_map(
            static fn (mixed $environment): string => strtolower(trim((string) $environment)),
            $environments,
        )));

        return app()->environment($environments);
    }
}
