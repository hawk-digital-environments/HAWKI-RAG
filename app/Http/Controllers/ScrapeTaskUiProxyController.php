<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class ScrapeTaskUiProxyController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): Response
    {
        $baseUrl = rtrim((string) config('scraper.task_ui_url'), '/');
        $targetUrl = $baseUrl.'/ui'.($path ? '/'.ltrim($path, '/') : '');

        $headers = collect($request->headers->all())
            ->except(['host', 'content-length'])
            ->mapWithKeys(fn (array $values, string $name): array => [$name => implode(', ', $values)])
            ->all();

        try {
            $proxied = Http::timeout(30)
                ->withHeaders($headers)
                ->send($request->method(), $targetUrl, [
                    'body' => $request->getContent(),
                    'query' => $request->query(),
                ]);
        } catch (ConnectionException $exception) {
            return response('CustomCrawler UI is unavailable: '.$exception->getMessage(), 502);
        }

        $response = response($proxied->body(), $proxied->status());

        foreach (['content-type', 'cache-control'] as $header) {
            $value = $proxied->header($header);

            if ($value !== null) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
