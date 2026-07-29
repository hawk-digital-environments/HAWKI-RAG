<?php

declare(strict_types=1);

namespace App\Services\Scrape\Clients;

use App\Services\Scrape\Exceptions\ScrapeResponseException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\Response;

#[Singleton]
readonly class ScrapeCrawlerResponseMapper
{
    public function requestResult(Response $response): array
    {
        $data = $this->decodeJsonResponse($response->body());
        $success = $response->successful();

        return [
            'success' => $success,
            'status' => $response->status(),
            'data' => $data,
            'message' => $success
                ? $this->successMessageFromData($data)
                : $this->errorMessageFromData($data, $response->status()),
        ];
    }

    public function invalidJsonResult(\Throwable $exception): array
    {
        return [
            'success' => false,
            'status' => 502,
            'data' => null,
            'message' => 'Crawler returned invalid JSON: '.$exception->getMessage(),
        ];
    }

    public function exceptionResult(\Throwable $exception): array
    {
        return [
            'success' => false,
            'status' => 502,
            'data' => null,
            'message' => $exception->getMessage(),
        ];
    }

    /**
     * @throws \JsonException
     */
    private function decodeJsonResponse(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw ScrapeResponseException::expectedJsonObject('crawler');
        }

        return $data;
    }

    private function successMessageFromData(array $data): string
    {
        foreach (['message', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return 'Crawler request completed successfully.';
    }

    private function errorMessageFromData(array $data, int $status): string
    {
        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.$this->formatFastApiDetail($data['detail']);
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['error']) && is_scalar($data['error']) && trim((string) $data['error']) !== '') {
            return (string) $data['error'];
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }

    private function formatFastApiDetail(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (! is_array($detail)) {
            return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
        }

        $messages = [];
        foreach ($detail as $item) {
            if (! is_array($item)) {
                continue;
            }

            $location = $item['loc'] ?? [];
            $path = is_array($location) ? implode('.', array_map('strval', $location)) : (string) $location;
            $message = is_scalar($item['msg'] ?? null) ? (string) $item['msg'] : 'validation error';
            $messages[] = $path !== '' ? "{$path}: {$message}" : $message;
        }

        return $messages === []
            ? (json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown validation error')
            : implode('; ', $messages);
    }
}
