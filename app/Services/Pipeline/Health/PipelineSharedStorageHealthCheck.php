<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineSharedStorageHealthCheck
{
    public function __construct(
        private Application $app,
        private ConfigRepository $config,
        private Filesystem $files,
    ) {}

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(): array
    {
        $sharedRoot = (string) $this->config->get('temporal.storage.shared_root');
        $paths = array_values(array_unique(array_filter([
            $sharedRoot,
            (string) $this->config->get('scraper.storage_path'),
            (string) $this->config->get('config.shared_root'),
        ])));

        foreach ($paths as $path) {
            if (! $this->files->isDirectory($path)) {
                return $this->failureResult(
                    'Shared storage',
                    "Path does not exist: {$path}.",
                    'Create the shared path or mount the Docker shared_storage volume at the configured path.',
                );
            }

            if (! $this->files->isWritable($path)) {
                return $this->failureResult(
                    'Shared storage',
                    "Path is not writable: {$path}.",
                    $this->permissionFix($path).' Then verify HAWKI_RAG_TEMPORAL_SHARED_ROOT, SCRAPE_STORAGE_PATH, and HAWKI_RAG_PIPELINE_ROOT.',
                );
            }

            $probe = $path.DIRECTORY_SEPARATOR.'.pipeline-health-'.bin2hex(random_bytes(6));
            try {
                $this->files->put($probe, 'ok');
                $this->files->delete($probe);
            } catch (\Throwable $exception) {
                return $this->failureResult(
                    'Shared storage',
                    "Could not create a probe file in {$path}: {$exception->getMessage()}",
                    $this->permissionFix($path),
                );
            }

            if ($this->samePath($path, $sharedRoot)) {
                $workerSourceFailure = $this->workerSourceDirectoryFailure($sharedRoot);
                if ($workerSourceFailure !== null) {
                    return $this->failureResult(
                        'Shared storage',
                        $workerSourceFailure,
                        $this->permissionFix($sharedRoot),
                    );
                }
            }

            $webUserError = $this->webUserError($path);
            if ($webUserError !== null) {
                return $this->failureResult(
                    'Shared storage',
                    $webUserError,
                    $this->permissionFix($path).' Also verify PIPELINE_SHARED_STORAGE_WEB_USER names the PHP-FPM user.',
                );
            }
        }

        return $this->ok('Shared storage', 'Writable paths: '.implode(', ', $paths).'.');
    }

    private function workerSourceDirectoryFailure(string $sharedRoot): ?string
    {
        $sourcesRoot = rtrim($sharedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sources';
        if (! $this->files->isDirectory($sourcesRoot)) {
            return null;
        }

        try {
            $sourceDirectories = $this->files->directories($sourcesRoot);
        } catch (\Throwable $exception) {
            return "Could not inspect worker-created source directories in {$sourcesRoot}: {$exception->getMessage()}";
        }

        foreach ($sourceDirectories as $sourceDirectory) {
            if ($this->files->isWritable($sourceDirectory)) {
                continue;
            }

            return "Worker-created source directory is not writable by the PHP process: {$sourceDirectory}. "
                .'Directory mode bits alone can be misleading when a named POSIX ACL overrides shared-group permissions.';
        }

        return null;
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim($left, DIRECTORY_SEPARATOR) === rtrim($right, DIRECTORY_SEPARATOR);
    }

    private function permissionFix(string $path): string
    {
        return "Inspect {$path} with getfacl. Repair or remove restrictive named/default POSIX ACL entries for the PHP-FPM user, "
            .'then ensure PHP-FPM and the workers share PIPELINE_SHARED_STORAGE_GID and restore group rwX plus setgid permissions on shared directories.';
    }

    private function webUserError(string $path): ?string
    {
        $webUser = trim((string) $this->config->get('temporal.storage.shared_storage_web_user', ''));
        if ($webUser === '' || $this->app->environment('testing')) {
            return null;
        }

        if (! function_exists('posix_getpwnam')) {
            return null;
        }

        $user = posix_getpwnam($webUser);
        if (! is_array($user)) {
            return "Configured shared storage web user {$webUser} does not exist in this container.";
        }

        $owner = fileowner($path);
        $group = filegroup($path);
        $mode = fileperms($path);
        if ($owner === false || $group === false || $mode === false) {
            return "Could not read ownership for shared storage path {$path}.";
        }

        $uid = (int) ($user['uid'] ?? -1);
        $gid = (int) ($user['gid'] ?? -1);
        $mode = $mode & 0777;
        $canWriteAsOwner = (int) $owner === $uid && ($mode & 0300) === 0300;
        $canWriteAsGroup = (int) $group === $gid && ($mode & 0030) === 0030;
        $canWriteAsOther = ($mode & 0003) === 0003;

        if ($canWriteAsOwner || $canWriteAsGroup || $canWriteAsOther) {
            return null;
        }

        return sprintf(
            'Path %s is writable by the current CLI process, but not by %s (uid %d, gid %d). Current owner/group is %d:%d with mode %s.',
            $path,
            $webUser,
            $uid,
            $gid,
            (int) $owner,
            (int) $group,
            decoct($mode),
        );
    }

    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
