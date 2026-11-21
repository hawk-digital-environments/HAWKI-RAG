# Run HAWKI RAG

Provision the database role/schema, issuer, registered service clients, dataset grants, models and shared storage described in the [deployment guide](../Operations/python_runtime.md).

## Choose a startup mode

```sh
make help
make up-core-local
make test-frontend
```

`make up-core-local` uses the explicit Python local overlay. `make up-core` uses base deployment settings. Neither implicitly runs migrations. Rebuild changed images and recreate their consumers; when backend/gateway addresses change, refresh frontend nginx routing. Preserve all persisted volumes and historical reservations.

Verify `python -m hawki_backend.readiness --live` inside the backend container: all 26 checks must pass. Use `python_backend/scripts/cutover_readiness.py --live` for the read-only deployment audit.

The [testing guide](../Developer/testing.md) documents disposable managed/text/URL/graph smoke checks and separate read-only cleanup audits. They require real configured services. Never bypass the crawler contract or delete historical content to make a probe pass.
