# Development setup

Create a local environment from `.env.example` and supply development-only
credentials. Reuse the Compose project name to keep the existing volumes.
See the [runtime guide](../Operations/python_runtime.md) for the local issuer,
database role, and optional crawler.

Install the Python dependencies and run the test suites.

```sh
make python-deps
make test-backend
make test-rag
```

## Choose a startup mode

Apply the schema migrations and provision the runtime grants before starting
either stack. `make up-core` uses the configured issuer. `make up-core-local`
adds a development issuer and dedicated Temporal queues.
