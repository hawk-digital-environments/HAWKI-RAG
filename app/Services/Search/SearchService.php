<?php

namespace App\Services\Search;  

use App\Models\Embedding;
use App\Services\AI\Providers\OllamaProvider;

class SearchService
{
    protected $ollamaProvider;

    public function __construct(OllamaProvider $ollamaProvider)
    {
        $this->ollamaProvider = $ollamaProvider;
    }

    public function search(
        string $term,
        ?string $format = null, // null when searching both txt and images, 'txt' when searching text only and 'images' when searching images only  
        ?float $min_score = 0.5,
        ?int $offset = 0,
        ?int $limit = 5,
        ?bool $aggregate = true // true sorts the results by page_url - works better for the chatbot
    ) {
        $searchEmbedding = $this->ollamaProvider->getEmbeddings($term);
        if (!$searchEmbedding) {
            return ['items' => []];
        }

        // Reset score for aggregation
        $effectiveMinScore = $aggregate ? 0.0 : ($min_score ?? 0.5);
        
        // Get all entries with all necessary columns
        $entries = Embedding::select([
            'id',
            'title',
            'content',
            'embedding',
            'meta_img_url',
            'page_url',
            'source_url',
            'source_format',
            'date',
            'tags',
            'intermediate_formatting'
        ])->get();
        
        $results = $entries->map(function ($entry) use ($searchEmbedding, $effectiveMinScore, $format) {
            // Skip invalid embeddings
            if (!is_array($entry->embedding) || count($entry->embedding) !== count($searchEmbedding)) {
                return null;
            }

            $similarity = $this->cosineSimilarity($searchEmbedding, $entry->embedding);
            
            // Format filtering
            $formatMap = [
                'txt' => ['txt'],
                'images' => ['png', 'jpg', 'gif']
            ];
            
            $setFormat = $formatMap[$format] ?? null;
            if ($setFormat && !in_array($entry->source_format, $setFormat)) {
                return null;
            }
            
            if ($similarity < $effectiveMinScore) {
                return null;
            }
            
            return [
                'id' => $entry->id,
                'title' => $entry->title,
                'content' => $entry->content,
                'meta_img_url' => $entry->meta_img_url,
                'page_url' => $entry->page_url,
                'source_url' => $entry->source_url,
                'source_format' => $entry->source_format,
                'date' => $entry->date,
                'tags' => $entry->tags,
                'intermediate_formatting' => $entry->intermediate_formatting,
                'similarity' => $similarity
            ];
        })
        ->filter()
        ->values();

        if ($aggregate) {
            $results = $this->aggregateResults($results, $min_score ?? 0.5);
        }

        $results = $results->sortByDesc('similarity')->values();

        if ($offset > 0) {
            $results = $results->slice($offset);
        }
        
        return [
            'items' => $results->take($limit ?? 10)
        ];
    }

    protected function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        // Cache the length to avoid recalculating it
        $length = count($vectorA);
        
        // Use unrolled and cached variables for better performance
        $dotProduct = 0;
        $magnitudeA = 0;
        $magnitudeB = 0;
        
        // Process 4 elements at a time if possible
        $i = 0;
        $limit = $length - 3; // Process in groups of 4
        
        while ($i < $limit) {
            // Prefetch values (reduces array lookups)
            $a1 = $vectorA[$i];
            $b1 = $vectorB[$i];
            $a2 = $vectorA[$i+1];
            $b2 = $vectorB[$i+1];
            $a3 = $vectorA[$i+2];
            $b3 = $vectorB[$i+2];
            $a4 = $vectorA[$i+3];
            $b4 = $vectorB[$i+3];
            
            // Unrolled calculations for 4 elements
            $dotProduct += $a1 * $b1 + $a2 * $b2 + $a3 * $b3 + $a4 * $b4;
            $magnitudeA += $a1 * $a1 + $a2 * $a2 + $a3 * $a3 + $a4 * $a4;
            $magnitudeB += $b1 * $b1 + $b2 * $b2 + $b3 * $b3 + $b4 * $b4;
            
            $i += 4;
        }
        
        // Handle remaining elements
        while ($i < $length) {
            $a = $vectorA[$i];
            $b = $vectorB[$i];
            $dotProduct += $a * $b;
            $magnitudeA += $a * $a;
            $magnitudeB += $b * $b;
            $i++;
        }
        
        // Avoid expensive sqrt calculation if possible
        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0;
        }
        
        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);
        
        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    protected function aggregateResults($results, float $minScore)
    {
        return collect($results)
            ->groupBy('page_url')
            ->map(function ($group) {
                $averageScore = $group->avg('similarity');
                
                // Prefer txt entries as representatives
                $representative = $group->firstWhere('source_format', 'txt') ?? $group->first();
                return array_merge($representative, ['similarity' => $averageScore]);
            })
            ->filter(function ($entry) use ($minScore) {
                return $entry['similarity'] >= $minScore;
            })
            ->values();
    }
} 