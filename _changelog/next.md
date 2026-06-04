# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)
- Simplify the HAWKI RAG pipeline to a Laravel-orchestrated MVP using RabbitMQ event workers for scrape, convert, and ingest.
- Remove the legacy converted-document ingestion worker path in favor of the MVP event names.
- Add Neo4j graph visualization to the HAWKI RAG playground, including live graph snapshots, relationship counts, recently added triplet highlighting, and graph clearing controls.
- Add graph-only ingestion mode for writing Neo4j triplets without running the full vector embedding flow.
- Add document and pipeline state persistence foundations with new `documents` and `job_processing_state` models and migrations.
- Replace phpMyAdmin with Adminer for a lighter database administration container.
- Add a RabbitMQ service with management UI to the core Docker stack.
- Add a crawler producer profile for RabbitMQ-based crawler event publishing.

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)
- Introduce `docker-compose.local.yml` for local overrides, allowing developers to run the stack locally without modifying the default Compose files.
- Add Makefile targets for local/server core startup, health checks, RabbitMQ publishing, crawl/convert/ingest flows, and Neo4j reset.
- Reorganize `.env.example` into clearer sections for application, database, RabbitMQ, RAG, Neo4j, and search configuration.
- Split the playground JavaScript into focused modules for ingestion, logs/stats, query handling, and graph visualization.
- Add documentation for RabbitMQ ingestion, database operations, and pipeline state tracking.
- Improve playground ingestion controls and status polling for default and Neo4j-specific ingestion modes.

### Bugfix

- Fix ARM compatibility issues in Docker builds, including Ollama and database admin tooling.
- Fix gateway/Laravel URL handling by making `DOCKER_PROJECT_PROTOCOL`, `APP_URL`, and `ASSET_URL` configurable from Docker build args.
- Fix Vite asset base path generation so assets resolve correctly when the app is mounted under a sub-path.
- Fix RAG health fallback behavior when one or more backend health endpoints are unavailable.
- Fix Ollama health checks in the Docker stack.
- Fix conversion handoff issues by validating converted output, normalizing conversion metadata, and writing converted files more safely.
- Fix pipeline command status handling and improve conversion/ingestion logging.
- Fix duplicate ingestion of converted document trees by skipping nested converted output folders during ingest discovery.
- Fix Neo4j triplet duplication by folding reverse duplicate relationships into the existing semantic relationship and preserving provenance with `doc_ids`.
- Fix Neo4j stats to count the canonical HAWKI `Entity`/`REL` graph instead of temporary extraction graph data.
- Fix Neo4j/RAG-Anything graph cleanup so temporary extraction graph data does not pollute the persisted HAWKI graph.
- Fix duplicate/failed LightRAG document status handling so duplicate attempts are treated as skipped rather than polluting failure counts.

### Internals

[//]: # (- Changes that are mostly relevant to maintainers and contributors, such as refactors, dependency updates, CI changes, etc.)
- Refactor RAG orchestration from the older communication service abstraction into Laravel-owned RabbitMQ services and commands.
- Add `ConvertedDocumentIngestionService` and `RagRabbitMQ` for event validation, topology declaration, retry handling, and bridge handoff.
- Add pipeline validation and structured pipeline logging helpers for conversion and ingestion stages.
- Remove previously tracked Python cache files and generated JSON artifacts from the repository.
- Clean up Laravel migrations by removing obsolete cache/session/scrape table migrations and adding operational state tables for the new pipeline.
- Update the Laravel Dockerfile to build frontend assets more reliably and simplify crawler resource handling.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
