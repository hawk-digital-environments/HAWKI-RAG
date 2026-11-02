<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Authorization\Repositories\QueryCutoverInventoryRepository;
use App\Services\Rag\Clients\PythonQueryClient;
use App\Services\Rag\RagProxyService;
use Illuminate\Console\Command;

class QueryCutoverProbeCommand extends Command
{
    protected $signature = 'query:cutover-probe {user} {dataset} {--query=} {--graph} {--legacy}';

    protected $description = 'Operator-only non-generating query smoke probe; does not change the backend selector.';

    public function handle(QueryCutoverInventoryRepository $inventory, PythonQueryClient $python, RagProxyService $legacy): int
    {
        try {
            $user = $inventory->activeUser((string) $this->argument('user'));
            if ($user === null) {
                throw new \RuntimeException('Unknown active user.');
            }
            $data = [
                'dataset_id' => (string) $this->argument('dataset'),
                'query' => $this->option('query') ?: 'handbook policy',
                'generate' => false, 'top_k' => 5, 'fast_mode' => ! $this->option('graph'),
            ];
            $result = $this->option('legacy') ? $legacy->query($user, $data) : $python->query($user, $data);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 200 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Query probe failed. No selector or policy was changed.');

            return self::FAILURE;
        }
    }
}
