# Simple Makefile to streamline the RAWKI pipeline

SHELL := /bin/bash

# Variables (override via `make VAR=value`)
ENV_FILE ?= ops/LightRAG.env
OPS_COMPOSE ?= ops/rawki-docker-compose.yml
INGEST_BASE ?= http://localhost:8009
RAWKI_BASE ?= http://localhost:8006
RERANK_BASE ?= http://localhost:8008
CRAWLED_ROOT ?= /home/ixdlab-admin/Rawki/RAWKI/storage/app/private/crawled-data

.PHONY: network pull-core build-app up-core up-rag health pull-models ingest logs-core logs-rag down-core down-rag restart-core restart-rag test-services neo4j-fresh

network:
	@docker network create hawki-network || true

pull-core:
	@docker compose pull qdrant nginx ollama

build-app:
	@docker compose build app

up-core: network pull-core build-app
	@docker compose up -d qdrant nginx ollama app
	@echo "Ensuring Ollama has bge-m3 model pulled..."
	@docker exec hawki_ollama ollama pull bge-m3 >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3:8b model pulled..."
	@docker exec hawki_ollama ollama pull llama3:8b >/dev/null 2>&1 || true

up-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) build rawki_rerank || true
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d

health:
	@echo "Checking Qdrant..." && curl -fsS http://localhost:6333/readyz && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ollama..." && curl -fsS http://localhost:11434/api/tags >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Local Reranker..." && curl -fsS http://localhost:8008/health && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ingestion Bridge..." && curl -fsS $(INGEST_BASE)/health && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking RAWKI (UI/API)..." && curl -fsS $(RAWKI_BASE)/health && echo " OK" || (echo " WARN (skip if not exposed)" && true)
	@echo "Checking RAWKI (via gateway)..." && curl -fsS http://localhost:8003/rag/health && echo " OK" || (echo " WARN (gateway may be disabled)" && true)

test-services:
	@set -e; \
	printf "qdrant: "; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://localhost:6333/readyz || echo 000); \
	if [ "$$code" = "200" ] || [ "$$code" = "204" ] || [ "$$code" = "404" ]; then echo "healthy ($$code)"; else echo "FAIL ($$code)"; exit 1; fi; \
	printf "neo4j: "; curl -fsS http://localhost:7475/browser >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	printf "rawki_core: "; curl -fsS $(RAWKI_BASE)/health >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	printf "rawki_bridge: "; curl -fsS $(INGEST_BASE)/health >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	printf "rawki_rerank: "; curl -fsS $(RERANK_BASE)/health >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	echo "All services reported healthy."

pull-models:
	@docker exec -it hawki_ollama ollama pull bge-m3

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to your local path, e.g.: make ingest CRAWLED_ROOT=/data/crawled" && exit 1; fi
	@python3 scripts/ingest_crawled.py \
		--root $(CRAWLED_ROOT) \
		--base-url $(INGEST_BASE) \
		--provider ollama \
		--graph \
		--batch 16

logs-core:
	@docker compose logs -f qdrant nginx ollama app

logs-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) logs -f

down-core:
	@docker compose down

down-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) down

restart-core:
	@docker compose up -d --force-recreate qdrant nginx ollama app

restart-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d --force-recreate

neo4j-fresh:
	@echo "Stopping Neo4j service..."
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) stop neo4j >/dev/null 2>&1 || true
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) rm -f neo4j >/dev/null 2>&1 || true
	@echo "Removing persisted Neo4j data (databases, transactions)..."
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) run --rm --entrypoint bash neo4j -lc 'rm -rf /data/databases/* /data/transactions/*' >/dev/null
	@echo "Starting Neo4j service..."
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d neo4j >/dev/null
	@echo "Neo4j store reset complete."
