# Development setup

Create a local environment from `.env.example` and supply development-only
credentials. Keep the same Compose project name when reusing local volumes.
Use the [runtime guide](../Operations/python_runtime.md) for the local issuer,
database role, and optional crawler.

```sh
make python-deps
make test-backend
make test-rag
```

`make up-core` starts the headless stack using the configured issuer.
`make up-core-local` uses the explicit local API overlay with a development
issuer and a reviewed crawler. Apply schema migrations and provision runtime
grants before startup.
