<?php

declare(strict_types=1);

namespace App\Services\Pipeline\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;

#[Singleton]
readonly class DirectIngestCollectionProbe
{
    public function exists(string $collection): bool
    {
        $collection = trim($collection);
        if ($collection === '') {
            return false;
        }

        $baseUrl = rtrim((string) config('config.qdrant_http_url', 'http://qdrant:6333'), '/');
        try {
            $response = Http::timeout(3)->get($baseUrl.'/collections');
            if (! $response->successful()) {
                return false;
            }

            $data = $response->json();
            foreach (($data['result']['collections'] ?? []) as $candidate) {
                if (($candidate['name'] ?? null) === $collection) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
