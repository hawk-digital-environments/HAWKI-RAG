<?php

namespace App\Console\Commands\Communication;

use App\Services\CommunicationService\CommunicationServiceFactory;
use Illuminate\Console\Command;

/**
 * Listen to Messages Command
 *
 * Generic command for starting a message listener using any
 * configured communication service (Redis, RabbitMQ, etc.)
 *
 * Usage:
 * php artisan communication:listen --service=redis --channels=channel1,channel2
 * php artisan communication:listen --service=rabbitmq --channels=queue1,queue2
 */
class ListenToMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'communication:listen
                            {--service= : Communication service type (redis, rabbitmq). Defaults to config value}
                            {--channels= : Comma-separated list of channels/queues to subscribe to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start listening to messages from a communication service (Redis, RabbitMQ, etc.)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $serviceType = $this->option('service') ?? config('communication.default');
        $channelsOption = $this->option('channels');

        // Determine channels based on service type
        if ($channelsOption) {
            $channels = array_map('trim', explode(',', $channelsOption));
        } else {
            $channels = $this->getDefaultChannels($serviceType);
        }

        if (empty($channels)) {
            $this->error('No channels/queues specified. Use --channels option or set default in config.');
            return Command::FAILURE;
        }

        try {
            // Create the message listener using the factory
            $listener = CommunicationServiceFactory::create($serviceType);

            // Set up output callback to display messages in the console
            $listener->setOutputCallback(function (string $message, string $type) {
                match ($type) {
                    'info' => $this->info($message),
                    'error' => $this->error($message),
                    'line' => $this->line($message),
                    default => $this->line($message),
                };
            });

            $this->info("Starting {$listener->getName()} listener...");

            // Start listening
            $exitCode = $listener->subscribe($channels);

            return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;

        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('');
            $this->info('Available service types: ' . implode(', ', CommunicationServiceFactory::getAvailableTypes()));
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get default channels for a service type.
     *
     * @param string $serviceType
     * @return array
     */
    protected function getDefaultChannels(string $serviceType): array
    {
        return match ($serviceType) {
            'redis' => [config('communication.redis.default_channel', 'scrape-events')],
            'rabbitmq' => [config('communication.rabbitmq.default_queue', 'scrape-events')],
            default => [],
        };
    }
}
