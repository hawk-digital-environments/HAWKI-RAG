# Environment & Configuration

The authoritative examples are `.env.example`, `python_backend/.env.example` and `_docker_production/.env.example`. Runtime values live in ignored operator files or secret mounts. See [deployment](python_runtime.md) for startup and schema prerequisites.

## Runtime options and system configuration

- Database: provision schema revision 0009 with a migration DSN; the restricted runtime role uses only `hawki_backend`. Keep historical public tables frozen.
- Service authentication: configure issuer and JWKS over HTTPS, audience, registered service clients and required scopes. Compose requires service identities.
- Models: query provider/model configuration and `RAG_BACKEND_DATASET_EMBEDDING_PROVIDER` / `RAG_BACKEND_DATASET_EMBEDDING_MODEL` define current contracts. Existing datasets retain their embedding contract.
- Workers: configure Temporal address/namespace, dedicated task queues, shared storage and the same signed callback secret/replay window at both ends. Worker callbacks stay on the private Docker network.
- Stores: configure Qdrant, Neo4j and reranker endpoints and credentials server-side. Browser requests cannot select physical collections or namespaces.
- For URL ingestion, `EXTERNAL_SCRAPER_URL` configures the scraper worker's external permanent-job-v2 crawler. FastAPI sends crawler operations to the scraper worker over Temporal.

Recreate consumers after environment changes. Startup does not implicitly migrate the database. Readiness checks dependencies without repairing state or relaxing authorization.

## Graph extraction

Dataset graph capability is deployment-managed. Managed uploads and URL tasks opt into graph ingestion through their normal input. Use the configured model/provider and namespace for that dataset; direct text retains its vector-only contract.
