<?php

declare(strict_types=1);

namespace App\Http\Requests\Rag;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QueryDatasetRequest extends FormRequest
{
    /** @var list<string> */
    private const RESERVED_FILTER_KEYS = [
        'dataset_id',
        'collection',
        'qdrant_collection',
        'neo4j_namespace',
        'authorized_scope',
        'auth_context',
        'graph_enabled',
        'provider',
        'chat_model',
        'graph_model',
        'embedding_model',
        'vision_model',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ! (bool) $user->isRemoved;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dataset_id' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/'],
            'query' => ['required', 'string', 'max:4000'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_optimized' => ['sometimes', 'boolean'],
            'generate' => ['sometimes', 'boolean'],
            'fast_mode' => ['sometimes', 'boolean'],
            'smart_lookup' => ['sometimes', 'boolean'],
            'preferred_tags' => ['sometimes', 'array', 'max:20'],
            'preferred_tags.*' => ['string', 'max:80'],
            'filters' => ['sometimes', 'array', 'max:20'],
            'authorized_scope' => ['prohibited'],
            'auth_context' => ['prohibited'],
            'collection' => ['prohibited'],
            'qdrant_collection' => ['prohibited'],
            'qdrantCollection' => ['prohibited'],
            'neo4j_namespace' => ['prohibited'],
            'neo4jNamespace' => ['prohibited'],
            'graph_enabled' => ['prohibited'],
            'provider' => ['prohibited'],
            'chat_model' => ['prohibited'],
            'graph_model' => ['prohibited'],
            'embedding_model' => ['prohibited'],
            'vision_model' => ['prohibited'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $filters = $this->input('filters');
            if (! is_array($filters)) {
                return;
            }

            $invalidReason = $this->invalidFilterReason($filters);
            if ($invalidReason !== null) {
                $validator->errors()->add('filters', $invalidReason);
            }
        }];
    }

    public function authenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }

    /**
     * @param  array<array-key, mixed>  $filters
     */
    private function invalidFilterReason(array $filters): ?string
    {
        foreach ($filters as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                return 'The filters field must use named metadata keys.';
            }

            if (in_array($this->normalizeFilterKey($key), self::RESERVED_FILTER_KEYS, true)) {
                return 'The filters field contains a reserved authorization or storage key.';
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                return 'The filters field may contain only string, integer, finite float, or boolean values.';
            }

            if (is_float($value) && ! is_finite($value)) {
                return 'The filters field may contain only finite numeric values.';
            }
        }

        return null;
    }

    private function normalizeFilterKey(string $key): string
    {
        $snakeCase = preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key)) ?? $key;

        return strtolower(str_replace('-', '_', $snakeCase));
    }
}
