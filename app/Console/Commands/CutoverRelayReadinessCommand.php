<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\VerifyPipelineWorkerSignature;
use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

/** Explicit operator read: never publishes callbacks or changes ownership. */
class CutoverRelayReadinessCommand extends Command
{
    protected $signature = 'rag:cutover-relay-readiness';

    protected $description = 'Inspect effective callback destinations from the Laravel network.';

    public function handle(Repository $config, Factory $http, QueryTokenSigner $signer): int
    {
        $checks = [];
        $secret = (string) $config->get('temporal.callbacks.secret');
        $checks['callback_auth'] = $secret !== '' && (int) $config->get('temporal.callbacks.max_age_seconds') > 0 && (int) $config->get('temporal.callbacks.max_age_seconds') <= 3600 ? 'PASS' : 'FAIL';
        foreach (['worker-events', 'managed-writers', 'managed-deletion-events'] as $family) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/internal/pipeline/'.$family && in_array('POST', $route->methods(), true));
            $checks['ingress.'.$family] = $route && in_array(VerifyPipelineWorkerSignature::class, $route->gatherMiddleware(), true) ? 'PASS' : 'FAIL';
        }
        foreach (['text_ingestion' => ['python_text_ingestion_events_url', '/internal/text-ingestion/events'], 'url_ingestion' => ['python_url_ingestion_url', ''], 'managed_document' => ['python_managed_document_url', '']] as $owner => [$key, $expectedPath]) {
            try {
                $base = $this->origin((string) $config->get('config.'.$key), $expectedPath);
                if ($base !== $this->origin((string) $config->get('temporal.control_url'), '')) {
                    throw new \RuntimeException('Relay destination differs from control authority.');
                }
                $path = '/internal/workflow-control/relay-readiness';
                $raw = json_encode(['family' => $owner], JSON_THROW_ON_ERROR);
                $stamp = (string) time();
                $signature = hash_hmac('sha256', "hawki-workflow-control-v1\nPOST\n{$path}\n{$stamp}\n".$raw, $secret);
                $response = $http->timeout(5)->connectTimeout(3)->withoutRedirecting()->withHeaders(['X-Hawki-Timestamp' => $stamp, 'X-Hawki-Signature' => 'v1='.$signature])->withBody($raw, 'application/json')->post($base.$path);
                $checks['relay.'.$owner] = $secret !== '' && $response->successful() && $response->json() === ['ready' => true, 'family' => $owner] ? 'PASS' : 'FAIL';
            } catch (\Throwable) {
                $checks['relay.'.$owner] = 'FAIL';
            }
        }
        try {
            $base = $this->origin((string) $config->get('config.python_query_url'), '/v1/query');
            if ($base !== $this->origin((string) $config->get('temporal.control_url'), '')) {
                throw new \RuntimeException('Query destination differs from control authority.');
            }
            $response = $http->timeout(5)->connectTimeout(3)->withoutRedirecting()->withToken($signer->signDatasetAdmin())->get($base.'/v1/readiness/identity');
            $checks['bff_identity'] = $response->successful() && $response->json() === ['tenant_id' => $config->get('config.query_bff.tenant'), 'client_id' => $config->get('config.query_bff.client_id')] ? 'PASS' : 'FAIL';
        } catch (\Throwable) {
            $checks['bff_identity'] = 'FAIL';
        }
        $this->line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));

        return in_array('FAIL', $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function origin(string $url, string $path): string
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== $path || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \RuntimeException('Invalid internal destination.');
        }

        return substr($url, 0, strlen($url) - strlen($path));
    }
}
