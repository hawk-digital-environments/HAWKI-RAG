# Upgrade to v%%VERSION%%

[//]: # (Add administrator actions only when an upgrade requires manual migration, configuration, environment, or operational changes.)

## Service name change

The shared-storage initialisation service has been renamed from
`hawki-rag-shared-storage-init` to `hawki_rag_migrator`. Any custom
`docker-compose` overrides, monitoring rules, or scripts that reference the
old service name must be updated.

## Dockerfile paths

The root `Dockerfile` has been replaced by per-service files under `docker/`.
If you build images locally outside of `docker-compose`, update your build
commands:

| Old | New |
|---|---|
| `docker build --target python-rag .` | `docker build -f docker/bridge.Dockerfile .` |
| `docker build --target rerank .` | `docker build -f docker/rerank.Dockerfile .` |
| *(shared-storage init)* | `docker build -f docker/migrator.Dockerfile .` |

The custom Qdrant image (`docker/qdrant.Dockerfile`) has been removed. The
stack now uses the official `qdrant/qdrant:v1.18.3` image directly; no local
build step is required.
