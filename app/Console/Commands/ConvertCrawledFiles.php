<?php

namespace App\Console\Commands;

class ConvertCrawledFiles extends ConvertCrawledPdfs
{
    protected $signature = 'convert:crawled-files
        {outputDir? : Path to crawler output directory (absolute path or path relative to the canonical crawled-data root)}
        {--extensions= : Comma-separated list of extensions to convert; defaults to file_converter.supported_extensions}
        {--scan-all : Scan all files under outputDir (not just **/files/)}
        {--existing=ask : Existing output mode: ask, continue, restart, cancel}';

    protected $description = 'Convert supported files under OUTPUT_DIR to Markdown using DocumentConverter.';
}
