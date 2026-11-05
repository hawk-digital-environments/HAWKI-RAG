<?php

declare(strict_types=1);

namespace App\Services\Document\Clients;

use App\Models\User;
use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Psr\Clock\ClockInterface;

#[Singleton]
readonly class PythonManagedDocumentClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private QueryTokenSigner $signer,
        private ClockInterface $clock,
    ) {}

    public function read(User $user, ?string $documentId = null, array $query = []): array
    {
        return $this->readRequest($user, 'GET', '/v1/documents'.($documentId === null ? '' : '/'.rawurlencode($documentId)), ['query' => $query], $documentId === null ? 'documents' : 'document');
    }

    public function legacyReadAccess(User $user, array $candidates): array
    {
        return $this->readRequest($user, 'POST', '/v1/documents/read-access', ['json' => ['documents' => $candidates]], 'legacy_document_ids');
    }

    private function readRequest(User $user, string $method, string $path, array $options, string $field): array
    {
        try {
            $token = $this->signer->sign($user);
            $response = $this->http->acceptJson()->withToken($token)->timeout($this->timeout())->connectTimeout(3)
                ->withoutRedirecting()->withOptions(['cookies' => false, 'http_errors' => false])
                ->send($method, $this->url($path), $options);
            $body = $this->safeBody($response, $token);
            if ($response->status() === 200 && is_array($body[$field] ?? null)) {
                return ['status' => 200, 'payload' => $body];
            }

            return $this->failure(in_array($response->status(), [401, 403, 404, 422, 503], true) ? $response->status() : 502);
        } catch (\Throwable) {
            return $this->failure(502);
        }
    }

    /** @param list<UploadedFile> $files */
    public function mutate(User $user, string $purpose, array $input, array $files, ?string $key, ?string $documentId = null): array
    {
        $streams = [];
        try {
            $token = $this->signer->signIngest($user);
            $path = '/v1/documents'.($purpose === 'batch' ? '/batch' : ($documentId === null ? '' : '/'.rawurlencode($documentId)));
            $method = match ($purpose) {
                'update' => 'PUT', 'delete' => 'DELETE', default => 'POST'
            };
            $request = $this->http->acceptJson()->withToken($token)->timeout($this->timeout())->connectTimeout(3)
                ->withoutRedirecting()->withOptions(['cookies' => false, 'http_errors' => false]);
            if ($key !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $key]);
            }
            foreach ($files as $file) {
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    return $this->failure(422);
                }
                $streams[] = $stream;
                $name = $file->getClientOriginalName();
                $originalName = $name;
                // Preserve Laravel's MIME extension fallback without MIME rejection.
                if ($file->getClientOriginalExtension() === '' && $file->extension()) {
                    $name .= '.'.$file->extension();
                }
                $request = $request->attach($purpose === 'batch' ? 'files[]' : 'file', $stream, $name, ['X-Hawki-Original-Filename' => rawurlencode($originalName)]);
            }
            $response = $request->send($method, $this->url($path), $purpose === 'delete' ? [] : ['multipart' => $this->fields($input)]);

            return $this->response($response, $token, $purpose);
        } catch (\Throwable) {
            // Never log exceptions containing HTTP request headers, upload data or JWTs.
            return $this->failure(502);
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }
    }

    public function relay(string $raw, string $kind): array
    {
        try {
            $secret = $this->config->get('temporal.callbacks.secret');
            if (! is_string($secret) || $secret === '' || ! in_array($kind, ['events', 'writers', 'ownership'], true)) {
                return $this->failure(503);
            }
            $stamp = (string) $this->clock->now()->getTimestamp();
            $response = $this->http->acceptJson()->timeout($this->timeout())->connectTimeout(3)
                ->withoutRedirecting()->withOptions(['cookies' => false, 'http_errors' => false])
                ->withHeaders(['X-Hawki-Timestamp' => $stamp, 'X-Hawki-Signature' => 'v1='.hash_hmac('sha256', $stamp.'.'.$raw, $secret)])
                ->withBody($raw, 'application/json')->post($this->url('/internal/managed-documents/'.$kind));
            $body = $this->safeBody($response, $secret);
            if (! $response->successful() || $body === null) {
                return $this->failure(in_array($response->status(), [409, 422, 503], true) ? $response->status() : 502);
            }
            if ($kind === 'ownership' && ! in_array($body['owner'] ?? null, ['python', 'laravel'], true)) {
                return $this->failure(502);
            }
            if ($kind === 'writers' && (($body['success'] ?? null) !== true || ! in_array($body['status'] ?? null, ['active', 'released', 'uncertain'], true) || ! is_array($body['targets'] ?? null))) {
                return $this->failure(502);
            }
            if ($kind === 'events' && (($body['accepted'] ?? null) !== true || ! is_bool($body['duplicate'] ?? null))) {
                return $this->failure(502);
            }

            return ['status' => $response->status(), 'payload' => $body];
        } catch (\Throwable) {
            return $this->failure(502);
        }
    }

    private function fields(array $input): array
    {
        $fields = [];
        foreach ($input as $name => $value) {
            $fields[] = ['name' => $name, 'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value)];
        }

        return $fields;
    }

    private function response(Response $response, #[\SensitiveParameter] string $token, string $purpose): array
    {
        $status = $response->status();
        $body = $this->safeBody($response, $token);
        if ($body === null) {
            return $this->failure(502);
        }
        if ($status === 422 && ($body['success'] ?? null) === false
            && in_array($body['message'] ?? null, [
                'This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats.',
                'Custom converter uploads still need a file extension so the pipeline can preserve type metadata.',
            ], true) && is_array($body['accepted_extensions'] ?? null)) {
            return ['status' => 422, 'payload' => ['success' => false, 'message' => $body['message'], 'accepted_extensions' => $body['accepted_extensions']]];
        }
        if ($status === 500 && ($body['success'] ?? null) === false
            && ($body['message'] ?? null) === 'The uploaded file could not be stored. No dataset, task, or job was created.') {
            return ['status' => 500, 'payload' => ['success' => false, 'message' => $body['message'], 'dataset_id' => $body['dataset_id'] ?? null, 'task_id' => null, 'job_id' => null, 'error' => null]];
        }
        if ($purpose === 'batch' && in_array($status, [202, 207], true)
            && is_array($body['items'] ?? null) && is_array($body['summary'] ?? null)
            && ($body['operation']['type'] ?? null) === 'batch_create') {
            return ['status' => $status, 'payload' => $body];
        }
        if (in_array($status, [200, 202, 409, 502], true) && is_bool($body['success'] ?? null)
            && ($body['operation']['type'] ?? null) === $purpose && is_array($body['document'] ?? null)) {
            return ['status' => $status, 'payload' => $body];
        }

        return $this->failure(in_array($status, [401, 403, 404, 409, 413, 422, 503], true) ? $status : 502);
    }

    private function safeBody(Response $response, #[\SensitiveParameter] string $secret): ?array
    {
        $raw = $response->body();
        if ($secret !== '' && str_contains($raw, $secret)) {
            return null;
        }
        $body = json_decode($raw, true);
        $normalized = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : false;
        if (! is_string($normalized) || ($secret !== '' && str_contains($normalized, $secret))) {
            return null;
        }

        return is_array($body) ? $body : null;
    }

    private function url(string $path): string
    {
        $base = $this->config->get('config.python_managed_document_url');
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \LogicException('Managed document backend URL is invalid.');
        }

        return rtrim($base, '/').$path;
    }

    private function timeout(): int
    {
        return max(1, min(300, (int) $this->config->get('config.python_managed_document_timeout', 30)));
    }

    private function failure(int $status): array
    {
        return ['status' => $status, 'payload' => ['success' => false, 'message' => match ($status) {
            401 => 'Unauthenticated.', 403 => 'Forbidden.', 404 => 'Document was not found.',
            409 => 'Managed document operation conflicts with current ownership.',
            413 => 'The upload request is too large.', 422 => 'The given data was invalid.',
            default => 'Managed document ingestion is unavailable.',
        }]];
    }
}
