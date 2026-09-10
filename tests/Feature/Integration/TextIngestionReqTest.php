<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Http\Requests\Integration\IngestTextRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TextIngestionReqTest extends TestCase
{
    private const ENDPOINT = '/_test/text-ingestions';

    protected function setUp(): void
    {
        parent::setUp();

        Route::post(
            self::ENDPOINT,
            static function (IngestTextRequest $request): JsonResponse {
                return response()->json([
                    'input' => $request->ingestionInput()->toArray(),
                    'idempotency_key' => $request->idempotencyKey(),
                ], 202);
            },
        );
    }

    public function test_valid_text_ingestion_request_passes_form_validation(): void
    {
        $this->send($this->validPayload())
            ->assertAccepted()
            ->assertJsonPath('idempotency_key', 'document-123-v1')
            ->assertJsonPath('input.external_document_id', 'document-123')
            ->assertJsonPath('input.dataset_id', 'assistant_42')
            ->assertJsonPath('input.content_format', 'markdown');
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->send($this->validPayload(), null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_text_must_not_be_empty(): void
    {
        $this->send($this->validPayload([
            'text' => " \n\t ",
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    public function test_content_format_must_be_supported(): void
    {
        $this->send($this->validPayload([
            'content_format' => 'html',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_format');
    }

    public function test_dataset_identifier_must_have_a_supported_format(): void
    {
        $this->send($this->validPayload([
            'dataset_id' => 'not a dataset',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dataset_id');
    }

    public function test_caller_cannot_control_graph_or_storage_settings(): void
    {
        $this->send($this->validPayload([
            'graph' => false,
            'embedding_model' => 'custom-model',
            'qdrant_collection' => 'custom-collection',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'graph',
                'embedding_model',
                'qdrant_collection',
            ]);
    }

    public function test_unknown_top_level_field_is_rejected(): void
    {
        $this->send($this->validPayload([
            'display_nam' => 'Typo',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('display_nam')
            ->assertJsonPath(
                'errors.display_nam.0',
                'The field is not part of the direct-text ingestion contract.',
            );
    }

    public function test_multiple_unknown_top_level_fields_are_rejected(): void
    {
        $this->send($this->validPayload([
            'provider' => 'ollama',
            'workflow_id' => 'caller-workflow',
            'markdown_storage_path' => '/caller/path',
            'idempotency_key' => 'body-key-must-not-be-accepted',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'provider',
                'workflow_id',
                'markdown_storage_path',
                'idempotency_key',
            ]);
    }

    public function test_arbitrary_nested_metadata_fields_remain_allowed(): void
    {
        $this->send($this->validPayload([
            'metadata' => [
                'project_specific_key' => 'value',
                'nested' => [
                    'any_key' => true,
                ],
            ],
        ]))
            ->assertAccepted()
            ->assertJsonPath('input.metadata.project_specific_key', 'value')
            ->assertJsonPath('input.metadata.nested.any_key', true);
    }

    public function test_known_optional_fields_may_be_null(): void
    {
        $this->send($this->validPayload([
            'display_name' => null,
            'source_url' => null,
            'metadata' => null,
        ]))
            ->assertAccepted()
            ->assertJsonPath('input.display_name', null)
            ->assertJsonPath('input.source_url', null)
            ->assertJsonPath('input.metadata', []);
    }

    public function test_metadata_must_be_a_json_object(): void
    {
        $this->send($this->validPayload([
            'metadata' => ['first', 'second'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata');
    }

    public function test_oversized_text_is_rejected(): void
    {
        $this->send($this->validPayload([
            'text' => str_repeat(
                'x',
                (int) config('config.text_ingestion.max_text_characters') + 1,
            ),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    public function test_oversized_metadata_is_rejected(): void
    {
        $this->send($this->validPayload([
            'metadata' => [
                'value' => str_repeat(
                    'x',
                    (int) config('config.text_ingestion.max_metadata_bytes'),
                ),
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(
        array $payload,
        ?string $idempotencyKey = 'document-123-v1',
    ): TestResponse {
        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $this->call('POST', self::ENDPOINT, [], [], [], $headers, $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'external_document_id' => 'document-123',
            'dataset_id' => 'assistant_42',
            'text' => "# Example\n\nDocument content.",
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/documents/document-123',
            'metadata' => [
                'assistant_id' => 'assistant-42',
            ],
        ], $overrides);
    }
}
