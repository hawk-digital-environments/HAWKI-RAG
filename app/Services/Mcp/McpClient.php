<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class McpClient
{
    private string $baseUrl;
    private string $server;
    private int $timeout;
    private ?string $sessionId = null;

    public function __construct(string $baseUrl, string $server, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->server = $server;
        $this->timeout = $timeout;
    }

    public function initialize(?string $protocolVersion = null): array
    {
        $params = [];
        if ($protocolVersion !== null) {
            $params['protocolVersion'] = $protocolVersion;
        }

        return $this->request('initialize', $params);
    }

    public function listTools(?string $cursor = null, ?int $perPage = null): array
    {
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        if ($perPage !== null) {
            $params['per_page'] = $perPage;
        }

        return $this->request('tools/list', $params);
    }

    public function callTool(string $tool, array $arguments = []): array
    {
        return $this->request('tools/call', [
            'name' => $tool,
            'arguments' => $arguments,
        ]);
    }

    private function request(string $method, array $params): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => Str::uuid()->toString(),
            'method' => $method,
            'params' => $params,
        ];

        $request = Http::timeout($this->timeout)->acceptJson();
        if ($this->sessionId) {
            $request = $request->withHeaders(['Mcp-Session-Id' => $this->sessionId]);
        }

        $response = $request->post($this->endpoint(), $payload);
        $this->captureSessionId($response);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $response->body(),
            ];
        }

        if (isset($json['error'])) {
            return ['ok' => false, 'error' => $json['error']];
        }

        return $json['result'] ?? $json;
    }

    private function endpoint(): string
    {
        return $this->baseUrl.'/'.$this->server;
    }

    private function captureSessionId(Response $response): void
    {
        $sessionId = $response->header('Mcp-Session-Id');
        if ($sessionId) {
            $this->sessionId = $sessionId;
        }
    }
}
