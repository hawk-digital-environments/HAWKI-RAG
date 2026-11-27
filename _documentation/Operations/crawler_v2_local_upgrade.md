# External crawler setup

The crawler fetches web pages and writes files to the shared artifact volume.
The Temporal scraper worker submits the crawl over HTTP and polls for its
receipt. The converter reads the files from the volume. FastAPI uses the same
scraper queue to check readiness, fetch saved profiles, and inspect or cancel
existing jobs.

RAWKI checks that the crawler implements `crawler-api`. The crawler advertises
this contract as `permanent-job-v2` in its OpenAPI schema. Retries reuse the
same job ID, and job receipts confirm when the crawler has stopped writing.

## Configure the worker

### Optional production addon

From `_docker_production`, copy `.env.scraper.example` to `.env.scraper` and
include the addon after the base Compose file.

```sh
COMPOSE_FILE=docker-compose.yml:docker-compose.scraper.yml ./deploy.sh
```

The addon uses `digitalenvironments/hawki-rag-scraper:latest` by default and
shares the artifact volume with the worker. Set `RAG_SCRAPER_IMAGE` to select
a specific image tag or digest. The scraper publishes no host port. If a
reverse proxy on the crawler network serves its UI, set `ORIGIN` in
`.env.scraper` to the public URL. Enable `RAG_BACKEND_URL_INGESTION_ENABLED=true`
in `.env` for an image that implements `crawler-api`, then check readiness.

### Separately deployed crawler

1. Deploy a crawler that implements `crawler-api`. Give it
   access to the same shared artifact storage as the scraper and converter
   workers. Make the crawler endpoint reachable from the scraper worker.
2. For repository builds, copy `.env.scraper-worker.example` to
   `.env.scraper-worker` and set `EXTERNAL_SCRAPER_URL` and, if required,
   `EXTERNAL_SCRAPER_TOKEN`. For production, set those values in
   `_docker_production/.env`. Its Compose configuration passes them to the
   scraper worker.
3. Set `RAG_BACKEND_URL_INGESTION_ENABLED=true` in the project `.env` and start
   the stack with a matching backend and scraper worker release. Both services
   must use the same scraper task queue.
4. Check `/internal/url-ingestion/readiness` from the private network or run
   `python -m hawki_backend.readiness --live` in the backend. A failed
   `crawler_contract` check blocks URL admission.

The saved profile and task catalog is optional. If the default profile route
is absent and crawler readiness passes, the catalog returns an empty list.
The external crawler owns browser configuration and lifecycle.

Existing URL generations retain their original crawler identity. Keep the
original endpoint available while those jobs drain. Retirement and cleanup
require a verified receipt for the exact job and generation. Preserve archived
crawler evidence and its identity and artifact reservations.
