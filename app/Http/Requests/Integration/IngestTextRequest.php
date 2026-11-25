<?php

declare(strict_types=1);

namespace App\Http\Requests\Integration;

use App\Models\User;
use App\Services\TextIngestion\Values\TextIngestionInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class IngestTextRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_BODY_FIELDS = [
        'external_document_id',
        'dataset_id',
        'text',
        'content_format',
        'display_name',
        'source_url',
        'metadata',
    ];

    /** @var list<string> */
    private array $submittedBodyFields = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->isJson()
            ? $this->json()->all()
            : $this->request->all();
        $this->submittedBodyFields = array_map(
            static fn (int|string $field): string => (string) $field,
            array_keys($body),
        );

        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            'external_document_id' => 'required|string|max:191|regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            'dataset_id' => [
                'required',
                'string',
                'max:160',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/',
            ],
            'text' => [
                'required',
                'string',
                'not_regex:/\A\s*\z/u',
                'max:'.(int) config('config.text_ingestion.max_text_characters'),
            ],
            'content_format' => 'required|string|in:plain_text,markdown',
            'display_name' => 'nullable|string|max:255',
            'source_url' => 'nullable|url:http,https|max:2048',
            'metadata' => [
                'nullable',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value) && $value !== [] && array_is_list($value)) {
                        $fail('The metadata field must be a JSON object.');

                        return;
                    }

                    if (
                        is_array($value)
                        && strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES))
                            > (int) config('config.text_ingestion.max_metadata_bytes')
                    ) {
                        $fail('The metadata field is too large.');
                    }
                },
            ],
            'graph' => 'prohibited',
            'embedding_provider' => 'prohibited',
            'embedding_model' => 'prohibited',
            'qdrant_collection' => 'prohibited',
            'neo4j_namespace' => 'prohibited',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpectedFields = array_diff(
                $this->submittedBodyFields,
                self::ALLOWED_BODY_FIELDS,
            );

            foreach ($unexpectedFields as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    'The field is not part of the direct-text ingestion contract.',
                );
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    public function ingestionInput(): TextIngestionInput
    {
        return TextIngestionInput::fromValidated(
            $this->safe()->except('idempotency_key'),
        );
    }
}
