<?php

namespace App\Console\Commands\Scraper\Concerns;

use App\Models\Embedding;
use Illuminate\Support\Str;

trait DirectoryUpdates
{
    /**
     * Find directory by page URL
     */
    protected function findDirectoryByPageUrl($folders, $pageUrl)
    {
        foreach ($folders as $folder) {
            $jsonFile = $folder . '/data_' . basename($folder) . '.json';
            
            if (!file_exists($jsonFile)) {
                $jsonFiles = glob($folder . '/data_*.json');
                if (empty($jsonFiles)) {
                    continue;
                }
                $jsonFile = $jsonFiles[0];
            }
            
            $data = json_decode(file_get_contents($jsonFile), true);
            
            if ($data && isset($data['page_url'][0]) && $data['page_url'][0] === $pageUrl) {
                return $folder;
            }
        }
        
        return null;
    }

    /**
     * Reprocess a directory (both text and images) and update existing embedding
     */
    protected function reprocessDirectory($directoryPath, $data, $embeddingId)
    {
        $pageURL = $data['page_url'][0] ?? '';
        $title = $data['title'][0] ?? 'No Title';
        $metaImgUrl = $data['meta_img_url'][0] ?? '';
        $date = $data['date'][0] ?? '';
        
        // Find and reprocess text file
        $textFiles = glob($directoryPath . '/site_*.txt');
        if (!empty($textFiles)) {
            $textFile = $textFiles[0];
            $this->updateTextEmbedding($textFile, $pageURL, $metaImgUrl, $title, $date, $embeddingId);
        }
        
        // Find and reprocess image files
        $imageDir = $directoryPath . '/images';
        if (is_dir($imageDir)) {
            $currentImageFiles = array_merge(
                glob($imageDir . '/*.png'),
                glob($imageDir . '/*.jpg'),
                glob($imageDir . '/*.gif')
            );
            
            // Get existing image embeddings for this page
            $existingImageEmbeddings = Embedding::where('page_url', $pageURL)
                ->whereIn('source_format', ['png', 'jpg', 'gif'])  
                ->where('id', '!=', $embeddingId)
                ->get()
                ->keyBy('content'); // Use content (image name) as key
            
            $processedImageNames = [];
            
            // Update or create embeddings for current images
            foreach ($currentImageFiles as $imageFile) {
                $imageName = Str::before(basename($imageFile), '.');
                $sourceFormat = pathinfo($imageFile, PATHINFO_EXTENSION);
                
                $processedImageNames[] = $imageName;
                
                // Check if this image already has an embedding
                $existingEmbedding = $existingImageEmbeddings->get($imageName);
                
                if ($existingEmbedding) {
                    // Update existing image embedding
                    $this->updateImageEmbedding($imageFile, $sourceFormat, $pageURL, $metaImgUrl, $data, $title, $date, $existingEmbedding->id);
                } else {
                    // Create new image embedding
                    $this->processImageFile($imageFile, $sourceFormat, $pageURL, $metaImgUrl, $data, $title, $date);
                }
            }
            
            // Delete embeddings for images that no longer exist
            $imagesToDelete = $existingImageEmbeddings->filter(function ($embedding) use ($processedImageNames) {
                return !in_array($embedding->content, $processedImageNames);
            });
            
            foreach ($imagesToDelete as $embeddingToDelete) {
                $this->comment("   🗑️  Removing embedding for deleted image: {$embeddingToDelete->content}");
                $embeddingToDelete->delete();
            }
        }
    }
}
