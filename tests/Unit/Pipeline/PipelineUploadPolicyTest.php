<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Uploads\PipelineUploadPolicy;
use Tests\TestCase;

class PipelineUploadPolicyTest extends TestCase
{
    public function test_it_normalizes_supported_extensions_from_config(): void
    {
        config()->set('file_converter.supported_extensions', ['.PDF', ' docx ', '', '.TXT']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertSame(['pdf', 'docx', 'txt'], $policy->supportedExtensions());
    }

    public function test_it_supports_extensions_case_and_dot_insensitively(): void
    {
        config()->set('file_converter.supported_extensions', ['pdf', 'docx']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertTrue($policy->supports('.PDF'));
        $this->assertTrue($policy->supports(' docx '));
        $this->assertFalse($policy->supports('png'));
    }

    public function test_empty_supported_extensions_means_the_converter_decides(): void
    {
        config()->set('file_converter.supported_extensions', []);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertTrue($policy->supports('pdf'));
        $this->assertTrue($policy->supports('svg'));
        $this->assertTrue($policy->supports('pptx'));
        $this->assertTrue($policy->supports(''));
        $this->assertSame('Unsupported converter input. The converter rejected this file.', $policy->unsupportedMessage());
    }

    public function test_it_uses_generic_unsupported_message(): void
    {
        config()->set('file_converter.supported_extensions', ['.PDF', 'docx']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertSame(
            'Unsupported converter input. The converter rejected this file.',
            $policy->unsupportedMessage(),
        );
    }
}
