# Laravel test commands

This directory contains Laravel unit, feature, system, and database-migration
tests. Python workspace tests are documented separately in
[`python_rag/tests/README.md`](../python_rag/tests/README.md).

## Standard Laravel suites

Run all Laravel tests:

```bash
php artisan test
```

Run one suite:

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=System
```

The system suite covers authenticated query, dataset isolation, and PDF upload
flows. It replaces external Python HTTP calls explicitly and does not require
live model or storage services.

## PostgreSQL migration upgrade test

The migration test is intentionally separate from the ordinary test suites. It
requires the Compose stack to be running and executes against PostgreSQL inside
the Laravel application container.

From the repository root:

```bash
docker compose --env-file .env exec -T hawki_rag_app sh -lc '\
  RUN_POSTGRES_MIGRATION_TESTS=1 \
  MIGRATION_TEST_ALLOW_SHARED_DATABASE=1 \
  MIGRATION_TEST_DB_HOST="${DB_HOST:-postgres}" \
  MIGRATION_TEST_DB_PORT="${DB_PORT:-5432}" \
  MIGRATION_TEST_DB_DATABASE="${DB_DATABASE:-hawki_rag}" \
  MIGRATION_TEST_DB_USERNAME="${DB_USERNAME:-rag_user}" \
  MIGRATION_TEST_DB_PASSWORD="${DB_PASSWORD:-}" \
  php artisan test tests/Feature/Database/PostgresMigrationUpgradeTest.php'
```

The Makefile retains `make system-test` and `make migration-test` as convenient
aliases for these Laravel workflows because the migration environment is easy
to invoke incorrectly by hand.
