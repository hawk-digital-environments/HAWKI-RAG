<?php

namespace App\Console\Commands\Scraper\Concerns;

trait ManagesWorkflow
{
    /**
     * Handle user choice for continue/restart/cancel
     */
    protected function handleUserChoice($lastIndex, $hasExistingEmbeddings)
    {
        if ($lastIndex > 0) {
            $this->info("Found existing progress: processed {$lastIndex} files.");
            $choice = $this->choice('What would you like to do?', ['continue', 'restart', 'cancel'], 0);
        } else if ($hasExistingEmbeddings) {
            $this->info('Found existing embeddings in database.');
            $choice = $this->choice('What would you like to do?', ['continue', 'restart', 'cancel'], 0);
        } else {
            $choice = 'continue';
        }
        
        return $choice;
    }

    /**
     * Handle restart choice
     */
    protected function handleRestart($hasExistingEmbeddings)
    {
        if ($hasExistingEmbeddings) {
            if ($this->confirm('This will delete all existing embeddings. Are you sure?', false)) {
                $this->resetEmbeddingsTable();
                $this->deleteProgress();
                $this->info('Starting fresh import...');
            } else {
                $this->info('Import cancelled.');
                return;
            }
        } else {
            $this->deleteProgress();
            $this->info('Starting fresh import...');
        }
    }

    /**
     * Handle continue choice
     */
    protected function handleContinue($hasExistingEmbeddings)
    {
        if ($hasExistingEmbeddings) {
            $this->info('Checking for updates in existing content...');
            $updatesProcessed = $this->checkForUpdates();
            
            if ($updatesProcessed > 0) {
                $this->info("✅ Processed {$updatesProcessed} updates.");
            } else {
                $this->info('✅ No updates found.');
            }
            $this->info('Continuing with new content...');
        } else {
            $this->info('Continuing import...');
        }
    }
}
