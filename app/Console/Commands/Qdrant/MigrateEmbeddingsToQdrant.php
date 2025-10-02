<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MigrateEmbeddingsToQdrant extends Command
{
    protected $signature = 'migrate:qdrant {--update : Only sync updated embeddings since last sync}';
    protected $description = 'Migrate embeddings from MySQL(Embedding Table) to Qdrantß(Embedding Collection)';

    public function handle()
    {
        /////////////////////////////////// args and params
        $host = config('services.qdrant.host');
        $port = config('services.qdrant.port');
        $collection = config('services.qdrant.collection');
        $url = "{$host}:{$port}/collections/{$collection}/points";
        $batchSize = 250;


        /////////////////////////////////// sync mode 

        // Read last sync time if --update is used
        $lastSync = null;
        if ($this->option('update')) {
            if (Storage::exists('qdrant_last_sync.txt')) {
                $lastSync = Storage::get('qdrant_last_sync.txt');
                $this->info("Syncing only embeddings updated since: $lastSync");
            } else {
                $this->warn("No last sync time found. Syncing everything.");
            }
        }

        // Auto-detect vector size from first valid record
        $sample = DB::table('embeddings')->whereNotNull('embedding')->first();
        $vector = json_decode($sample->embedding, true);
        if (is_string($vector)) $vector = json_decode($vector, true);
        $vectorSize = count($vector);

        // Check if collection exists otherwise we create a new collection from .env file
        $check = Http::get("{$host}:{$port}/collections/{$collection}");
        if ($check->status() === 404) {
            $this->info("Creating collection '{$collection}'...");
            $create = Http::put("{$host}:{$port}/collections/{$collection}", [
                "vectors" => [
                    "size" => $vectorSize,
                    "distance" => "Cosine"
                ]
            ]);
            if (!$create->successful()) {
                $this->error("Failed to create collection: " . $create->body());
                return;
            }
            $this->info("Collection created.");
        } else {
            $this->info("Collection '{$collection}' already exists.");
        }

        // Building base query and connection, mysql embedding table to qdrant embedding collection. 
        $query = DB::table('embeddings')->orderBy('id');
        if ($lastSync) {
            // Check if 'updated_at' exists in the embeddings table <<<<< ask Arian or Ilithya to create an updated_at column during scraping maybe! >>>>>>
            $columns = DB::getSchemaBuilder()->getColumnListing('embeddings');

            if (in_array('updated_at', $columns)) {
                $this->info("Syncing only rows updated since: $lastSync");
                $query->where('updated_at', '>=', $lastSync);
            } else {
                $this->warn("'updated_at' column not found. Running full sync instead.");
            }
        }
        $total = $query->count();
        $this->info("Found {$total} embeddings to sync.");

        if ($total === 0) {
            $this->info("Nothing to sync. Done.");
            return;
        }
        /////////////////////////////////// chunkifying section

        $query->chunk($batchSize, function ($records) use ($url, $vectorSize) {
            $points = [];

            foreach ($records as $record) {
                $vector = json_decode($record->embedding, true);
                if (is_string($vector)) $vector = json_decode($vector, true);

                if (!is_array($vector) || count($vector) !== $vectorSize) {
                    $this->warn("Skipping invalid vector for ID {$record->id}");
                    continue;
                }

                $points[] = [
                    'id' => $record->id,
                    'vector' => $vector,
                    'payload' => [
                        'title' => $record->title,
                        'content' => $record->content,
                        'meta_img_url' => $record->meta_img_url,
                        'page_url' => $record->page_url,
                        'source_url' => $record->source_url,
                        'source_format' => $record->source_format,
                        'date' => $record->date,
                        'tags' => array_filter(explode(',', $record->tags ?? '')),
                        'intermediate_formatting' => $record->intermediate_formatting,
                    ]
                ];
            }

            if (count($points) === 0) {
                $this->warn("No valid points in this batch.");
                return;
            }

            $response = Http::timeout(30)->put($url, ['points' => $points]); /// Direct connection to qdrant from laravel without HTTP 

            if (!$response->successful()) {
                $this->error("Failed to upload batch: " . $response->status());
                try {
                    $error = $response->json();
                    $this->error(json_encode($error, JSON_PRETTY_PRINT));
                } catch (\Exception $e) {
                    $this->line("Error body too large or unreadable.");
                }
            } else {
                $this->info("Uploaded batch of " . count($points));
            }

            unset($points);
        });

        // Save last sync timestamp
        if ($this->option('update')) {
            Storage::put('qdrant_last_sync.txt', now()->toDateTimeString());
            $this->info("Updated last sync time.");
        }

        $this->info("Migration complete.");
    }
}
