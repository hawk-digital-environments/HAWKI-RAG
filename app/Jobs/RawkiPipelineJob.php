<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class RawkiPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(private array $payload)
    {
    }

    public function handle(): void
    {
        $statusPath = (string) config('rawki.pipeline_status_path', storage_path('logs/pipeline_status.json'));
        File::ensureDirectoryExists(dirname($statusPath));

        $status = [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'payload' => $this->payload,
        ];
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $args = [
            'url' => $this->payload['url'],
            '--max-pages' => $this->payload['max_pages'] ?? 100,
            '--output-dir' => $this->payload['output_dir'] ?? null,
            '--label' => $this->payload['label'] ?? null,
            '--collection' => $this->payload['collection'] ?? null,
            '--image-exceptions' => $this->payload['image_exceptions'] ?? null,
            '--date' => $this->payload['date'] ?? null,
            '--provider' => $this->payload['provider'] ?? 'ollama',
            '--graph-engine' => $this->payload['graph_engine'] ?? 'lightrag',
            '--distance' => $this->payload['distance'] ?? 'Cosine',
            '--chunk-chars' => $this->payload['chunk_chars'] ?? 3200,
            '--chunk-overlap' => $this->payload['chunk_overlap'] ?? 100,
            '--batch' => $this->payload['batch'] ?? 64,
            '--timeout' => $this->payload['timeout'] ?? 1800,
            '--base-url' => $this->payload['base_url'] ?? env('RAWKI_BRIDGE_URL', 'http://rawki_bridge:8000'),
        ];
        if (!empty($this->payload['skip_images'])) {
            $args['--skip-images'] = true;
        }
        if (!empty($this->payload['graph'])) {
            $args['--graph'] = true;
        }

        $code = Artisan::call('rawki:pipeline', array_filter($args, static fn ($v) => $v !== null));

        $status['status'] = $code === 0 ? 'completed' : 'failed';
        $status['updated_at'] = now()->toIso8601String();
        $status['exit_code'] = $code;
        File::put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
