# Requirements

HAWKI RAG runs through Docker. Host installations of PHP, Python, PostgreSQL,
Qdrant, Neo4j, and Ollama are unnecessary for the container workflow.

## Software and platforms

| Requirement | Notes |
|---|---|
| Docker Engine or Docker Desktop, with Compose v2 | The development override uses Compose's `!override` YAML tag; the installed Compose must support it. |
| Git and Make | Obtain the checkout and run the repository's lifecycle commands. |
| curl and OpenSSL | Verify HTTP endpoints and generate installation secrets. |
| Linux | CPU mode or NVIDIA acceleration. |
| macOS, Intel or Apple Silicon | Docker Desktop; Make selects CPU mode automatically. |
| Windows through WSL2 | Use a Linux shell with Docker Desktop integration. The Makefile assumes Unix tools; there is no native Windows startup script. |
| Optional NVIDIA driver and container toolkit | GPU images use the CUDA 13.0 PyTorch variant. Make's automatic detection checks whether `nvidia-smi` exists; it does not validate driver compatibility. |

Keep a WSL checkout in its Linux filesystem for source-mount performance.
Developers running tools outside containers should consult
[Testing](../Developer/testing.md) for the locked Python workspace.

Verify the host tools before installation:

```bash
docker --version
docker compose version
make --version
```

The `!override` requirement comes specifically from `networks` in
`docker-compose.local.yml`; it matters when selecting local development mode.

## Hardware planning

The repository does **not** establish a tested minimum hardware specification or
a platform certification matrix. Treat these as planning estimates, not
guarantees:

| Resource | Starting estimate | Recommended headroom |
|---|---|---|
| RAM available to Docker | 16 GB for small CPU experiments | 32 GB or more for local models and graph extraction |
| CPU | Multicore processor | 8 or more cores |
| Free disk | At least 20 GB to begin | Substantially more for image builds, model caches, documents, and persistent databases |
| GPU | Optional | Size VRAM for the selected models; CPU mode remains available |

The indexer and reranker have separate dependency stacks. Image builds and local
vision models can need considerably more space and memory than a small
retrieval-only workload.

## Host-exposed ports

Only published ports must be available on the host.

| Port / binding | When needed |
|---|---|
| `127.0.0.1:8080` | Laravel UI/API with `up-core` or `up-core-local` |
| `127.0.0.1:8081` | Optional Temporal UI; configurable host binding and port |
| `127.0.0.1:4000` | Optional LiteLLM profile; configurable port |
| Reverse proxy's HTTP/HTTPS ports | Server deployment; the core server profile publishes no Laravel host port |

The external-tool Make targets report crawler UI port **8041** and converter
health port **8004**. Their actual bindings belong to the sibling projects'
Compose files; verify those projects before reserving host ports.

## Docker-internal service ports

These do **not** need to be free on the host.

| Service | Internal endpoint |
|---|---|
| Laravel / Nginx | `hawki_rag_app:80` |
| PostgreSQL | `postgres:5432` |
| Temporal | `temporal:7233` |
| Qdrant HTTP | `qdrant:6333` |
| Neo4j HTTP / Bolt | `hawki_rag_neo4j:7474` / `hawki_rag_neo4j:7687` |
| Query / Temporal-control bridge | `hawki_rag_bridge:80` |
| Reranker / Ollama | `hawki_rag_rerank:80` / `hawki_ollama:11434` |
| Optional LiteLLM / Temporal UI | `litellm:4000` / `temporal-ui:8080` |
| External crawler | `crawl4ai-service:80` |
| External file converter | `hawki-toolkit-file-converter-file-converter-1:80` |

Workflow and activity workers expose no application HTTP API.

## Ingestion prerequisites

Direct text needs the core stack and an embedding provider. Website ingestion
also needs the external crawler; file conversion needs the external converter.
The external tools must share the expected network and artifact volume.
[Installation](./4_installation_zero_to_up.md) explains first startup;
[Run HAWKI RAG](./2_setup.md#external-tools) covers the sibling-tool commands.

