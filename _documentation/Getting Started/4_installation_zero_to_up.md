# Run HAWKI-RAG

1. Configure the environment from `.env.example` for local builds, or
   `_docker_production/.env.example` for registry images. Supply the database
   settings, signed callback secret, service JWT issuer and model configuration.
2. Provision Alembic revision `0009` and a dedicated runtime role using the
   instructions in [Python RAG deployment](../Operations/python_runtime.md).
3. Apply the provider addons you need. For production URL ingestion, see the
   [crawler setup](../Operations/python_runtime.md#production-crawler).
4. From the project network, check `http://rag_backend/v1/health` and use a
   registered service JWT on `GET /v1/query/datasets`. Verify live readiness
   from within the backend container.

Production requires the matching published Python images and an established
schema rollout procedure. The current model readiness probe requires Ollama
even though Compose can connect to an external or self-hosted LiteLLM gateway.
