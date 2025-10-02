<?php

namespace App\Services\Concerns\Crawler;

use Illuminate\Support\Facades\File;

trait HandlesFileSystem
{
    /**
     * Setup and validate output directory
     */
    protected function setupOutputDirectory(): ?string
    {
        $outputDir = realpath(storage_path('app/private/crawled-data'));
        if (!$outputDir) {
            File::makeDirectory(storage_path('app/private/crawled-data'), 0755, true);
            $outputDir = realpath(storage_path('app/private/crawled-data'));
        }
        
        if (!$outputDir) {
            if (method_exists($this, 'error')) {
                $this->error('Could not create or find the output directory.');
            }
            return null;
        }
        
        if (method_exists($this, 'info')) {
            $this->info("Using absolute output path: $outputDir");
        }
        
        return $outputDir;
    }
} 