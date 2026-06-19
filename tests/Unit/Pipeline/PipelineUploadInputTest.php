<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Values\PipelineUploadInput;
use PHPUnit\Framework\TestCase;

class PipelineUploadInputTest extends TestCase
{
    public function test_it_prefers_dataset_id_and_normalizes_graph_false(): void
    {
        $input = PipelineUploadInput::fromValidated([
            'dataset_id' => ' primary-dataset ',
            'datasetId' => 'secondary-dataset',
            'graph' => 'false',
        ]);

        $this->assertSame('primary-dataset', $input->datasetId);
        $this->assertFalse($input->graph);
    }

    public function test_it_accepts_dataset_id_camel_case(): void
    {
        $input = PipelineUploadInput::fromValidated([
            'datasetId' => 'camel-dataset',
        ]);

        $this->assertSame('camel-dataset', $input->datasetId);
        $this->assertTrue($input->graph);
    }

    public function test_it_defaults_blank_dataset_and_invalid_graph(): void
    {
        $input = PipelineUploadInput::fromValidated([
            'dataset_id' => '   ',
            'graph' => 'not-a-boolean',
        ]);

        $this->assertSame('controller-uploads', $input->datasetId);
        $this->assertTrue($input->graph);
    }

    public function test_it_normalizes_custom_converter_fields(): void
    {
        $input = PipelineUploadInput::fromValidated([
            'converter_mode' => 'custom',
            'converter_url' => ' https://converter.example.test ',
            'converter_token' => ' token-123 ',
            'converter_start_path' => 'extract',
            'converter_status_path' => 'jobs/{job_id}',
        ]);

        $this->assertTrue($input->usesCustomConverter());
        $this->assertSame('custom', $input->converterMode);
        $this->assertSame('https://converter.example.test', $input->customConverterUrl);
        $this->assertSame('token-123', $input->customConverterToken);
        $this->assertSame('/extract', $input->customConverterStartPath);
        $this->assertSame([
            'converter_url' => 'https://converter.example.test',
            'converter_start_path' => '/extract',
            'converter_token' => 'token-123',
        ], $input->customConverterProfile());
    }
}
