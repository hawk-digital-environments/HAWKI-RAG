<?php

namespace App\Console\Commands\Scraper\Concerns;

use App\Models\Embedding;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

trait UpdatesEmbeddings
{
    /**
     * Update existing text embedding
     */
    protected function updateTextEmbedding($file, $pageURL, $metaImgUrl, $title, $date, $embeddingId)
    {
        $text = mb_convert_encoding(file_get_contents($file), 'UTF-8', mb_detect_encoding(file_get_contents($file)));
        
        if (blank($text)) {
            return;
        }

        $title = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $title);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $textInterFormat = $this->ollamaProvider->generateAdditionalContext($text);
        $textTags = $this->ollamaProvider->generateKeywords($textInterFormat);        
        $dateProminent = $date ? " year {$date} date {$date} published {$date}" : '';
        $textCombined = $text . " " . $textInterFormat . " " . $textTags . $dateProminent;
        $textEmbedding = $this->ollamaProvider->getEmbeddings($textCombined);

        if ($textEmbedding === null) {
            throw new \Exception("Failed to get embedding");
        }

        // Update the existing embedding
        Embedding::where('id', $embeddingId)->update([
            'title' => $title,
            'content' => $text,
            'embedding' => $textEmbedding,
            'meta_img_url' => $metaImgUrl,
            'page_url' => $pageURL,
            'source_url' => $pageURL,
            'source_format' => 'txt',
            'date' => $date,
            'tags' => mb_convert_encoding($textTags, 'UTF-8', 'UTF-8'),
            'intermediate_formatting' => mb_convert_encoding($textInterFormat, 'UTF-8', 'UTF-8'),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update existing image embedding
     */
    protected function updateImageEmbedding($file, $sourceFormat, $pageURL, $metaImgUrl, $data, $title, $date, $embeddingId)
    {
        // Find the text file for this directory
        $siteTextFiles = glob(dirname(dirname($file)) . '/site_*.txt');
        
        if (blank($siteTextFiles)) {
            $this->warn("No site text file found for image: $file");
            return;
        }
        
        $siteTextFile = $siteTextFiles[0];
        $pageContent = mb_convert_encoding(file_get_contents($siteTextFile), 'UTF-8', mb_detect_encoding(file_get_contents($siteTextFile)));

        if ($pageContent === false) {
            return;
        }

        $title = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $title);
        $pageContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $pageContent);
        $imageInterFormat = $this->ollamaProvider->generateImageContext(realpath($file), $pageContent);
        $imageTags = $this->ollamaProvider->generateKeywords($imageInterFormat);        
        $dateProminent = $date ? " year {$date} date {$date} published {$date}" : '';
        $imageCombined = $title . " " . $imageInterFormat . " " . $imageTags . $dateProminent;
        $imageEmbedding = $this->ollamaProvider->getEmbeddings($imageCombined);

        if ($imageEmbedding === null) {
            $this->warn("Failed to get embedding for image: " . basename($file));
            return;
        }

        $imageName = Str::before(basename($file), '.');
        $sourceURL = '';
        
        // Try to find the original image URL from the data
        $images = Arr::get($data, 'images', []);
        if (is_array($images)) {
            $sourceURL = array_reduce($images, function ($carry, $url) use ($imageName) {
                $filename = basename($url);
                if (Str::contains($filename, $imageName)) {
                    return $url;
                }
                return $carry;
            }, '');
        }
        
        // If we couldn't find the original URL, use the meta image URL or page URL
        if (blank($sourceURL)) {
            $sourceURL = !empty($metaImgUrl) ? $metaImgUrl : $pageURL;
        }

        // Update the existing image embedding
        Embedding::where('id', $embeddingId)->update([
            'title' => $title,
            'content' => mb_convert_encoding($imageName, 'UTF-8', 'UTF-8'),
            'embedding' => $imageEmbedding,
            'meta_img_url' => $metaImgUrl,
            'page_url' => $pageURL,
            'source_url' => $sourceURL,
            'source_format' => $sourceFormat,
            'date' => $date,
            'tags' => mb_convert_encoding($imageTags, 'UTF-8', 'UTF-8'),
            'intermediate_formatting' => mb_convert_encoding($imageInterFormat, 'UTF-8', 'UTF-8'),
            'updated_at' => now(),
        ]);
    }
}
