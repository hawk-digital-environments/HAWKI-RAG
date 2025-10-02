<?php

namespace App\Console\Commands\Crawler;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CrawleeSetup extends Command
{
    protected $signature = 'crawlee:setup';
    protected $description = 'Set up the Crawlee web scraper environment';

    public function handle()
    {
        $this->info('Setting up Crawlee web scraper...');
        
        // Create the crawler directory
        $nodePath = resource_path('js/crawler');
        
        if (!File::exists($nodePath)) {
            File::makeDirectory($nodePath, 0755, true);
            $this->info('Created crawler directory: ' . $nodePath);
        }
        
        // Create package.json
        $packageJson = [
            'name' => 'laravel-crawlee',
            'version' => '1.0.0',
            'description' => 'Web crawler using Crawlee for Laravel',
            'main' => 'crawler.js',
            'type' => 'module', 
            'dependencies' => [
                'crawlee' => '^3.13.1',
                'playwright' => '^1.40.0',
                'axios' => '^1.6.0',
                'sharp' => '^0.33.0'
            ]
        ];
        
        File::put(
            $nodePath . '/package.json', 
            json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        $this->info('Created package.json');
        
        // Create the crawler.js file
        $stubPath = __DIR__ . '/stubs/crawler.js.stub';
        
        if (!File::exists($stubPath)) {
            $this->error('Could not find crawler.js.stub file at: ' . $stubPath);
            $this->comment('Please ensure the stub file exists at: ' . $stubPath);
            return 1;
        }
        
        $crawlerJs = File::get($stubPath);
        
        if (!$crawlerJs) {
            $this->error('Could not read crawler.js.stub file.');
            $this->comment('You will need to manually create the crawler.js file in ' . $nodePath);
            return 1;
        }
        
        File::put($nodePath . '/crawler.js', $crawlerJs);
        $this->info('Created crawler.js');
        
        // Add this method call to copy utilities
        $this->copyUtilities($nodePath);

        // Install dependencies
        $this->info('Installing Node.js dependencies (this may take a while)...');
        
        $process = Process::path($nodePath)->run('npm install');
        
        if ($process->successful()) {
            $this->info('Dependencies installed successfully.');
        } else {
            $this->error('Failed to install dependencies:');
            $this->error($process->errorOutput());
            $this->comment('You may need to run `npm install` manually in ' . $nodePath);
            return 1;
        }
        
        // Install Playwright browsers
        $this->info('Installing Playwright browsers (this may take a while)...');
        
        $process = Process::path($nodePath)->run('npx playwright install');
        
        if ($process->successful()) {
            $this->info('Playwright browsers installed successfully.');
        } else {
            $this->error('Failed to install Playwright browsers:');
            $this->error($process->errorOutput());
            $this->comment('You may need to run `npx playwright install` manually in ' . $nodePath);
            // Don't return error here as this is optional for basic functionality
        }
        
        $this->info('');
        $this->info('Setup completed successfully! 🎉');
        $this->info('');
        $this->info('You can now use the crawler with:');
        $this->info('  php artisan crawlee:scrape "https://example.com"');
        $this->info('  php artisan crawlee:scrape /path/to/sitemap.txt --label=my-project');
        $this->info('  php artisan crawlee:scrape "https://example.com/sitemap.xml?page=1" --skip-images --max-pages=50');
        $this->info('  php artisan crawlee:scrape "https://example.com" --date=\'meta[property="og:updated_time"]\' --max-pages=1000');
        $this->info('');
        $this->info('The crawler will automatically detect existing data and offer to continue or restart.');
        
        return 0;
    }

    /**
     * Copy utility files from stubs to the node directory
     */
    private function copyUtilities($nodePath)
    {
        $utilitiesPath = $nodePath . '/utilities';
        if (!File::exists($utilitiesPath)) {
            File::makeDirectory($utilitiesPath, 0755, true);
            $this->info('Created utilities directory: ' . $utilitiesPath);
        }
        
        $stubsUtilitiesDir = __DIR__ . '/stubs/utilities';
        if (File::exists($stubsUtilitiesDir)) {
            $files = File::files($stubsUtilitiesDir);
            foreach ($files as $file) {
                $content = File::get($file->getPathname());
                $filename = str_replace('.stub', '', $file->getFilename());
                File::put($utilitiesPath . '/' . $filename, $content);
                $this->info('Created utility: ' . $filename);
            }
            
            $this->info('All utility modules copied successfully!');
        } else {
            $this->warn('Utilities directory not found at: ' . $stubsUtilitiesDir);
        }
    }
} 