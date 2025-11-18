<?php

namespace App\Console\Commands\Crawler;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CrawleeSetup extends Command
{
    protected $signature = 'crawlee:setup';
    protected $description = 'Set up the Crawlee web scraper environment';

    /**
     * Execute the crawlee:setup command.
     *
     * Performs a complete setup of the Crawlee-based web scraper environment:
     * 1. Creates the Node.js project directory (resources/js/crawler)
     * 2. Generates package.json with required dependencies
     * 3. Copies the main crawler.js script from stubs
     * 4. Copies utility modules from stubs
     * 5. Installs Node.js dependencies via npm
     * 6. Installs Playwright browser binaries
     * 7. Displays usage instructions
     *
     * This command should be run once when first setting up the crawler,
     * or after major updates to the crawler configuration.
     *
     * @return int Command exit code (0 for success, 1 for failure)
     */
    public function handle()
    {
        $this->info('Setting up Crawlee web scraper...');

        // Step 1: Create the Node.js project directory
        $nodePath = resource_path('js/crawler');

        if (!File::exists($nodePath)) {
            File::makeDirectory($nodePath, 0755, true);
            $this->info('Created crawler directory: ' . $nodePath);
        }

        // Step 2: Generate package.json with Crawlee dependencies
        $packageJson = [
            'name' => 'laravel-crawlee',
            'version' => '1.0.0',
            'description' => 'Web crawler using Crawlee for Laravel',
            'main' => 'crawler.js',
            'type' => 'module',  // Enable ES6 modules
            'dependencies' => [
                'crawlee' => '^3.13.1',        // Main crawler framework
                'playwright' => '^1.40.0',     // Browser automation
                'axios' => '^1.6.0',           // HTTP client
                'sharp' => '^0.33.0'           // Image processing
            ]
        ];

        File::put(
            $nodePath . '/package.json',
            json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->info('Created package.json');

        // Step 3: Copy the main crawler script from stubs
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

        // Step 4: Copy utility modules
        $this->copyUtilities($nodePath);

        // Step 5: Install Node.js dependencies
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

        // Step 6: Install Playwright browsers
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

        // Step 7: Display success message and usage instructions
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
     * Copy utility modules from stubs to the Node.js project directory.
     *
     * Copies all utility JavaScript modules from the stubs/utilities directory
     * to resources/js/crawler/utilities. These utilities provide helper functions
     * for the main crawler script. The .stub extension is removed from filenames
     * during the copy process.
     *
     * @param string $nodePath Path to the Node.js project directory
     * @return void
     */
    private function copyUtilities($nodePath)
    {
        // Create utilities subdirectory
        $utilitiesPath = $nodePath . '/utilities';
        if (!File::exists($utilitiesPath)) {
            File::makeDirectory($utilitiesPath, 0755, true);
            $this->info('Created utilities directory: ' . $utilitiesPath);
        }

        // Copy all utility files from stubs
        $stubsUtilitiesDir = __DIR__ . '/stubs/utilities';
        if (File::exists($stubsUtilitiesDir)) {
            $files = File::files($stubsUtilitiesDir);

            // Copy each utility file, removing .stub extension
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