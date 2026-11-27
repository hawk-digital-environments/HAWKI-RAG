# Environment & Configuration

Use `.env.example`, `python_backend/.env.example`, or
`_docker_production/.env.example` for the deployment in use. Store runtime
credentials in operator-managed environment files or secret mounts. See
[deployment](python_runtime.md) for startup and schema prerequisites.

## Runtime options and system configuration

- **Database.** Use a separate migration DSN to apply Alembic revision `0009`.
  Give the backend's restricted runtime role only the operational grants on
  `hawki_backend`. Preserve the historical `public` tables.
- **Service authentication.** Configure the JWT issuer, HTTPS JWKS, audience,
  client registry, and scopes.
- **Models.** `RAG_BACKEND_QUERY_MODELS` registers chat and vision models by
  provider. At dataset creation, the backend stores the defaults from
  `RAG_BACKEND_DATASET_EMBEDDING_PROVIDER` and
  `RAG_BACKEND_DATASET_EMBEDDING_MODEL` in the dataset record. A change to
  either default applies to new datasets. Existing datasets and their vectors
  keep the stored embedding contract.
- **Workers.** Match the Temporal address, namespace, queue names, and callback
  signing secret across the backend and workers. Mount the shared artifact
  volume for ingestion. Keep the clocks synchronized because callbacks sign a
  timestamp and the serialized body. The backend accepts timestamps within
  300 seconds of its clock. Readiness validates
  `RAG_WORKER_CALLBACK_MAX_AGE_SECONDS` separately, and the runtime verifier
  uses its fixed limit.
- **Stores and reranker.** Configure the Qdrant, Neo4j, and reranker endpoints
  and credentials on the server. The dataset record determines physical
  collections and namespaces.
- **Crawler.** When URL ingestion is enabled, set `EXTERNAL_SCRAPER_URL` on
  the scraper worker. It checks that the crawler implements `crawler-api`.
  FastAPI sends crawler operations to the worker through Temporal.

Recreate affected services after changing environment settings. Apply schema
migrations separately. Readiness reports dependency and configuration failures.

## Graph extraction

The dataset record determines whether a graph scope is available. Managed
documents, including inline text, and URL tasks can request graph processing
through their respective inputs. The historical `IngestTextWorkflow` remains
vector-only.
