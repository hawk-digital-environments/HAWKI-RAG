<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authorization\Repositories\QueryCutoverInventoryRepository;
use App\Services\Dataset\Repositories\DatasetGraphAvailabilityRepository;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

class QueryCutoverExportCommand extends Command
{
    protected $signature = 'query:cutover-export {--check-key : Inspect the mounted private key without printing it}';

    protected $description = 'Export read-only effective query migration metadata, without credentials.';

    public function handle(QueryCutoverInventoryRepository $inventory, DatasetGraphAvailabilityRepository $graphs, SettingsService $settings): int
    {
        try {
            $datasets = [];
            $models = [];
            foreach ($inventory->datasets() as $dataset) {
                $row = $dataset->getAttributes();
                $row['graph_enabled'] = $dataset->status === 'active'
                    && trim((string) $dataset->neo4j_namespace) !== ''
                    && $graphs->hasReadyGraph($dataset->dataset_id);
                $datasets[] = $row;
                if ($dataset->status === 'active') {
                    $runtime = $settings->modelRuntimeForProvider((string) $dataset->embedding_provider);
                    $models[$runtime['provider']] = [
                        'chat_model' => $runtime['graph_model'],
                        'vision_model' => $runtime['vision_model'],
                    ];
                }
            }
            ksort($models);
            $bff = config('config.query_bff');
            unset($bff['private_key_path']);
            $pythonUrl = config('config.python_query_url');
            $parts = is_string($pythonUrl) ? parse_url($pythonUrl) : false;
            if ($parts === false || preg_match('/[\x00-\x20\x7f]/', $pythonUrl)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || ($parts['path'] ?? '') !== '/v1/query'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \RuntimeException('Invalid Python query destination.');
            }
            $bridgeBase = config('config.hawki_rag_bridge_url');
            $bridgeParts = is_string($bridgeBase) ? parse_url($bridgeBase) : false;
            if ($bridgeParts === false || preg_match('/[\x00-\x20\x7f]/', $bridgeBase)
                || ! in_array($bridgeParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($bridgeParts['host']) || isset($bridgeParts['user']) || isset($bridgeParts['pass'])
                || trim((string) ($bridgeParts['path'] ?? ''), '/') !== ''
                || isset($bridgeParts['query']) || isset($bridgeParts['fragment'])
                || str_contains($bridgeBase, '\\') || str_contains($bridgeBase, '%')) {
                throw new \RuntimeException('Invalid legacy bridge destination.');
            }
            // Match RagProxyService::queryEndpoint(), the actual rollback route.
            $legacyBridgeQueryUrl = rtrim($bridgeBase, '/').'/query';
            $report = [
                'version' => 1,
                'generated_at' => time(),
                'tenant' => config('config.query_bff.tenant'),
                'python_query_url' => $pythonUrl,
                'legacy_bridge_query_url' => $legacyBridgeQueryUrl,
                'key_forbidden_paths' => [public_path(), storage_path(), config('config.shared_root')],
                'backend' => config('config.query_backend', 'laravel'),
                'query_all' => config('config.query_auth.all_datasets_by_default'),
                'auth_default_guard' => config('auth.defaults.guard'),
                'auth_guard_drivers' => array_map(fn ($guard) => $guard['driver'] ?? null, config('auth.guards', [])),
                'datasets' => $datasets,
                'models' => $models,
                'bff' => $bff,
                'key' => $this->option('check-key') ? $this->keyReport() : null,
            ];
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Cutover export failed. Check inventory, effective models and mounted signing key. No configuration was changed.');

            return self::FAILURE;
        }
    }

    /** @return array{bits: int, fingerprint: string, mount_path: string} */
    private function keyReport(): array
    {
        $path = config('config.query_bff.private_key_path');
        if (! is_string($path) || ! is_readable($path)) {
            throw new \RuntimeException('Signing key unavailable.');
        }
        $key = openssl_pkey_get_private(file_get_contents($path));
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new \RuntimeException('Invalid signing key.');
        }
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return ['bits' => $details['bits'], 'fingerprint' => hash('sha256', $encode($details['rsa']['n']).':'.$encode($details['rsa']['e'])), 'mount_path' => realpath($path)];
    }
}
