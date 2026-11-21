# Requirements

Use Docker with Compose v2, Node.js 22 or newer for frontend builds, and the Python/uv versions declared in the backend and workspace pyproject files. Allocate memory for the chosen Ollama models, converters and graph extraction workload.

The runtime needs PostgreSQL, Temporal, Qdrant, Neo4j, a reranker, configured model providers and shared artifact storage. URL ingestion requires a genuine permanent-job-v2 crawler. Provision a restricted database role, schema revision 0009 and HTTPS JWT/JWKS service authentication before startup.

Local nginx publishes only 127.0.0.1:8080. Internal callbacks, workers, issuer and stores use Docker networks. See [deployment](../Operations/python_runtime.md) for prerequisites and startup, and [configuration](../Operations/5_environment_db_queue.md) for settings. Keep real credentials outside browser assets and source control.
