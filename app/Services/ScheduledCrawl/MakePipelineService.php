<?php

namespace App\Services\ScheduledCrawl;

use Symfony\Component\Process\Process;

class MakePipelineService
{
    public function normalizePipelineMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        if ($normalized === 'make') {
            return 'make-sync';
        }

        if (!in_array($normalized, ['make-sync', 'rabbitmq-event'], true)) {
            return 'make-sync';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function runPrecheck(array $job): array
    {
        $errors = [];

        $scraperRepoPath = (string) config('scheduler_pipeline.scraper_repo_path');
        $ragRepoPath = (string) config('scheduler_pipeline.rag_repo_path');
        $scraperTarget = (string) config('scheduler_pipeline.scraper_make_target', 'crawl');
        $ragTarget = (string) config('scheduler_pipeline.rag_make_target', 'ingest');
        $checkBeforeRun = (bool) config('scheduler_pipeline.check_before_run', true);
        $dryTimeout = max(1, (int) config('scheduler_pipeline.dry_check_timeout_seconds', 30));
        $pipelineMode = $this->normalizePipelineMode((string) ($job['pipeline_mode'] ?? 'make-sync'));

        if ($scraperRepoPath === '' || !is_dir($scraperRepoPath)) {
            $errors[] = 'SCRAPER_REPO_PATH does not exist: ' . $scraperRepoPath;
        }

        if ($scraperRepoPath !== '' && !is_file($scraperRepoPath . DIRECTORY_SEPARATOR . 'Makefile')) {
            $errors[] = 'Scraper Makefile not found at: ' . $scraperRepoPath . DIRECTORY_SEPARATOR . 'Makefile';
        }
        if ($scraperRepoPath !== '' && is_file($scraperRepoPath . DIRECTORY_SEPARATOR . 'Makefile')) {
            $scraperMakefile = (string) file_get_contents($scraperRepoPath . DIRECTORY_SEPARATOR . 'Makefile');
            if (
                str_contains($scraperMakefile, 'https://www.hawk.de')
                && !str_contains($scraperMakefile, '$(URL)')
            ) {
                $errors[] = 'Scraper Makefile appears to hardcode URL. Expected payload value to use $(URL).';
            }
        }

        if ($ragRepoPath === '' || !is_dir($ragRepoPath)) {
            $errors[] = 'RAG_REPO_PATH does not exist: ' . $ragRepoPath;
        }

        if ($ragRepoPath !== '' && !is_file($ragRepoPath . DIRECTORY_SEPARATOR . 'Makefile')) {
            $errors[] = 'RAG Makefile not found at: ' . $ragRepoPath . DIRECTORY_SEPARATOR . 'Makefile';
        }
        if ($ragRepoPath !== '' && is_file($ragRepoPath . DIRECTORY_SEPARATOR . 'Makefile')) {
            $ragMakefile = (string) file_get_contents($ragRepoPath . DIRECTORY_SEPARATOR . 'Makefile');
            if (!str_contains($ragMakefile, 'GRAPH ?=')) {
                $errors[] = 'RAG Makefile does not expose GRAPH variable for ingest target.';
            }
        }

        if (($job['crawled_root'] ?? '') === '/absolute/path/to/crawled-data') {
            $errors[] = 'CRAWLED_ROOT still uses placeholder /absolute/path/to/crawled-data';
        }

        if (trim((string) ($job['job_id'] ?? '')) === '') {
            $errors[] = 'JOB_ID_FULL cannot be empty';
        }

        if (trim((string) ($job['url'] ?? '')) === '') {
            $errors[] = 'URL cannot be empty';
        }

        if (!empty($errors) || !$checkBeforeRun) {
            return ['ok' => empty($errors), 'errors' => $errors];
        }

        if (!$this->makeTargetExists($scraperRepoPath, $scraperTarget, $dryTimeout)) {
            $errors[] = "Scraper make target not found: {$scraperTarget}";
        }

        if (!$this->makeTargetExists($ragRepoPath, $ragTarget, $dryTimeout)) {
            $errors[] = "RAG make target not found: {$ragTarget}";
        }

        $composePs = $this->runCommand(['docker', 'compose', 'ps'], $scraperRepoPath, $dryTimeout);
        if (!$composePs['successful']) {
            $errors[] = 'docker compose ps failed in scraper repo: ' . $this->firstErrorLine($composePs['stderr']);
        }

        $servicesPs = $this->runCommand(['docker', 'compose', 'ps', '--services'], $scraperRepoPath, $dryTimeout);
        if (!$servicesPs['successful']) {
            $errors[] = 'docker compose ps --services failed in scraper repo: ' . $this->firstErrorLine($servicesPs['stderr']);
        } elseif (!preg_match('/crawler/i', $servicesPs['stdout'])) {
            $errors[] = 'Crawler service was not found in scraper docker compose services';
        }

        $bridgeCheck = $this->runCommand(['docker', 'exec', 'hawki_rag_bridge', 'true'], $ragRepoPath, $dryTimeout);
        if (!$bridgeCheck['successful']) {
            $errors[] = 'docker exec hawki_rag_bridge true failed: ' . $this->firstErrorLine($bridgeCheck['stderr']);
        }

        $scraperDry = $this->runScraper($job, true, $dryTimeout);
        if (!$scraperDry['successful']) {
            $errors[] = 'Scraper dry-check failed: ' . $this->firstErrorLine($scraperDry['stderr']);
        }

        if ($pipelineMode === 'make-sync') {
            $ingestDry = $this->runIngest($job, true, $dryTimeout);
            if (!$ingestDry['successful']) {
                $errors[] = 'Ingest dry-check failed: ' . $this->firstErrorLine($ingestDry['stderr']);
            }
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{command: string, exit_code: int, stdout: string, stderr: string, successful: bool}
     */
    public function runScraper(array $job, bool $dryRun = false, ?int $timeout = null): array
    {
        $repoPath = (string) config('scheduler_pipeline.scraper_repo_path');
        $target = (string) config('scheduler_pipeline.scraper_make_target', 'crawl');
        $timeout ??= max(1, (int) config('scheduler_pipeline.command_timeout_seconds', 3600));

        $command = [];
        $command[] = 'make';
        if ($dryRun) {
            $command[] = '-n';
        }
        $command[] = $target;
        $command[] = 'URL=' . (string) $job['url'];
        $command[] = 'JOB_ID_FULL=' . (string) $job['job_id'];
        $command[] = 'SITEMAP_PAGES=' . (string) $job['sitemap_pages'];
        $command[] = 'MAX_PAGES_FULL=' . (string) ($job['max_pages'] ?? '');
        $command[] = 'RESCRAPE_FAILED=' . $this->asMakeBool((bool) $job['rescrape_failed']);
        $command[] = 'SKIP_IMAGES=' . $this->asMakeBool((bool) $job['skip_images']);

        return $this->runCommand($command, $repoPath, $timeout);
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{command: string, exit_code: int, stdout: string, stderr: string, successful: bool}
     */
    public function runIngest(array $job, bool $dryRun = false, ?int $timeout = null): array
    {
        $repoPath = (string) config('scheduler_pipeline.rag_repo_path');
        $target = (string) config('scheduler_pipeline.rag_make_target', 'ingest');
        $timeout ??= max(1, (int) config('scheduler_pipeline.command_timeout_seconds', 3600));

        $command = [];
        $command[] = 'make';
        if ($dryRun) {
            $command[] = '-n';
        }
        $command[] = $target;
        $command[] = 'CRAWLED_ROOT=' . (string) $job['crawled_root'];
        $command[] = 'GRAPH=' . $this->asMakeBool((bool) $job['graph_enabled']);
        $command[] = 'BATCH=16';
        $command[] = 'PROVIDER=ollama';
        $command[] = 'BASE_URL=http://localhost:8000';

        return $this->runCommand($command, $repoPath, $timeout);
    }

    private function makeTargetExists(string $repoPath, string $target, int $timeout): bool
    {
        $result = $this->runCommand(['make', '-qp'], $repoPath, $timeout);
        if (!$result['successful']) {
            return false;
        }

        return preg_match('/^' . preg_quote($target, '/') . ':/m', $result['stdout']) === 1;
    }

    private function asMakeBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function firstErrorLine(string $stderr): string
    {
        $trimmed = trim($stderr);
        if ($trimmed === '') {
            return 'unknown error';
        }

        $lines = preg_split('/\R/', $trimmed) ?: [];

        return $lines[0] ?? 'unknown error';
    }

    /**
     * @param  array<int, string>  $command
     * @return array{command: string, exit_code: int, stdout: string, stderr: string, successful: bool}
     */
    private function runCommand(array $command, string $workingDirectory, int $timeout): array
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'command' => implode(' ', array_map('strval', $command)),
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'successful' => $process->isSuccessful(),
        ];
    }
}
