<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

abstract class AssistantDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $raw = $this->input('metadata_json');

            if ($raw === null || $raw === '') {
                return;
            }

            if (is_array($raw)) {
                return;
            }

            if (! is_string($raw)) {
                $validator->errors()->add('metadata_json', 'The metadata_json field must be a JSON object or JSON string.');

                return;
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $validator->errors()->add('metadata_json', 'The metadata_json field must contain valid JSON.');

                return;
            }

            if (! is_array($decoded)) {
                $validator->errors()->add('metadata_json', 'The metadata_json field must decode to an object or array.');
            }
        });
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantInput(bool $defaultGraph = false): array
    {
        return [
            'dataset_id' => $this->stringValue($this->input('dataset_id')),
            'display_name' => $this->displayName(),
            'display_name_provided' => $this->exists('display_name'),
            'source_url' => $this->sourceUrl(),
            'source_url_provided' => $this->exists('source_url'),
            'source_updated_at' => $this->sourceUpdatedAt(),
            'source_updated_at_provided' => $this->exists('source_updated_at'),
            'source_checksum_sha256' => $this->sourceChecksumSha256(),
            'source_checksum_sha256_provided' => $this->exists('source_checksum_sha256'),
            'graph_enabled' => $this->graphEnabled($defaultGraph),
            'graph_provided' => $this->exists('graph'),
            'metadata_json' => $this->assistantMetadata(),
            'metadata_json_provided' => $this->exists('metadata_json'),
            'force' => $this->forceUpdate(),
        ];
    }

    public function idempotencyKey(): ?string
    {
        return $this->stringValue($this->header('Idempotency-Key'));
    }

    public function displayName(): ?string
    {
        return $this->stringValue($this->input('display_name'));
    }

    public function sourceUrl(): ?string
    {
        return $this->stringValue($this->input('source_url'));
    }

    public function sourceUpdatedAt(): ?Carbon
    {
        $value = $this->input('source_updated_at');
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    public function sourceChecksumSha256(): ?string
    {
        $value = $this->stringValue($this->input('source_checksum_sha256'));

        return $value === null ? null : Str::lower($value);
    }

    public function graphEnabled(bool $default = false): bool
    {
        $value = filter_var($this->input('graph', $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assistantMetadata(): ?array
    {
        $raw = $this->input('metadata_json');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function forceUpdate(): bool
    {
        $value = filter_var($this->input('force', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? false;
    }

    protected function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
