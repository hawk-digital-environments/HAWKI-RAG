<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Services\Authorization\Connectors\StaticLmsPermissionConnector;
use App\Services\Authorization\Connectors\StudIpLmsPermissionConnector;
use App\Services\Authorization\Connectors\UnsupportedLmsPermissionConnector;
use App\Services\Authorization\Contracts\LmsPermissionConnector;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class LmsPermissionConnectorRegistry
{
    public function __construct(
        private ConfigRepository $config,
        private StaticLmsPermissionConnector $static,
        private StudIpLmsPermissionConnector $studip,
    ) {}

    public function default(): LmsPermissionConnector
    {
        return $this->forProvider((string) $this->config->get('authz.connectors.default', 'static'));
    }

    public function forProvider(string $provider): LmsPermissionConnector
    {
        return match (strtolower(trim($provider))) {
            'static', 'local', '' => $this->static,
            'studip' => $this->studip,
            'moodle' => new UnsupportedLmsPermissionConnector('moodle'),
            'ilias' => new UnsupportedLmsPermissionConnector('ilias'),
            'canvas' => new UnsupportedLmsPermissionConnector('canvas'),
            default => new UnsupportedLmsPermissionConnector($provider),
        };
    }
}
