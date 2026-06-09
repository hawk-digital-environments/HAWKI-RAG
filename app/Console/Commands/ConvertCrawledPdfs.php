<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FileConverter\CrawledFileConversionService;
use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Validation\PipelineDataValidator;
use Illuminate\Console\Command;

class ConvertCrawledPdfs extends Command
{
    /**
     * Usage:
     *   php artisan convert:crawled-files /app/shared/crawled-data/hawk
     */
    protected $signature = 'convert:crawled-pdfs
        {outputDir? : Path to crawler output directory (absolute path or path relative to the canonical crawled-data root)}
        {--job-id= : Pipeline job ID to update; defaults to a deterministic conversion job ID}
        {--extensions= : Comma-separated list of extensions to convert; defaults to file_converter.supported_extensions}
        {--scan-all : Scan all files under outputDir (not just **/files/)}
        {--existing=ask : Existing output mode: ask, continue, restart, cancel}';

    protected $description = 'Backward-compatible alias for convert:crawled-files.';

    public function handle(
        CrawledFileConversionService $conversion,
        DocumentConverter $converter,
        PipelineDataValidator $validator,
        PipelineStateService $state,
        PipelineStageLogger $logger,
    ): int {
        return $conversion->run(
            $this,
            $this->output,
            $this->input->isInteractive(),
            $converter,
            $validator,
            $state,
            $logger,
        );
    }
}
