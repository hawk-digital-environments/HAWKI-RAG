<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineExecutionBackend
{
    public function __construct(private ConfigRepository $config) {}

    public function value(): string
    {
        return (string) $this->config->get('config.pipeline_execution_backend', 'python');
    }

    public function valid(): bool
    {
        if ($this->value() === 'laravel') {
            return true;
        }

        return $this->value() === 'python'
            && $this->config->get('config.crawler_ingestion_backend', 'python') === 'python'
            && $this->config->get('temporal.enabled') === true
            && is_string($this->config->get('temporal.callbacks.secret'))
            && $this->config->get('temporal.callbacks.secret') !== ''
            && $this->validUrl($this->config->get('temporal.control_url'))
            && $this->validUrl($this->config->get('config.python_url_ingestion_url'));
    }

    private function validUrl(mixed $url): bool
    {
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }
}
