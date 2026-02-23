# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose

COMPOSE_PROFILES := gpu
OLLAMA_SERVICE := ollama_gpu
OLLAMA_CONTAINER ?= hawki_ollama_gpu
PROFILE_MESSAGE := "GPU profile forced."

COMPOSE_CMD = COMPOSE_PROFILES=$(COMPOSE_PROFILES) $(COMPOSE_BIN)

# Variables (override via `make VAR=value`)
ENV_FILE ?= python_rag/LightRAG.env
OPS_COMPOSE ?= docker-compose.yml
INGEST_BASE ?= http://localhost:8009
RERANK_BASE ?= http://localhost:8008
CRAWLED_ROOT ?= /absolute/path/to/crawled-data
MCP_BASE ?= http://localhost:8080/mcp/hawki_rag
MCP_INGEST_ROOT ?= /absolute/path/to/crawled-data
MCP_INGEST_PROVIDER ?= ollama
MCP_INGEST_GRAPH ?= true
MCP_INGEST_GRAPH_ENGINE ?= raganything
MCP_INGEST_CHUNK_CHARS ?= 3200
MCP_INGEST_CHUNK_OVERLAP ?= 100
MCP_INGEST_BATCH ?= 64
MCP_INGEST_TIMEOUT ?= 1800
MCP_LIST_ROOT ?= /app/shared
PIPELINE_URL ?=
PIPELINE_MAX_PAGES ?= 100
PIPELINE_OUTPUT_DIR ?=
PIPELINE_LABEL ?=
PIPELINE_COLLECTION ?=
PIPELINE_GRAPH ?= true
PIPELINE_GRAPH_ENGINE ?= raganything
PIPELINE_CHUNK_CHARS ?= 3200
PIPELINE_CHUNK_OVERLAP ?= 100
PIPELINE_BATCH ?= 64
PIPELINE_TIMEOUT ?= 1800
PIPELINE_PROVIDER ?= ollama
PIPELINE_DISTANCE ?= Cosine
PIPELINE_BASE_URL ?= http://hawki_rag_bridge:8000
PIPELINE_ASYNC ?= true

.PHONY: network pull-core build-app up-core up-rag health pull-models ingest logs-core logs-rag down-core down-rag restart-core restart-rag test-services neo4j-fresh

network:
	@docker network create hawki-network || true

pull-core:
	@$(COMPOSE_CMD) pull nginx || true

build-app:
	@$(COMPOSE_CMD) build hawki_rag_app

up-core: network pull-core build-app
	@echo $(PROFILE_MESSAGE)
	@echo "Launching core stack (profile: $(COMPOSE_PROFILES))..."
	@$(COMPOSE_CMD) up -d --remove-orphans qdrant hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app
	@echo "Ensuring Ollama has bge-m3 model pulled..."
	@docker exec $(OLLAMA_CONTAINER) ollama pull bge-m3 >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3:8b model pulled..."
	@docker exec $(OLLAMA_CONTAINER) ollama pull llama3:8b >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3.1:8b model pulled..."
	@docker exec $(OLLAMA_CONTAINER) ollama pull llama3.1:8b >/dev/null 2>&1 || true

up-rag:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) -f $(OPS_COMPOSE) --env-file $(ENV_FILE) build hawki_rag_rerank hawki_rag_bridge raganything_api raganything_api_gpu || true
	@$(COMPOSE_CMD) -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d
	@echo "Ensuring Ollama has llama3.2:1b model pulled..."
	@docker exec $(OLLAMA_CONTAINER) ollama pull llama3.2:1b >/dev/null 2>&1 || true
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true

health:
	@echo "Checking Qdrant..." && curl -fsS http://localhost:6333/readyz && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ollama..." && curl -fsS http://localhost:11434/api/tags >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@if docker ps --format '{{.Names}}' | grep -q hawki_rag_rerank; then \
		echo "Checking Local Reranker..." && curl -fsS http://localhost:8008/health && echo " OK" || (echo " WARN (reranker reported unhealthy)" && true); \
	else \
		echo "Checking Local Reranker... SKIPPED (hawki_rag_rerank container not running)"; \
	fi
	@if docker ps --format '{{.Names}}' | grep -q hawki_rag_bridge; then \
		echo "Checking Ingestion Bridge..." && curl -fsS $(INGEST_BASE)/health && echo " OK" || (echo " WARN (ingestion reported unhealthy)" && true); \
	else \
		echo "Checking Ingestion Bridge... SKIPPED (hawki_rag_bridge container not running)"; \
	fi

test-services:
	@set -e; \
	printf "qdrant: "; \
	if docker ps --format '{{.Names}}' | grep -q hawki_qdrant; then \
		code=$$(docker exec hawki_qdrant sh -lc "curl -s -o /dev/null -w \"%{http_code}\" http://localhost:6333/readyz" || echo 000); \
		if [ "$$code" = "200" ] || [ "$$code" = "204" ] || [ "$$code" = "404" ]; then echo "healthy ($$code)"; else echo "FAIL ($$code)"; exit 1; fi; \
	else \
		echo "skipped (container not running)"; \
	fi; \
	printf "neo4j: "; \
	if docker ps --format '{{.Names}}' | grep -q hawki_rag_neo4j; then \
		docker exec hawki_rag_neo4j sh -lc "wget --spider -q http://localhost:7474/browser" >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	else \
		echo "skipped (container not running)"; \
	fi; \
	if docker ps --format '{{.Names}}' | grep -q hawki_rag_bridge; then \
		printf "hawki_rag_bridge: "; docker exec hawki_rag_bridge sh -lc "curl -fsS http://localhost:8000/health" >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "hawki_rag_bridge: skipped (container not running)\n"; \
	fi; \
	if docker ps --format '{{.Names}}' | grep -q hawki_rag_rerank; then \
		printf "hawki_rag_rerank: "; docker exec hawki_rag_rerank sh -lc "curl -fsS http://localhost:8000/health" >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "hawki_rag_rerank: skipped (container not running)\n"; \
	fi; \
	echo "Service checks completed."

pull-models:
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull bge-m3
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:1b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:3b

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to your local path, e.g.: make ingest CRAWLED_ROOT=/data/crawled" && exit 1; fi
	@python3 python_rag/ingest/ingest_crawled.py \
		--root $(CRAWLED_ROOT) \
		--base-url $(INGEST_BASE) \
		--provider ollama \
		--graph \
		--batch 16

logs-core:
	@$(COMPOSE_CMD) logs -f qdrant mysql hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app

logs-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mysql hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app

restart-rag:
	@echo $(PROFILE_MESSAGE)
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
