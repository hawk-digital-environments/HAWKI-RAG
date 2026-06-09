<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class DirectIngestCollectionProbe
{
    public function __construct(
        private HttpFactory $http,
        private DirectIngestConfig $config,
    ) {}

    public function exists(string $collection): bool
    {
        $collection = trim($collection);
        if ($collection === '') {
            return false;
        }

        try {
            $response = $this->http->timeout(3)->get($this->config->qdrantHttpUrl().'/collections');
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
