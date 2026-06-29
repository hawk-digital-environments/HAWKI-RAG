<?php

declare(strict_types=1);

namespace App\Services\Scrape\Tasks;

use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ScrapeTaskStartPayloadBuilder
{
    public function __construct(
        private ScrapeTaskProfileNormalizer $profiles,
        private ScrapeTaskValueNormalizer $values,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function build(array $task, array $profile, array $options): array
    {
        $firstHost = $this->profiles->firstEntrypoint($profile, 'host');
        $firstSitemap = $this->profiles->firstEntrypoint($profile, 'sitemap');
        $jobId = $this->values->firstScalar([$options['job_id'] ?? null, $options['jobId'] ?? null])
            ?? ((string) $task['profileId']).'_'.$this->clock->now()->format('Uv');

        $payload = [
            'job_id' => $jobId,
            'output_dir' => 'output',
            'site_profile_path' => $profile['containerPath'],
            'rescrape_failed' => $profile['rescrape_failed'],
            'sitemap' => $firstSitemap !== null,
            'max_pages' => $profile['max_pages'],
            'max_concurrency' => $profile['max_concurrency'],
            'max_rpm' => $profile['max_rpm'],
            'skip_images' => $profile['skip_images'],
            'max_images_per_page' => $profile['max_images_per_page'],
            'max_link_density' => $profile['max_link_density'],
            'discovery_mode' => $profile['discovery_mode'],
        ];

        if (($profile['wait_until'] ?? null) !== null) {
            $payload['wait_until'] = $profile['wait_until'];
        }

        if (($profile['page_timeout_ms'] ?? null) !== null) {
            $payload['page_timeout_ms'] = $profile['page_timeout_ms'];
        }

        if ($firstHost !== null) {
            $payload['url'] = 'https://'.$firstHost;
        } elseif ($firstSitemap !== null) {
            $payload['url'] = $firstSitemap;
        }

        if ($firstSitemap !== null) {
            $payload['sitemap_base'] = $firstSitemap;
        }

        return ['jobId' => $jobId, 'payload' => $payload];
    }
}
