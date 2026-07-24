# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)
- Replace RabbitMQ-based RAG ingestion orchestration with Temporal workflows and Python activity workers.
- Remove the legacy PHP event-bus worker path for scrape, convert, and ingest orchestration.
- Add Neo4j graph visualization to the HAWKI RAG playground, including live graph snapshots, relationship counts, recently added triplet highlighting, and graph clearing controls.
- Add graph-only ingestion mode for writing Neo4j triplets without running the full vector embedding flow.
- Add document and pipeline state persistence foundations with new `documents` and `job_processing_state` models and migrations.
- Add Temporal Server with PostgreSQL persistence to the core Docker stack.
- Add independently scalable Temporal workflow, scraper, converter, and ingestion workers.

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)
- Introduce `docker-compose.local.yml` for local overrides, allowing developers to run the stack locally without modifying the default Compose files.
- Add Makefile targets for local/server core startup, health checks, Temporal workers, crawl/convert/ingest flows, and Neo4j reset.
- Reorganize `.env.example` into clearer sections for application, PostgreSQL, Temporal, RAG, Neo4j, and search configuration.
- Split the playground JavaScript into focused modules for ingestion, logs/stats, query handling, and graph visualization.
- Add documentation for Temporal ingestion, database operations, and pipeline state tracking.
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
- Refactor RAG orchestration from the older communication service abstraction into Laravel-owned Temporal workflow/schedule services and commands.
- Add Temporal workflow payload factories and external service adapter workers for scraper/converter/ingestion handoff.
- Add pipeline validation and structured pipeline logging helpers for conversion and ingestion stages.
- Remove previously tracked Python cache files and generated JSON artifacts from the repository.
- Clean up Laravel migrations by removing obsolete cache/session/scrape table migrations and adding operational state tables for the new pipeline.
- Update the Laravel Dockerfile to build frontend assets more reliably and simplify crawler resource handling.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
