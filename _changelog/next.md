# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)

### Bugfix

- **More reliable Qdrant health check.** The Qdrant container now uses a raw
  TCP socket probe instead of curl, working around a known Qdrant hang
  (qdrant/qdrant#4250) that could prevent the stack from starting cleanly.

### Internals

- Add three GitHub Actions workflows for an automated release pipeline:
  `create-release-branch` (manual branch cut), `trigger-release` (validates
  and merges), and `publish-version` (builds and pushes Docker images on tag,
  generates GitHub release).
- Split the monolithic root `Dockerfile` into per-service files:
  `docker/bridge.Dockerfile`, `docker/rerank.Dockerfile`, and
  `docker/migrator.Dockerfile`. The custom `docker/qdrant.Dockerfile` is
  removed in favour of the official `qdrant/qdrant` image.
- Published images are built for `linux/amd64` and `linux/arm64` with SLSA
  build attestations.
- Rename the shared-storage initialisation service from
  `hawki-rag-shared-storage-init` to `hawki_rag_migrator` and give it a
  lightweight dedicated image instead of the full Python RAG image.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
