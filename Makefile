# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose

# Auto-detect GPU; allow override via COMPOSE_PROFILES=gpu|cpu
COMPOSE_PROFILES ?= $(shell if command -v nvidia-smi >/dev/null 2>&1; then echo gpu; else echo cpu; fi)
ifeq ($(COMPOSE_PROFILES),cpu)
	OLLAMA_SERVICE := ollama_cpu
	OLLAMA_CONTAINER ?= hawki_ollama_cpu
	PROFILE_MESSAGE := "CPU profile selected (no nvidia-smi)."
else
	OLLAMA_SERVICE := ollama_gpu
	OLLAMA_CONTAINER ?= hawki_ollama_gpu
	PROFILE_MESSAGE := "GPU profile selected."
endif

COMPOSE_CMD = COMPOSE_PROFILES=$(COMPOSE_PROFILES) $(COMPOSE_BIN)

# Variables (override via `make VAR=value`)
ENV_FILE ?= .env
OPS_COMPOSE ?= docker-compose.yml
CRAWLED_ROOT ?= /app/shared


.PHONY: network pull-core build-app up-core health pull-models ingest logs-core down-core down-rag restart-core test-services neo4j-fresh

network:
	@for net in hawki-network hosting_network; do \
		if docker network inspect $$net >/dev/null 2>&1; then \
			echo "$$net already exists; skipping create"; \
		else \
			echo "Creating $$net..."; \
			docker network create $$net; \
		fi; \
	done

pull-core:
	@$(COMPOSE_CMD) pull nginx || true

build-app:
	@$(COMPOSE_CMD) build hawki_rag_app

up-core: network
	@echo $(PROFILE_MESSAGE)
	@echo "Launching full stack (profile: $(COMPOSE_PROFILES))..."
	@$(COMPOSE_CMD) -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d --build --remove-orphans
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true

health:
	@echo "Checking Qdrant..." && docker exec hawki_qdrant sh -lc "curl -fsS http://localhost:6333/readyz" >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ollama..." && docker exec $(OLLAMA_CONTAINER) sh -lc "curl -fsS http://localhost:11434/api/tags" >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@if docker ps --format '{{.Names}}' | grep -q hawki_rag_rerank; then \
		echo "Checking Local Reranker..." && docker exec hawki_rag_rerank sh -lc "curl -fsS http://localhost:8000/health" >/dev/null && echo " OK" || (echo " WARN (reranker reported unhealthy)" && true); \
	else \
		echo "Checking Local Reranker... SKIPPED (hawki_rag_rerank container not running)"; \
	fi
	@if docker ps --format '{{.Names}}' | grep -q hawki_rag_bridge; then \
		echo "Checking Ingestion Bridge..." && docker exec hawki_rag_bridge sh -lc "curl -fsS http://localhost:8000/health" >/dev/null && echo " OK" || (echo " WARN (ingestion reported unhealthy)" && true); \
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
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.1:8b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:1b

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to a path mounted in shared storage (default /app/shared inside hawki_rag_bridge)" && exit 1; fi
	@docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py --root $(CRAWLED_ROOT) --base-url http://localhost:8000 --provider ollama --graph --batch 16"

logs-core:
	@$(COMPOSE_CMD) logs -f qdrant mysql hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mysql hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app
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
