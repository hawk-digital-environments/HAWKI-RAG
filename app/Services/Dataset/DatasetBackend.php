<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The selected dataset authority.
 *
 * Laravel is the default authority. When Python is selected, Laravel keeps its
 * public BFF routes and legacy compatibility reads but no longer writes
 * dataset metadata, grants or grant tokens. An unknown selector never falls
 * back: every dataset operation fails closed.
 */
#[Singleton]
readonly class DatasetBackend
{
    public const LARAVEL = 'laravel';

    public const PYTHON = 'python';

    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * Return the selected authority, refusing an unknown or incompatible selector.
     *
     * Python ownership is only a valid activation when every ingestion and
     * query surface is already routed to Python and legacy query-all is off.
     * A partially activated deployment would leave a second writer or a
     * wildcard authorization path, so it fails closed instead of falling back.
     */
    public function selected(): string
    {
        $configured = $this->config->get('config.dataset_backend', self::LARAVEL);
        if (! is_string($configured)) {
            throw DatasetBackendUnavailableException::configurationInvalid();
        }

        $selected = match (strtolower(trim($configured))) {
            self::LARAVEL => self::LARAVEL,
            self::PYTHON => self::PYTHON,
            default => throw DatasetBackendUnavailableException::configurationInvalid(),
        };
        if ($selected === self::PYTHON) {
            $this->assertPythonActivation();
        }

        return $selected;
    }

    /**
     * Refuse a Python activation that is not complete across every selector.
     */
    private function assertPythonActivation(): void
    {
        foreach ([
            'query_backend',
            'text_ingestion_backend',
            'managed_document_backend',
            'crawler_ingestion_backend',
        ] as $key) {
            $value = $this->config->get("config.{$key}");
            if (! is_string($value) || strtolower(trim($value)) !== self::PYTHON) {
                throw DatasetBackendUnavailableException::configurationInvalid();
            }
        }
        if ($this->config->get('config.query_auth.all_datasets_by_default') !== false) {
            throw DatasetBackendUnavailableException::configurationInvalid();
        }
    }

    public function usesPython(): bool
    {
        return $this->selected() === self::PYTHON;
    }

    /**
     * Whether this process may still perform legacy dataset writes.
     *
     * An unknown selector never enables legacy writes: the schedule and CLI
     * paths stay inert while public requests fail closed with a 503.
     */
    public function laravelOwnsAuthority(): bool
    {
        try {
            return $this->selected() === self::LARAVEL;
        } catch (DatasetBackendUnavailableException) {
            return false;
        }
    }

    /**
     * Refuse a legacy write when Python owns the dataset authority.
     */
    public function assertLegacyWriter(): void
    {
        if ($this->selected() !== self::LARAVEL) {
            throw DatasetBackendUnavailableException::readOnly();
        }
    }
}
