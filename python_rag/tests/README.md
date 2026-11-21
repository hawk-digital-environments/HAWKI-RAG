# Python RAG tests

Run deterministic workspace tests using the [testing guide](../../_documentation/Developer/testing.md). PostgreSQL, provider and store integration tests require their explicit isolated configuration; do not point them at retained production/history data.

Live acceptance uses the configured Python service boundary and disposable datasets: managed/text/graph smoke, URL smoke with its isolated fixture overlay, and separate read-only cleanup audits. Service credentials stay server-side. See [deployment](../../_documentation/Operations/python_runtime.md) and [crawler operations](../../docker/crawler/README.md).

Some frozen integration adapters retain `RAWKI_INTEGRATION_LARAVEL_URL` and Laravel wire terminology as compatibility identifiers. They are not current deployment instructions and must not be renamed without changing their independent contract fixtures. No human login/token or historical runtime is needed for ordinary Python/frontend tests.
