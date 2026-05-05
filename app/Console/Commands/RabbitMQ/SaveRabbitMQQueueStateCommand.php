<?php

namespace App\Console\Commands\RabbitMQ;

use App\Models\RabbitMQQueueState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SaveRabbitMQQueueStateCommand extends Command
{
    protected $signature = 'rabbitmq:save-queue-state';

    protected $description = 'Snapshot RabbitMQ queue counters into MariaDB for Adminer/operator visibility.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('communication.rabbitmq.management_url', 'http://rabbitmq:15672'), '/');
        $vhost = rawurlencode((string) config('communication.rabbitmq.vhost', '/'));
        $user = (string) config('communication.rabbitmq.user', 'guest');
        $password = (string) config('communication.rabbitmq.password', 'guest');

        $response = Http::withBasicAuth($user, $password)
            ->acceptJson()
            ->timeout(15)
            ->get("{$baseUrl}/api/queues/{$vhost}");

        if ($response->failed()) {
            $this->error("RabbitMQ management API returned HTTP {$response->status()}.");
            return Command::FAILURE;
        }

        $sampledAt = Carbon::now();
        $saved = 0;

        foreach ($response->json() ?? [] as $queue) {
            if (!is_array($queue) || empty($queue['name'])) {
                continue;
            }

            RabbitMQQueueState::query()->updateOrCreate(
                ['queue_name' => (string) $queue['name']],
                [
                    'messages_ready' => (int) ($queue['messages_ready'] ?? 0),
                    'messages_unacknowledged' => (int) ($queue['messages_unacknowledged'] ?? 0),
                    'messages_total' => (int) ($queue['messages'] ?? 0),
                    'consumers' => (int) ($queue['consumers'] ?? 0),
                    'state' => isset($queue['state']) ? (string) $queue['state'] : null,
                    'sampled_at' => $sampledAt,
                    'updated_at' => $sampledAt,
                ],
            );
            $saved++;
        }

        $this->info("Saved {$saved} RabbitMQ queue snapshot row(s).");

        return Command::SUCCESS;
    }
}
