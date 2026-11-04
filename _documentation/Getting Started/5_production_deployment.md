# Production Deployment

HAWKI RAG ships as prebuilt Docker images on Docker Hub:
[digitalenvironments/hawki-rag](https://hub.docker.com/r/digitalenvironments/hawki-rag).
Each service role has its own image variant, so a production server pulls and
runs the whole stack without building anything from source. The host needs
Docker with Compose v2 and the preconfigured Compose setup from the
repository; see [Requirements](./1_requirements.md) for the details.

## Docker Compose

The [`_docker_production`](https://github.com/hawk-digital-environments/HAWKI-RAG/tree/main/_docker_production)
directory of the repository holds a preconfigured production setup: a
pull-only `docker-compose.yml` that runs the migrator, the Laravel application,
PostgreSQL, Temporal, Qdrant, Neo4j, Ollama, the FastAPI bridge, the reranker,
the file converter, and the four Python Temporal workers. The optional website
scraper joins through its own override file (see
[Website scraper](#website-scraper)). Every HAWKI RAG image is pulled from the
registry and pinned through the environment file; nothing is built on the
server.

**Please consider it a starting point you can/should adapt to your needs.**

Image versions are pinned in `.env`: `HAWKI_RAG_VERSION` selects the release,
`HAWKI_RAG_IMAGE_REPOSITORY` the registry. Each role is published as
`<role>-<version>` (for example `laravel-1.2.3` or `rerank-gpu-1.2.3`) plus a
floating `<role>-latest` and a `<role>-<major-version>` tag. `latest` follows
the newest release; pin an exact version for reproducible deployments.

A few things to consider:

* Certificates - Place your own certificates as `cert.pem` and `key.pem` in the
  `certs` directory. They are mounted into the application container, which
  terminates HTTPS itself. Without certificates the container generates
  self-signed ones (expect browser warnings).
* Reverse proxy - The application binds
  `${DOCKER_PROJECT_IP:-127.0.0.1}:${HAWKI_RAG_APP_PORT:-8080}:80`, loopback by
  default, so an external reverse proxy terminates TLS and forwards traffic to
  it. Set `APP_URL` to the public HTTPS URL and `SESSION_SECURE_COOKIE=true`.
* Shared network - The stack joins the Docker network `hawki-rag-network`,
  which the main HAWKI application stack also joins, so both sides can reach
  each other by service name. Create it once with
  `docker network create hawki-rag-network`, or remove the network block if
  you run HAWKI RAG standalone.
* Persistent data - PostgreSQL, Qdrant, Neo4j and Ollama store their data in
  named volumes, and ingestion artifacts flow through the shared
  `rawki_shared_storage` volume. Laravel storage is a bind mount at
  `./storage`, which ships with the directory.
* Ingestion tools - The file converter runs inside the stack (service
  `file-converter`), because every ingest path needs it; the website scraper
  is optional and joins through `docker-compose.scraper.yml` (see
  [Website scraper](#website-scraper)). Deployments can also point the
  scraper worker at an external crawler instead; the development wiring is
  described in [Run HAWKI RAG](./2_setup.md#external-tools).
* Configuration - Copy `.env.example` to `.env` and adjust it. Everything
  that must be filled in sits in the "Required Settings" section at the top
  of the file; the remaining production defaults such as
  `APP_ENV=production` and `APP_DEBUG=false` rarely need touching. Values
  may reference variables defined earlier in the file (for example
  `EXTERNAL_CONVERTER_URL=${FILE_CONVERTER_BASE_URL}`); Docker Compose
  expands such references when it builds the container environments. The
  file is the configuration reference for this stack: extend it rather
  than guessing variable names.

:::warning Fill in the secrets before the first start

`APP_KEY`, `DB_PASSWORD`, `NEO4J_PASSWORD`,
`HAWKI_RAG_WORKER_CALLBACK_SECRET`, and `FILE_CONVERTER_TOKEN` ship empty in
`.env.example` (they open the "Required Settings" section at the top of the
file) and must be set before `deploy.sh` runs; the stack does not come up
with empty secrets, and an empty callback secret prevents the workers from
reporting status to Laravel. Generate them in one go with
`./generate-env-secrets.sh`, which prints a ready-to-paste env block; the
equivalent manual commands live in
[Installation](./4_installation_zero_to_up.md#2-set-the-installation-secrets).

:::

### What's in the box

The `docker-compose.yml` contains a setup of multiple services:

* `migrator` - A one-shot container that runs the database migrations
  (`php artisan migrate --force`) once, after PostgreSQL is healthy. Every
  other HAWKI RAG service waits for it to complete successfully, so the stack
  never starts against an outdated database schema.
* `hawki_rag_app` - The Laravel application. It serves the web UI and the HTTP
  API through the NGINX and PHP-FPM pair shipped inside the image, and writes
  its health report only after initialization finished.
* `postgres` - The PostgreSQL container. It stores the Laravel metadata and
  the Temporal persistence.
* `temporal` - The Temporal server that orchestrates the ingestion workflows.
  The auto-setup variant creates and updates its own database schema on
  startup.
* `qdrant` - The vector store for chunk text, payloads, and embeddings.
* `hawki_rag_neo4j` - The knowledge-graph store used when ingestion runs with
  graph extraction enabled.
* `ollama` - The local model server for embedding, chat, and vision models.
  Its entrypoint (`ollama-entrypoint.sh`) guards the GPU, pulls the configured
  models on startup, and forwards stop signals; see
  [GPU acceleration](#gpu-acceleration).
* `hawki_rag_bridge` - The read-only FastAPI bridge that fronts query,
  configuration, health, and Temporal control operations for Laravel.
* `hawki_rag_rerank` - The reranker that orders retrieved passages by
  relevance.
* `file-converter` - The HAWKI toolkit service that extracts text from
  uploaded and crawled files before embedding. Every ingest path runs
  through it; Laravel and the converter worker authenticate with the shared
  `FILE_CONVERTER_TOKEN` and wait for its health report.
* `hawki-rag-temporal-workflow-worker`, `hawki-rag-temporal-scraper-worker`,
  `hawki-rag-temporal-converter-worker`, `hawki-rag-indexer-worker` - The
  Python activity workers. Each runs as its own image variant and shares the
  crawled-data root with the application through the shared volume.

### Deployment

Copy `.env.example` to `.env` and fill the required settings at the top of
the file: `./generate-env-secrets.sh` prints the five secrets as a
paste-ready block. Then put the `_docker_production` directory on the
server (cloning the repository works) and run:

```bash
docker network create hawki-rag-network
chmod +x deploy.sh && ./deploy.sh
```

`docker network create` reports an error when the network already exists, for
example because the main HAWKI stack created it; skip it in that case.

`deploy.sh` runs `docker compose up --pull always -d`: it pulls the pinned
images, applies the database migrations through the one-shot `migrator`, and
starts every service in dependency order. The application reports healthy only
after its initialization finished, so give the first start a few minutes.

The Ollama container pulls its models itself: `ollama-entrypoint.sh` starts the
model server, pulls every model listed in `HAWKI_RAG_OLLAMA_MODELS` (see
`.env.example`) once the API answers, and keeps the server in the foreground.
Models live in the `ollama` volume, so re-runs are cheap; pull failures warn
but never stop the server, and queries against a missing model fail until it
is pulled.

Create the first user and verify the stack as described in
[Installation](./4_installation_zero_to_up.md#4-verify-health-and-create-a-query-identity);
the commands run against the production containers unchanged.

### GPU acceleration

`docker-compose.gpu-override.yml` switches the reranker and the indexer worker
to the published `-gpu` image variants and reserves one NVIDIA device for them
and for Ollama. It requires the NVIDIA Container Toolkit on the host. Apply it
by extending `COMPOSE_FILE`:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.gpu-override.yml ./deploy.sh
```

The mounted `ollama-entrypoint.sh` disables GPU usage for the server when the
free VRAM drops below `GPU_MIN_FREE_MB` (default 4096 MB), so inference
degrades to CPU instead of starving other GPU tenants on a shared host, and
pulls the models listed in `HAWKI_RAG_OLLAMA_MODELS` when the service starts.

### Website scraper

The scraper worker belongs to the base stack, but the scraper container - the
service that actually visits websites - is optional: it ships a Playwright
browser and costs real resources, and deployments can point the worker at an
external crawler instead. To run it inside this stack, apply the override:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.scraper.yml ./deploy.sh
```

Then uncomment the "Website Scraper" block in `.env`; the URLs point at the
`scraper` service through `${...}` references. The scraper publishes no host
port: it joins the default network (for this stack) and `hawki-rag-network`
(for the main HAWKI stack), so its unauthenticated API stays unreachable
from the host. Serve its UI through the reverse proxy of the HAWKI stack
and set `ORIGIN` to that public URL. The scraper writes its crawl results
into the shared `rawki_shared_storage` volume the scraper worker reads
from, and the scraper worker waits for its health report before polling.
Edit `forbidden_urls.txt` in the deployment directory to keep hosts and URL
patterns out of every crawl. The override merges with the GPU file:

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.scraper.yml:docker-compose.gpu-override.yml ./deploy.sh
```

### Backups

PostgreSQL holds the Laravel metadata and the Temporal persistence. Create a
dump with:

```bash
docker compose exec postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > backup.sql
```

Restore it by piping the file back through `psql`:

```bash
docker compose exec -T postgres sh -c 'exec psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < backup.sql
```

The vector, graph, and model data live outside PostgreSQL in the named volumes
(`qdrant_data`, `neo4j_data`, `ollama`, `rawki_shared_storage`). Losing them
means re-pulling the models and re-ingesting the content, so include them in
your backup strategy alongside the `./storage` bind mount.

## Building custom images

You can build the images yourself to ship patches or use a private registry.
`docker/laravel.Dockerfile` provides the `laravel-app` build target;
`docker/python_rag.Dockerfile` provides one target per Python role
(`bridge-runtime`, `reranker-runtime`, `workflow-runtime`, `scraper-runtime`,
`converter-runtime`, `indexer-runtime`), where the `TORCH_VARIANT=cpu|gpu`
build argument selects the PyTorch variant for the indexer and reranker
builds. For example:

```bash
docker build --target laravel-app -f docker/laravel.Dockerfile \
  -t my-registry.example/hawki-rag:laravel-1.2.3 .
docker build --target indexer-runtime --build-arg TORCH_VARIANT=gpu \
  -f docker/python_rag.Dockerfile \
  -t my-registry.example/hawki-rag:indexer-worker-gpu-1.2.3 .
```

Point `HAWKI_RAG_IMAGE_REPOSITORY` and `HAWKI_RAG_VERSION` at your own registry
and tags, and the production Compose file pulls them like the official images.

## Good to know

Upgrading is a re-run: set `HAWKI_RAG_VERSION` to the new release and run
`./deploy.sh` again. Compose recreates only the services whose image changed,
and the `migrator` applies the new migrations. Migrations run forward only;
never point an upgraded database at an older release, restore a backup
instead.
