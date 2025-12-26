# Simple Makefile to streamline the RAWKI pipeline

SHELL := /bin/bash
OS_NAME := $(shell uname -s)

COMPOSE_BIN ?= docker compose

ifeq ($(OS_NAME),Darwin)
COMPOSE_PROFILES := cpu
OLLAMA_SERVICE := ollama_cpu
export OLLAMA_USE_MPS := 1
else
COMPOSE_PROFILES := gpu
OLLAMA_SERVICE := ollama_gpu
endif

COMPOSE_CMD = COMPOSE_PROFILES=$(COMPOSE_PROFILES) $(COMPOSE_BIN)

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
	@$(COMPOSE_CMD) pull qdrant mysql nginx $(OLLAMA_SERVICE)

build-app:
	@$(COMPOSE_CMD) build app

up-core: network pull-core build-app
	@echo "Launching core stack (profile: $(COMPOSE_PROFILES))..."
	@$(COMPOSE_CMD) up -d --remove-orphans qdrant mysql nginx $(OLLAMA_SERVICE) app
	@echo "Ensuring Ollama has bge-m3 model pulled..."
	@docker exec hawki_ollama ollama pull bge-m3 >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3:8b model pulled..."
	@docker exec hawki_ollama ollama pull llama3:8b >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3.1:8b model pulled..."
	@docker exec hawki_ollama ollama pull llama3.1:8b >/dev/null 2>&1 || true

up-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) build rawki_rerank || true
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) up -d

health:
	@echo "Checking Qdrant..." && curl -fsS http://localhost:6333/readyz && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ollama..." && curl -fsS http://localhost:11434/api/tags >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@if docker ps --format '{{.Names}}' | grep -q rawki_rerank; then \
		echo "Checking Local Reranker..." && curl -fsS http://localhost:8008/health && echo " OK" || (echo " WARN (reranker reported unhealthy)" && true); \
	else \
		echo "Checking Local Reranker... SKIPPED (rawki_rerank container not running)"; \
	fi
	@if docker ps --format '{{.Names}}' | grep -q rawki_bridge; then \
		echo "Checking Ingestion Bridge..." && curl -fsS $(INGEST_BASE)/health && echo " OK" || (echo " WARN (ingestion reported unhealthy)" && true); \
	else \
		echo "Checking Ingestion Bridge... SKIPPED (rawki_bridge container not running)"; \
	fi
	@if docker ps --format '{{.Names}}' | grep -q rawki_core; then \
		echo "Checking RAWKI (UI/API)..." && curl -fsS $(RAWKI_BASE)/health && echo " OK" || (echo " WARN (service reported unhealthy)" && true); \
	else \
		echo "Checking RAWKI (UI/API)... SKIPPED (rawki_core container not running)"; \
	fi
	@if docker ps --format '{{.Names}}' | grep -q rawki-nginx; then \
		echo "Checking RAWKI (via gateway)..." && curl -fsS http://localhost:8003/rag/health && echo " OK" || (echo " WARN (gateway may be disabled)" && true); \
	else \
		echo "Checking RAWKI (via gateway)... SKIPPED (nginx gateway not running)"; \
	fi

test-services:
	@set -e; \
	printf "qdrant: "; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://localhost:6333/readyz || echo 000); \
	if [ "$$code" = "200" ] || [ "$$code" = "204" ] || [ "$$code" = "404" ]; then echo "healthy ($$code)"; else echo "FAIL ($$code)"; exit 1; fi; \
	printf "neo4j: "; curl -fsS http://localhost:7475/browser >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
	if docker ps --format '{{.Names}}' | grep -q rawki_core; then \
		printf "rawki_core: "; curl -fsS $(RAWKI_BASE)/health >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "rawki_core: skipped (container not running)\n"; \
	fi; \
	if docker ps --format '{{.Names}}' | grep -q rawki_bridge; then \
		printf "rawki_bridge: "; curl -fsS $(INGEST_BASE)/health >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "rawki_bridge: skipped (container not running)\n"; \
	fi; \
	if docker ps --format '{{.Names}}' | grep -q rawki_rerank; then \
		printf "rawki_rerank: "; curl -fsS $(RERANK_BASE)/health >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "rawki_rerank: skipped (container not running)\n"; \
	fi; \
	echo "Service checks completed."

pull-models:
	@docker exec -it hawki_ollama ollama pull bge-m3

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to your local path, e.g.: make ingest CRAWLED_ROOT=/data/crawled" && exit 1; fi
	@python3 python_rag/ingest/ingest_crawled.py \
		--root $(CRAWLED_ROOT) \
		--base-url $(INGEST_BASE) \
		--provider ollama \
		--graph \
		--batch 16

logs-core:
	@$(COMPOSE_CMD) logs -f qdrant mysql nginx $(OLLAMA_SERVICE) app

logs-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) down

restart-core:
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mysql nginx $(OLLAMA_SERVICE) app

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
