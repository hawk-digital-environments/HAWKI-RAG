<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Routing\UrlGenerator as RoutingUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class UrlGenerator
{
    public function __construct(
        protected array $config,
        protected Filesystem $disk,
        protected RoutingUrlGenerator $urls,
        protected ClockInterface $clock = new Clock(),
    ) {}

    public function generate(string $path): string
    {
        return match ($this->config['driver']) {
            's3', 'webdav' => $this->generateTemporaryUrl($path),
            'local' => $this->generateLocalUrl($path),
            'sftp' => $this->generateSftpUrl($path),
            default => $this->generateDefaultUrl($path),
        };
    }

    private function generateLocalUrl(string $path): string
    {
        if (($this->config['visibility'] ?? null) === 'public' && method_exists($this->disk, 'url')) {
            return (string) call_user_func([$this->disk, 'url'], $path);
        }

        return $this->urls->temporarySignedRoute(
            "files.download",
            $this->expiresAt(),
            [
                'disk' => $this->disk,
            ]
        );
    }

    private function generateSftpUrl(string $path): string
    {
        return $this->urls->temporarySignedRoute(
            "files.download",
            $this->expiresAt(),
            [
                'path'=> base64_encode($path),
                'disk'=> $this->disk,
            ]
        );
    }

    private function generateTemporaryUrl(string $path): string
    {
        if (method_exists($this->disk, 'temporaryUrl')) {
            return (string) call_user_func([$this->disk, 'temporaryUrl'], $path, $this->expiresAt());
        }
        return $this->generateDefaultUrl($path);
    }

    private function generateDefaultUrl(string $path): string
    {
        if (method_exists($this->disk, 'url')) {
            return (string) call_user_func([$this->disk, 'url'], $path);
        }

        return $this->urls->temporarySignedRoute(
            "files.download",
            $this->expiresAt(),
            [
                'path'     => base64_encode($path),
                'disk'     => $this->disk,
            ]
        );
    }

    private function expiresAt(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('+24 hours');
    }
}
