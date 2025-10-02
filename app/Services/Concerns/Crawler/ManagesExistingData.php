<?php

namespace App\Services\Concerns\Crawler;

use Illuminate\Support\Str;

trait ManagesExistingData
{
    /**
     * Handle existing data analysis and user interaction
     */
    protected function handleExistingData(string $outputDir, string $label): array
    {
        $existingDirs = $this->getExistingDirectories($outputDir, $label);
        $directoryAnalysis = $this->scanAllDirectoriesForCompleteness($outputDir, $label);
        
        $shouldContinue = false;
        $startFromIndex = 1;
        
        if (filled($existingDirs)) {
            $this->displayExistingDataStats($existingDirs, $directoryAnalysis);
            
            $choice = method_exists($this, 'choice') ? $this->choice(
                'Existing scraped data found. What would you like to do?',
                [
                    'continue' => 'Continue and re-scrape incomplete directories',
                    'restart' => 'Start a new scrape from the beginning',
                    'cancel' => 'Cancel the scrape operation'
                ],
                'continue'
            ) : 'continue';
            
            if ($choice === 'cancel') {
                if (method_exists($this, 'info')) {
                    $this->info('Scrape operation cancelled.');
                }
                return [null, 0]; // Signal cancellation
            }
            
            if ($choice === 'continue') {
                [$shouldContinue, $startFromIndex] = $this->handleContinueChoice($outputDir, $label, $existingDirs, $directoryAnalysis);
            } elseif ($choice === 'restart') {
                $shouldContinue = $this->handleRestartChoice($outputDir, $label);
                $startFromIndex = 1;
            }
        }
        
        return [$shouldContinue, $startFromIndex];
    }

    /**
     * Display statistics about existing scraped data
     */
    protected function displayExistingDataStats(array $existingDirs, array $directoryAnalysis): void
    {
        $totalExisting = count($existingDirs);
        $totalComplete = count($directoryAnalysis['complete']);
        $totalIncomplete = count($directoryAnalysis['incomplete']);
        $lastCompleteDir = $directoryAnalysis['lastComplete'];
        
        if (method_exists($this, 'info')) {
            $this->info("Found existing scraped data:");
            $this->info("  Total directories: $totalExisting");
            $this->info("  Complete directories: $totalComplete");
            $this->info("  Incomplete directories: $totalIncomplete");
            
            if ($totalComplete > 0) {
                $this->info("  Last complete directory: " . Str::padLeft($lastCompleteDir, 5, '0'));
            }
        }
        
        if ($totalIncomplete > 0 && method_exists($this, 'warn')) {
            $incompleteList = collect($directoryAnalysis['incomplete'])
                ->map(fn($num) => Str::padLeft($num, 5, '0'))
                ->implode(', ');
            
            $this->warn("  Incomplete directories: {$incompleteList}");
        }
    }

    /**
     * Handle user's continue choice
     */
    protected function handleContinueChoice(string $outputDir, string $label, array $existingDirs, array $directoryAnalysis): array
    {
        $maxExistingDir = max($existingDirs);
        $startFromIndex = $maxExistingDir + 1;
        
        if (filled($directoryAnalysis['incomplete'])) {
            if (method_exists($this, 'info')) {
                $this->info("Preparing incomplete directories for re-scraping...");
            }
            
            foreach ($directoryAnalysis['incomplete'] as $incompleteDir) {
                $incompleteDirPath = "$outputDir/$label/" . Str::padLeft($incompleteDir, 5, '0');
                $formattedId = Str::padLeft($incompleteDir, 5, '0');
                
                if (is_dir($incompleteDirPath)) {
                    if (method_exists($this, 'info')) {
                        $this->info("Clearing directory $formattedId for re-scraping");
                    }
                    $this->emptyDirectory($incompleteDirPath);
                }
            }
            
            if (method_exists($this, 'info')) {
                $this->info("Prepared " . count($directoryAnalysis['incomplete']) . " directories for re-scraping");
            }
        }
        
        if (method_exists($this, 'info')) {
            $this->info("Will continue new URLs from directory: " . Str::padLeft($startFromIndex, 5, '0'));
        }
        
        return [true, $startFromIndex];
    }

    /**
     * Handle user's restart choice
     */
    protected function handleRestartChoice(string $outputDir, string $label): bool
    {
        $confirmRestart = method_exists($this, 'confirm') ? 
            $this->confirm('This will DELETE all existing scraped data in the output directory. Are you sure you want to continue?', false) : 
            false;
            
        if (!$confirmRestart) {
            if (method_exists($this, 'info')) {
                $this->info('Scrape operation cancelled.');
            }
            return false;
        }
        
        if (is_dir("$outputDir/$label")) {
            if (method_exists($this, 'info')) {
                $this->info("Emptying output directory: $outputDir/$label");
            }
            $this->emptyDirectory("$outputDir/$label");
        }
        
        if (isset($this->progressService)) {
            $this->progressService->deleteProgress($label);
        }
        
        return false; // Not continuing, restarting fresh
    }
} 