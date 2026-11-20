# Feature test organization

The feature suite is grouped by user-visible capability or application boundary. PHPUnit discovers every `*Test.php` file recursively through the existing `tests/Feature` test-suite configuration.

| Feature set | Scope | Test classes | Test methods |
| --- | --- | ---: | ---: |
| `ApiContract` | OpenAPI completeness, route drift, descriptions, authentication declarations, and response schemas | 1 | 4 |
| `Authentication` | Browser sessions, development-only query access, route authorization, CORS, and security headers | 3 | 29 |
| `Datasets` | Dataset lifecycle, statistics, storage, and deletion behavior | 1 | 4 |
| `Documents` | Document persistence, browsing, uploads, unified API behavior, and downloads | 3 | 20 |
| `Graph` | Dataset-scoped graph retrieval, graph explorer UI, and graph reporting | 3 | 5 |
| `Pipeline` | Pipeline UI, commands, repositories, state transitions, recovery, uploads, and stage logs | 7 | 43 |
| `Query` | Authorized dataset-scoped querying and the browser playground | 3 | 15 |
| `Scraping` | Scraper task APIs and the proxied scraper UI | 2 | 5 |
| `Settings` | Runtime provider, Ollama, LiteLLM, and converter settings | 1 | 7 |
| `Ui` | Top-level admin experience routes and Svelte shell mounting | 1 | 3 |
| **Total** | **10 feature sets** | **25** | **135** |

## Running the suite

Run all feature tests:

```bash
php artisan test tests/Feature
```

Run one feature set:

```bash
php artisan test tests/Feature/Query
php artisan test tests/Feature/Pipeline
```

Run one test class:

```bash
php artisan test tests/Feature/Query/DatasetScopedQueryTest.php
```

Cross-boundary vertical scenarios live in `tests/System`. They exercise the
real Laravel authentication, authorization, persistence, and orchestration
boundary while explicitly replacing external Python HTTP calls:

```bash
php artisan test --testsuite=System
```

See the root [test command guide](../README.md) for system-test and PostgreSQL
migration-test prerequisites and commands.

When adding a test, place it in the directory representing the behavior under test. Prefer the user-visible feature over the implementation layer; for example, a query authorization test belongs in `Query` unless it primarily verifies authentication/session behavior.
