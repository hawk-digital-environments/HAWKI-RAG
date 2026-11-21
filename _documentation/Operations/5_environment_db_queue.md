# Environment & Configuration

The authoritative examples are `.env.example`, `python_backend/.env.example` and `docker/env/epoch15c.cutover.env.example`. Runtime values live in ignored operator files or secret mounts. See [deployment](python_runtime.md) for startup and schema prerequisites.

## Runtime options and system configuration

- Database: provision schema revision 0009 with a migration DSN; the restricted runtime role uses only `hawki_backend`. Keep historical public tables frozen.
- Service authentication: configure HTTPS JWT issuer/JWKS, audience, service client registry and scopes. `HAWKI_BACKEND_SERVICE_ONLY=true` and `HAWKI_BACKEND_LARAVEL_OFF=true` are mandatory runtime/security fences. The latter is a compatibility identifier, not a browser authentication mode.
- UI: configure exact origin, HAWKI management approval, and read-only server credential mounts. The explicit local overlay selects loopback access. Never expose service credentials through frontend environment variables.
- Models: query provider/model configuration and `HAWKI_BACKEND_DATASET_EMBEDDING_PROVIDER` / `HAWKI_BACKEND_DATASET_EMBEDDING_MODEL` define current contracts. Existing datasets retain their embedding contract.
- Workers: configure Temporal address/namespace, dedicated task queues, shared storage and the same signed callback secret/replay window at both ends. Public ingress must not expose internal callbacks.
- Stores: configure Qdrant, Neo4j and reranker endpoints and credentials server-side. Browser requests cannot select physical collections or namespaces.
- URL ingestion: `EXTERNAL_SCRAPER_URL` selects the crawler. Keep full permanent-job-v2 validation. Optional saved task catalogs are distinct from crawler execution.

Recreate consumers after environment changes. Startup does not implicitly migrate the database. Readiness checks dependencies without repairing state or relaxing authorization.

## Graph extraction

Dataset graph capability is deployment-managed. Managed uploads and URL tasks opt into graph ingestion through their normal input. Use the configured model/provider and namespace for that dataset; direct text retains its vector-only contract.
