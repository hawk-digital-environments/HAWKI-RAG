<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Services\ScrapeService\Data\ScrapeJobRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class ScrapeExecutionService
{
    /**
     *
     * @param callable|null $outputCallback Optional callback for streaming output (callable(string $type, string $buffer))
     * @return array success and message
     * @throws ConnectionException
     */
    public function execute(ScrapeJobRequest $requestConfig, ?callable $outputCallback = null): array
    {
        try{
            $response = Http::timeout(300)
                ->retry(3, 1000, throw: false)
                ->post(config('scraper.api_url') . '/crawl',
                    $requestConfig->toArray());

            $data = $this->decodeJsonResponse($response->body());

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => $this->errorMessageFromResponse($data, $response->status()),
                ];
            }

            if (!isset($data['event']) || $data['event'] !== 'job_submitted') {
                return [
                    'success' => false,
                    'message' => $this->errorMessageFromResponse($data, $response->status()),
                ];
            }

            if (($data['job_id'] ?? null) !== $requestConfig->jobId) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Crawler response job_id mismatch. Expected %s, got %s.',
                        $requestConfig->jobId,
                        is_scalar($data['job_id'] ?? null) ? (string) $data['job_id'] : 'missing'
                    ),
                ];
            }

            return [
                'success' => true,
                'message' => $data['data']['message'] ?? 'Crawl job submitted successfully',
            ];
        } catch (JsonException $e) {
            return [
                'success' => false,
                'message' => 'Crawler returned invalid JSON: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @throws JsonException
     */
    private function decodeJsonResponse(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new JsonException('Expected JSON object response.');
        }

        return $data;
    }

    private function errorMessageFromResponse(array $data, int $status): string
    {
        if (isset($data['data']['message']) && is_scalar($data['data']['message'])) {
            return (string) $data['data']['message'];
        }

        if (isset($data['message']) && is_scalar($data['message'])) {
            return (string) $data['message'];
        }

        if (isset($data['detail'])) {
            return 'Crawler request failed with HTTP '.$status.': '.$this->formatFastApiDetail($data['detail']);
        }

        return 'Crawler request failed with HTTP '.$status.'.';
    }

    private function formatFastApiDetail(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (!is_array($detail)) {
            return json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unknown error';
        }

        $messages = [];
        foreach ($detail as $item) {
            if (!is_array($item)) {
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
