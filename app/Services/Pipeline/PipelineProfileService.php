<?php

namespace App\Services\Pipeline;

use App\Models\PipelineProfile;
use Illuminate\Support\Str;

class PipelineProfileService
{
    public function list(int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));

        return PipelineProfile::query()
            ->orderBy('name')
            ->orderBy('profile_id')
            ->limit($limit)
            ->get()
            ->map(fn (PipelineProfile $profile): array => $this->payload($profile))
            ->all();
    }

    public function show(string $profileId): ?array
    {
        $profile = $this->find($profileId);

        return $profile ? $this->payload($profile) : null;
    }

    public function find(string $profileId): ?PipelineProfile
    {
        return PipelineProfile::query()
            ->where('profile_id', $profileId)
            ->first();
    }

    public function create(array $input): PipelineProfile
    {
        $profileId = $this->profileId($input['profile_id'] ?? $input['profileId'] ?? $input['name'] ?? null);

        return PipelineProfile::query()->create($this->attributes($input, $profileId));
    }

    public function update(string $profileId, array $input): ?PipelineProfile
    {
        $profile = $this->find($profileId);
        if (!$profile) {
            return null;
        }

        $profile->forceFill($this->attributes($input, $profile->profile_id, partial: true))->save();

        return $profile->refresh();
    }

    public function applyToTaskInput(array $input): array
    {
        $profileId = $this->stringValue($input['pipeline_profile_id'] ?? $input['pipelineProfileId'] ?? null)
            ?? $this->stringValue($input['profile_id'] ?? $input['profileId'] ?? null);
        if ($profileId === null) {
            return $input;
        }

        $profile = $this->find($profileId);
        if (!$profile) {
            return $input;
        }

        $profileMetadata = is_array($profile->metadata) ? $profile->metadata : [];
        $requestMetadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $graphEnabled = array_key_exists('graph', $requestMetadata)
            ? filter_var($requestMetadata['graph'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $profile->graph_enabled;

        $profileDefaults = [
            'source' => 'pipeline-profile',
            'catalog_task_label' => $profile->name,
            'label' => $profile->name,
            'max_pages' => $profile->max_pages,
            'allowed_file_types' => $profile->allowed_file_types ?? [],
            'graph' => $graphEnabled,
            'rag_ingest_graph' => $graphEnabled,
            'qdrant_collection' => $profile->qdrant_collection,
            'neo4j_namespace' => $profile->neo4j_namespace,
            'pipeline_profile' => $this->payload($profile),
        ];

        return array_merge($input, [
            'profile_id' => $profile->profile_id,
            'dataset_id' => $this->stringValue($input['dataset_id'] ?? $input['datasetId'] ?? null) ?? $profile->profile_id,
            'qdrant_collection' => $this->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null) ?? $profile->qdrant_collection,
            'neo4j_namespace' => $this->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null) ?? $profile->neo4j_namespace,
            'sitemap_url' => $this->stringValue($input['sitemap_url'] ?? $input['sitemapUrl'] ?? null) ?? $profile->sitemap_url,
            'urls' => $this->stringList($input['urls'] ?? []) ?: ($profile->start_urls ?? []),
            'metadata' => array_merge($profileMetadata, $profileDefaults, $requestMetadata),
        ]);
    }

    public function payload(PipelineProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'profileId' => $profile->profile_id,
            'name' => $profile->name,
            'description' => $profile->description,
            'startUrls' => $profile->start_urls ?? [],
            'sitemapUrl' => $profile->sitemap_url,
            'maxPages' => $profile->max_pages,
            'allowedFileTypes' => $profile->allowed_file_types ?? [],
            'graphEnabled' => (bool) $profile->graph_enabled,
            'qdrantCollection' => $profile->qdrant_collection,
            'neo4jNamespace' => $profile->neo4j_namespace,
            'metadata' => $profile->metadata ?? [],
            'createdAt' => $profile->created_at?->format(DATE_ATOM),
            'updatedAt' => $profile->updated_at?->format(DATE_ATOM),
        ];
    }

    private function attributes(array $input, string $profileId, bool $partial = false): array
    {
        $attributes = [];
        $map = [
            'name' => ['name', fn (mixed $value) => $this->stringValue($value)],
            'description' => ['description', fn (mixed $value) => $this->stringValue($value)],
            'start_urls' => ['start_urls', fn (mixed $value) => $this->stringList($value)],
            'sitemap_url' => ['sitemap_url', fn (mixed $value) => $this->stringValue($value)],
            'max_pages' => ['max_pages', fn (mixed $value) => max(1, (int) $value)],
            'allowed_file_types' => ['allowed_file_types', fn (mixed $value) => $this->stringList($value)],
            'graph_enabled' => ['graph_enabled', fn (mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)],
            'qdrant_collection' => ['qdrant_collection', fn (mixed $value) => $this->stringValue($value)],
            'neo4j_namespace' => ['neo4j_namespace', fn (mixed $value) => $this->stringValue($value)],
            'metadata' => ['metadata', fn (mixed $value) => is_array($value) ? $value : []],
        ];

        $aliases = [
            'profile_id' => ['profile_id', 'profileId'],
            'start_urls' => ['start_urls', 'startUrls', 'urls'],
            'sitemap_url' => ['sitemap_url', 'sitemapUrl'],
            'max_pages' => ['max_pages', 'maxPages'],
            'allowed_file_types' => ['allowed_file_types', 'allowedFileTypes'],
            'graph_enabled' => ['graph_enabled', 'graphEnabled'],
            'qdrant_collection' => ['qdrant_collection', 'qdrantCollection'],
            'neo4j_namespace' => ['neo4j_namespace', 'neo4jNamespace'],
        ];

        if (!$partial) {
            $attributes = [
                'profile_id' => $profileId,
                'name' => $this->stringValue($input['name'] ?? null) ?? Str::headline(str_replace(['_', '-'], ' ', $profileId)),
                'description' => null,
                'start_urls' => [],
                'sitemap_url' => null,
                'max_pages' => 1,
                'allowed_file_types' => ['pdf', 'doc', 'docx'],
                'graph_enabled' => false,
                'qdrant_collection' => 'hawki_' . $this->safeName($profileId),
                'neo4j_namespace' => 'hawki_' . $this->safeName($profileId),
                'metadata' => [],
            ];
        }

        foreach ($map as $key => [$column, $normalizer]) {
            $keys = $aliases[$key] ?? [$key];
            foreach ($keys as $inputKey) {
                if (array_key_exists($inputKey, $input)) {
                    $attributes[$column] = $normalizer($input[$inputKey]);
                    break;
                }
            }
        }

        return $attributes;
    }

    private function profileId(mixed $value): string
    {
        $profileId = $this->stringValue($value);
        if ($profileId === null) {
            return 'profile_' . Str::lower(Str::random(8));
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($profileId)) ?: '';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : 'profile_' . Str::lower(Str::random(8));
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: 'default';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'default';
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item) => $this->stringValue($item), $value),
            static fn (?string $item) => $item !== null,
        ));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
