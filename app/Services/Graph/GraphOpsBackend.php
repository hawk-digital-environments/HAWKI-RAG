<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\Dataset\DatasetBackend;
use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use App\Services\Pipeline\PipelineExecutionBackend;
use Illuminate\Contracts\Config\Repository;

final readonly class GraphOpsBackend
{
    public function __construct(private Repository $config, private DatasetBackend $datasets,
        private PipelineExecutionBackend $pipelines) {}

    public function value(): string
    {
        $value = $this->config->get('config.graph_ops_backend', 'python');

        return is_string($value) ? $value : '';
    }

    public function valid(): bool
    {
        if ($this->value() === 'laravel') {
            return true;
        }
        try {
            return $this->value() === 'python'
                && $this->datasets->selected() === DatasetBackend::PYTHON
                && $this->config->get('config.pipeline_execution_backend', 'python') === 'python'
                && $this->pipelines->valid()
                && $this->config->get('config.python_query_dataset_source') === 'database'
                && self::validUrl($this->config->get('config.python_graph_ops_url'))
                && (int) $this->config->get('config.python_graph_ops_timeout', 10) > 0;
        } catch (DatasetBackendUnavailableException) {
            return false;
        }
    }

    public static function validUrl(mixed $url): bool
    {
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts) && ! preg_match('/[\x00-\x20\x7f]/', $url)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && rtrim($parts['path'] ?? '', '/') === '/v1/ops';
    }
}
