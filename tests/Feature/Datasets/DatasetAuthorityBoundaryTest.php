<?php

declare(strict_types=1);

namespace Tests\Feature\Datasets;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\DatasetGrantTokenService;
use App\Services\Authorization\Repositories\DatasetGrantRepository;
use App\Services\Authorization\Values\AuthenticatedPrincipal;
use App\Services\Dataset\DatasetBackend;
use App\Services\Dataset\DatasetRepository;
use App\Services\Dataset\DatasetService;
use App\Services\Dataset\Exceptions\DatasetBackendUnavailableException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Python dataset mode keeps the old write paths inert.
 *
 * The BFF routes are covered separately. These tests exercise the remaining
 * command, service and repository writers that exist for legacy compatibility:
 * none of them may create a second authority while Python owns the tables.
 */
final class DatasetAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_grant_commands_refuse_python_authority(): void
    {
        $this->enablePythonAuthority();
        $user = $this->user();
        $this->dataset('legacy-a');

        $this->artisan('dataset:grant-query', [
            'dataset_id' => 'legacy-a',
            'user_id' => (string) $user->getAuthIdentifier(),
        ])
            ->expectsOutput('Dataset grants are owned by the Python dataset authority.')
            ->assertExitCode(1);

        $this->artisan('dataset:grant-ingest', [
            'dataset_id' => 'legacy-a',
            'user_id' => (string) $user->getAuthIdentifier(),
        ])
            ->expectsOutput('Dataset grants are owned by the Python dataset authority.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_legacy_grant_commands_refuse_an_invalid_selector(): void
    {
        config()->set('config.dataset_backend', 'typo');
        $user = $this->user();
        $this->dataset('legacy-a');

        $this->artisan('dataset:grant-query', [
            'dataset_id' => 'legacy-a',
            'user_id' => (string) $user->getAuthIdentifier(),
        ])
            ->expectsOutput('Dataset grants are owned by the Python dataset authority.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('dataset_grants', 0);
    }

    public function test_the_cleanup_gate_treats_python_and_invalid_selectors_as_not_laravel(): void
    {
        $backend = app(DatasetBackend::class);

        config()->set('config.dataset_backend', 'laravel');
        $this->assertTrue($backend->laravelOwnsAuthority());

        $this->enablePythonAuthority();
        $this->assertFalse($backend->laravelOwnsAuthority());

        config()->set('config.dataset_backend', 'typo');
        $this->assertFalse($backend->laravelOwnsAuthority());
    }

    public function test_python_mode_does_not_register_the_legacy_cleanup_schedule(): void
    {
        $this->assertNotContains(
            'dataset-cleanup',
            $this->scheduledEvents('python'),
        );
    }

    public function test_an_invalid_selector_does_not_register_the_legacy_cleanup_schedule(): void
    {
        $this->assertNotContains(
            'dataset-cleanup',
            $this->scheduledEvents('typo'),
        );
    }

    public function test_laravel_mode_still_registers_the_legacy_cleanup_schedule(): void
    {
        $this->assertContains(
            'dataset-cleanup',
            $this->scheduledEvents('laravel'),
        );
    }

    public function test_every_legacy_write_path_refuses_python_authority(): void
    {
        $this->enablePythonAuthority();
        $dataset = $this->dataset('legacy-a');
        $user = $this->user();
        $principal = AuthenticatedPrincipal::tryFromUserIdentifier(
            $user->getAuthIdentifier(),
        );
        $this->assertNotNull($principal);

        $this->assertReadOnly(fn () => app(DatasetService::class)->create([
            'dataset_id' => 'legacy-b',
        ]));
        $this->assertReadOnly(fn () => app(DatasetService::class)->delete('legacy-a'));
        $this->assertReadOnly(fn () => app(DatasetService::class)->cleanup('legacy-a'));
        $this->assertReadOnly(fn () => app(DatasetService::class)->resumeArchivedDeletions());
        $this->assertReadOnly(fn () => app(DatasetRepository::class)->firstOrCreate(
            'legacy-b',
            [
                'dataset_id' => 'legacy-b',
                'name' => 'Legacy B',
                'status' => Dataset::STATUS_ACTIVE,
                'qdrant_collection' => 'hawki_legacy_b',
                'neo4j_namespace' => 'hawki_legacy_b',
                'created_at' => now(),
            ],
        ));
        $this->assertReadOnly(fn () => app(DatasetRepository::class)->archiveForDeletion('legacy-a'));
        $this->assertReadOnly(fn () => app(DatasetRepository::class)->completeDeletion($dataset));
        $this->assertReadOnly(fn () => app(DatasetGrantTokenService::class)->issue($dataset));
        $this->assertReadOnly(fn () => app(DatasetGrantTokenService::class)->redeem(
            $dataset,
            $user,
            'dgt_'.str_repeat('e', 64),
        ));
        $this->assertReadOnly(fn () => app(DatasetGrantRepository::class)->grant(
            $dataset,
            $principal,
            'query',
        ));

        // The legacy migration seeds "default"; only the test fixture adds one
        // more row, and no writer may change either of them.
        $this->assertDatabaseCount('datasets', 2);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'legacy-a', 'status' => Dataset::STATUS_ACTIVE]);
        $this->assertDatabaseCount('dataset_grants', 0);
        $this->assertDatabaseCount('dataset_grant_tokens', 0);
    }

    private function enablePythonAuthority(): void
    {
        config()->set([
            'config.dataset_backend' => 'python',
            'config.query_backend' => 'python',
            'config.text_ingestion_backend' => 'python',
            'config.managed_document_backend' => 'python',
            'config.crawler_ingestion_backend' => 'python',
            'config.query_auth.all_datasets_by_default' => false,
        ]);
    }

    /**
     * Resolve a fresh application with the given selector so the schedule is
     * built from scratch rather than from the already-booted test schedule.
     *
     * @return list<string>
     */
    private function scheduledEvents(string $selector): array
    {
        $saved = $this->setEnvironment('DATASET_BACKEND', $selector);
        $fresh = null;

        try {
            $fresh = require base_path('bootstrap/app.php');
            $kernel = $fresh->make(Kernel::class);
            $kernel->bootstrap();
            $this->assertSame(0, $kernel->call('schedule:list', ['--json' => true]));

            return array_values(array_filter(array_map(
                static fn (object $event): string => is_string($event->description)
                    ? $event->description
                    : '',
                $fresh->make(Schedule::class)->events(),
            )));
        } finally {
            $fresh?->flush();
            $this->restoreEnvironment('DATASET_BACKEND', $saved);
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
            Application::setInstance($this->app);
        }
    }

    /**
     * @return array{env: string|null, server: string|null, process: string|false}
     */
    private function setEnvironment(string $name, string $value): array
    {
        $saved = [
            'env' => array_key_exists($name, $_ENV) ? (string) $_ENV[$name] : null,
            'server' => array_key_exists($name, $_SERVER) ? (string) $_SERVER[$name] : null,
            'process' => getenv($name),
        ];
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);

        return $saved;
    }

    /**
     * @param  array{env: string|null, server: string|null, process: string|false}  $saved
     */
    private function restoreEnvironment(string $name, array $saved): void
    {
        if ($saved['env'] === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $saved['env'];
        }
        if ($saved['server'] === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $saved['server'];
        }
        if ($saved['process'] === false) {
            putenv($name);
        } else {
            putenv($name.'='.$saved['process']);
        }
    }

    private function assertReadOnly(callable $write): void
    {
        try {
            $write();
        } catch (DatasetBackendUnavailableException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame(
                'Dataset metadata is owned by the Python dataset authority.',
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('A legacy dataset writer was allowed to run in Python mode.');
    }

    private function user(): User
    {
        return User::query()->create([
            'username' => 'legacy-writer',
            'email' => 'legacy-writer@example.test',
            'ip' => '127.0.0.99',
        ]);
    }

    private function dataset(string $datasetId): Dataset
    {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => 'Legacy A',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_'.$datasetId,
            'neo4j_namespace' => 'hawki_'.$datasetId,
            'created_at' => now(),
        ]);
    }
}
