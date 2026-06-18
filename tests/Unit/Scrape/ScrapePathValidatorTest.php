<?php

declare(strict_types=1);

namespace Tests\Unit\Scrape;

use App\Services\Scrape\Validation\ScrapePathValidator;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScrapePathValidatorTest extends TestCase
{
    public function test_it_accepts_only_http_and_https_remote_urls(): void
    {
        $validator = $this->app->make(ScrapePathValidator::class);

        $this->assertTrue($validator->isValidUrlOrFile('https://example.test/source'));
        $this->assertTrue($validator->isValidUrlOrFile('http://example.test/source'));
        $this->assertFalse($validator->isValidUrlOrFile('ftp://example.test/source'));
        $this->assertFalse($validator->isValidUrlOrFile('file:///etc/passwd'));
    }

    public function test_it_blocks_local_files_outside_allowed_roots(): void
    {
        $suffix = Str::random(10);
        $allowedRoot = storage_path('framework/testing/scrape-allowed-'.$suffix);
        $outsideRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scrape-outside-'.$suffix;

        mkdir($allowedRoot, 0775, true);
        mkdir($outsideRoot, 0775, true);
        file_put_contents($allowedRoot.DIRECTORY_SEPARATOR.'urls.txt', "https://example.test\n");
        file_put_contents($outsideRoot.DIRECTORY_SEPARATOR.'urls.txt', "https://example.test\n");

        config()->set('scraper.allowed_local_roots', [$allowedRoot]);
        $this->app->forgetInstance(ScrapePathValidator::class);

        $validator = $this->app->make(ScrapePathValidator::class);

        $this->assertTrue($validator->isValidUrlOrFile($allowedRoot.DIRECTORY_SEPARATOR.'urls.txt'));
        $this->assertTrue($validator->isValidUrlListFile($allowedRoot.DIRECTORY_SEPARATOR.'urls.txt'));
        $this->assertTrue($validator->isValidDirectory($allowedRoot.DIRECTORY_SEPARATOR.'new-run'));
        $this->assertFalse($validator->isValidUrlOrFile($outsideRoot.DIRECTORY_SEPARATOR.'urls.txt'));
        $this->assertFalse($validator->isValidUrlListFile($outsideRoot.DIRECTORY_SEPARATOR.'urls.txt'));
        $this->assertFalse($validator->isValidDirectory($outsideRoot.DIRECTORY_SEPARATOR.'new-run'));
    }
}
