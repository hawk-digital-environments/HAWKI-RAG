# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose
UP_CORE_COMPOSE_FILE ?= docker-compose.yml:docker-compose.local.yml

# Variables (override via `make VAR=value`)
ENV_FILE ?= .env
CRAWLED_ROOT ?= /app/shared

HOST_OS := $(shell uname -s)

BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
COMPOSE_FILE_SEP ?= :
# USE_OLLAMA_GPU: auto (default), 1 (force GPU override), 0 (force CPU mode)
USE_OLLAMA_GPU ?= auto
COMPOSE_PROFILES ?=

ifeq ($(USE_OLLAMA_GPU),auto)
	ifeq ($(HOST_OS),Linux)
		USE_OLLAMA_GPU := $(shell if command -v nvidia-smi >/dev/null 2>&1; then echo 1; else echo 0; fi)
	else
		USE_OLLAMA_GPU := 0
	endif
endif

OLLAMA_SERVICE := ollama
OLLAMA_CONTAINER ?= hawki_ollama

COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)

ifeq ($(USE_OLLAMA_GPU),1)
	COMPOSE_FILE_LIST := $(COMPOSE_FILE_LIST)$(COMPOSE_FILE_SEP)$(GPU_OVERRIDE_COMPOSE)
	PROFILE_MESSAGE := "Ollama GPU override enabled."
else
	PROFILE_MESSAGE := "Ollama CPU mode."
endif

COMPOSE_ENV_PREFIX :=
ifeq ($(HOST_OS),Darwin)
	# Clear forced amd64 platform if inherited from shell; avoids wrong-platform pulls on Apple Silicon.
	COMPOSE_ENV_PREFIX := DOCKER_DEFAULT_PLATFORM=
endif
COMPOSE_PROFILE_PREFIX :=
ifneq ($(strip $(COMPOSE_PROFILES)),)
	COMPOSE_PROFILE_PREFIX := COMPOSE_PROFILES=$(COMPOSE_PROFILES)
endif
COMPOSE_FILE_PREFIX := COMPOSE_FILE=$(COMPOSE_FILE_LIST)
COMPOSE_CMD = $(COMPOSE_ENV_PREFIX) $(COMPOSE_FILE_PREFIX) $(COMPOSE_PROFILE_PREFIX) $(COMPOSE_BIN) --env-file $(ENV_FILE)


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
	@echo "Launching full stack (COMPOSE_FILE=$(UP_CORE_COMPOSE_FILE))..."
	@COMPOSE_FILE=$(UP_CORE_COMPOSE_FILE) $(COMPOSE_BIN) --env-file $(ENV_FILE) up -d --build --remove-orphans
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
	@$(COMPOSE_CMD) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@$(COMPOSE_CMD) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mysql hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app
	@$(COMPOSE_CMD) up -d --force-recreate

neo4j-fresh:
	@echo "Stopping Neo4j service..."
	@$(COMPOSE_CMD) stop neo4j >/dev/null 2>&1 || true
	@$(COMPOSE_CMD) rm -f neo4j >/dev/null 2>&1 || true
	@echo "Removing persisted Neo4j data (databases, transactions)..."
	@$(COMPOSE_CMD) run --rm --entrypoint bash neo4j -lc 'rm -rf /data/databases/* /data/transactions/*' >/dev/null
	@echo "Starting Neo4j service..."
	@$(COMPOSE_CMD) up -d neo4j >/dev/null
	@echo "Neo4j store reset complete."
