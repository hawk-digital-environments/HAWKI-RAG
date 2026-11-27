# External crawler setup

HAWKI-RAG sends crawler requests through its Temporal scraper worker. FastAPI
uses the scraper queue to check readiness and fetch saved profiles or job
receipts. The ingestion workflow uses the same queue for scraping.
The scraper worker calls the external crawler over HTTP and reports ingestion
progress back to FastAPI.

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
in `.env` for an image that implements `permanent-job-v2`, then check readiness.

### Separately deployed crawler

1. Deploy a crawler that advertises and implements `permanent-job-v2`. Give it
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
