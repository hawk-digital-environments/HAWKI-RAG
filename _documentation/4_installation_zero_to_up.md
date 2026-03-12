# 4. Installation

## Prerequisites
- **Docker + Docker Compose v2**: runs every service in containers.
- **Git**: downloads the project.
- **Make**: runs scripted commands easily.
- **curl**: tests HTTP endpoints.

## Step 1 - Create env file
- Command: `cp .env.example .env`

## Step 2 - Set secrets (do this once)
Open `.env` in an editor and set:

| Variable | Required | When to set |
| --- | --- | --- |
| `APP_KEY` | Yes | Always |
| `DB_ROOT_PASSWORD` | Yes | Always |
| `DB_PASSWORD` | Yes | Always |
| `NEO4J_PASSWORD` | Yes | Always |
| `FILE_CONVERTER_TOKEN` | Optional | If you use file conversion |
| `TAVILY_SEARCH_API_KEY` | Optional | If `WEB_SEARCH_PROVIDER=tavily` |
| `BRAVE_SEARCH_API_KEY` | Optional | If `WEB_SEARCH_PROVIDER=brave` |
| `JINA_API_KEY` | Optional | Only if reranker mode is `jina` |

## Step 3 - Create Docker networks
- Command: `make network`

!!! tip "Network check"
    `make network` can print `created` (first run) or `already exists` (rerun). After this step, Docker networks should include `hawki-network` and `hosting_network`.

## Step 4 - Start services
- Command: `make up-core`

!!! tip "Verification"
    `docker ps` should show containers such as `hawki_rag_app`, `hawki_qdrant`, `hawki_rag_bridge`, `hawki_rag_rerank`, `hawki_rag_neo4j`, `hawki_ollama`, `mariadb`, and `phpmyadmin`.

## Step 5 - Health check everything
- Commands: `make health` and `make test-services`
- You should be able to see `OK` for all components.
