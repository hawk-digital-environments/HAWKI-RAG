<?php

namespace App\Console\Commands\Scraper;

use Illuminate\Console\Command;
use App\Services\AI\Providers\OllamaProvider;
use App\Models\Embedding;
use App\Console\Commands\Scraper\Concerns\ManagesProgress;
use App\Console\Commands\Scraper\Concerns\DatabaseOperations;
use App\Console\Commands\Scraper\Concerns\ProcessesFiles;
use App\Console\Commands\Scraper\Concerns\ManagesUpdates;
use App\Console\Commands\Scraper\Concerns\DirectoryUpdates;
use App\Console\Commands\Scraper\Concerns\UpdatesEmbeddings;
use App\Console\Commands\Scraper\Concerns\ManagesWorkflow;
use App\Console\Commands\Scraper\Concerns\ProcessesImport;

class ImportScraping extends Command
{
    use ManagesProgress,
        DatabaseOperations,
        ProcessesFiles,
        ManagesUpdates,
        DirectoryUpdates,
        UpdatesEmbeddings,
        ManagesWorkflow,
        ProcessesImport;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:scraping';

    /**
     * The console command description.
     */
    protected $description = 'Import scraped data into vector database';

    /**
     * The Ollama provider instance
     */
    protected $ollamaProvider;

    public function __construct(OllamaProvider $ollamaProvider)
    {
        parent::__construct();
        $this->ollamaProvider = $ollamaProvider;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ini_set('memory_limit', '1G');

        $lastIndex = $this->getLastProcessedIndex();
        $hasExistingEmbeddings = Embedding::count() > 0;
        
        $choice = $this->handleUserChoice($lastIndex, $hasExistingEmbeddings);
        
        if ($choice === 'cancel') {
            $this->info('Import cancelled.');
            return;
        }
        
        if ($choice === 'restart') {
            $this->handleRestart($hasExistingEmbeddings);
        } else if ($choice === 'continue') {
            $this->handleContinue($hasExistingEmbeddings);
        }

        $this->performImport();
    }
}
