<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Uploads\PipelineUploadPolicy;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Tests\TestCase;

class PipelineUploadPolicyTest extends TestCase
{
    public function test_it_normalizes_supported_extensions_from_config(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['.PDF', ' docx ', '', '.TXT']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertSame(['pdf', 'docx', 'txt'], $policy->supportedExtensions());
    }

    public function test_it_supports_extensions_case_and_dot_insensitively(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['pdf', 'docx']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertTrue($policy->supports('.PDF'));
        $this->assertTrue($policy->supports(' docx '));
        $this->assertFalse($policy->supports('png'));
    }

    public function test_custom_converter_mode_accepts_non_native_extensions(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['pdf', 'docx']);

        $policy = app(PipelineUploadPolicy::class);
        $input = PipelineUploadInput::fromValidated([
            'converter_mode' => 'custom',
            'converter_url' => 'https://converter.example.test',
        ]);

        $this->assertTrue($policy->supports('svg', $input));
        $this->assertTrue($policy->supports('zip', $input));
        $this->assertFalse($policy->supports('', $input));
    }

    public function test_it_uses_native_unsupported_message(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['.PDF', 'docx']);

        $policy = app(PipelineUploadPolicy::class);

        $this->assertSame(
            'This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats.',
            $policy->unsupportedMessage(),
        );
    }
}
