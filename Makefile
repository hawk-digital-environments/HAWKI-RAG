# Simple Makefile to streamline the RAWKI pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose

GPU_AVAILABLE := $(shell command -v nvidia-smi >/dev/null 2>&1 && nvidia-smi -L >/dev/null 2>&1 && echo 1 || echo 0)
ifeq ($(GPU_AVAILABLE),1)
COMPOSE_PROFILES := gpu
OLLAMA_SERVICE := ollama_gpu
PROFILE_MESSAGE := "GPU detected; using gpu profile."
else
COMPOSE_PROFILES := cpu
OLLAMA_SERVICE := ollama_cpu
PROFILE_MESSAGE := "GPU not detected; falling back to cpu profile."
endif

COMPOSE_CMD = COMPOSE_PROFILES=$(COMPOSE_PROFILES) $(COMPOSE_BIN)

# Variables (override via `make VAR=value`)
ENV_FILE ?= python_rag/LightRAG.env
OPS_COMPOSE ?= docker-compose.yml
INGEST_BASE ?= http://localhost:8009
RERANK_BASE ?= http://localhost:8008
CRAWLED_ROOT ?= /absolute/path/to/crawled-data
MCP_BASE ?= http://localhost:8080/mcp/rawki
MCP_INGEST_ROOT ?= /absolute/path/to/crawled-data
MCP_INGEST_PROVIDER ?= ollama
MCP_INGEST_GRAPH ?= true
MCP_INGEST_GRAPH_ENGINE ?= lightrag
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
PIPELINE_GRAPH_ENGINE ?= lightrag
PIPELINE_CHUNK_CHARS ?= 3200
PIPELINE_CHUNK_OVERLAP ?= 100
PIPELINE_BATCH ?= 64
PIPELINE_TIMEOUT ?= 1800
PIPELINE_PROVIDER ?= ollama
PIPELINE_DISTANCE ?= Cosine
PIPELINE_BASE_URL ?= http://rawki_bridge:8000
PIPELINE_ASYNC ?= true

.PHONY: network pull-core build-app up-core up-rag health pull-models ingest logs-core logs-rag down-core down-rag restart-core restart-rag test-services neo4j-fresh

network:
	@docker network create hawki-network || true

pull-core:
	@$(COMPOSE_CMD) pull nginx || true

build-app:
	@$(COMPOSE_CMD) build app

up-core: network pull-core build-app
	@echo $(PROFILE_MESSAGE)
	@echo "Launching core stack (profile: $(COMPOSE_PROFILES))..."
	@$(COMPOSE_CMD) up -d --remove-orphans qdrant nginx $(OLLAMA_SERVICE) app
	@echo "Ensuring Ollama has bge-m3 model pulled..."
	@docker exec hawki_ollama ollama pull bge-m3 >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3:8b model pulled..."
	@docker exec hawki_ollama ollama pull llama3:8b >/dev/null 2>&1 || true
	@echo "Ensuring Ollama has llama3.1:8b model pulled..."
	@docker exec hawki_ollama ollama pull llama3.1:8b >/dev/null 2>&1 || true

up-rag:
	@echo $(PROFILE_MESSAGE)
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) build rawki_rerank rawki_bridge raganything_api raganything_api_gpu || true
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

test-services:
	@set -e; \
	printf "qdrant: "; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://localhost:6333/readyz || echo 000); \
	if [ "$$code" = "200" ] || [ "$$code" = "204" ] || [ "$$code" = "404" ]; then echo "healthy ($$code)"; else echo "FAIL ($$code)"; exit 1; fi; \
	printf "neo4j: "; curl -fsS http://localhost:7475/browser >/dev/null && echo "healthy" || (echo "FAIL" && exit 1); \
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

ingest-mcp:
	@if [ "$(MCP_INGEST_ROOT)" = "" ]; then echo "Set MCP_INGEST_ROOT to your crawled root." && exit 1; fi
	@curl -fsS $(MCP_BASE) \
		-H "Content-Type: application/json" \
		-d '{"jsonrpc":"2.0","id":'"$$(date +%s)"',"method":"tools/call","params":{"name":"rag-folder-ingest-tool","arguments":{"root":"$(MCP_INGEST_ROOT)","base_url":"http://rawki_bridge:8000","provider":"$(MCP_INGEST_PROVIDER)","graph":$(MCP_INGEST_GRAPH),"graph_engine":"$(MCP_INGEST_GRAPH_ENGINE)","chunk_chars":$(MCP_INGEST_CHUNK_CHARS),"chunk_overlap":$(MCP_INGEST_CHUNK_OVERLAP),"batch":$(MCP_INGEST_BATCH),"timeout":$(MCP_INGEST_TIMEOUT)}}}' | python3 -m json.tool

ingest-mcp-list:
	@curl -fsS $(MCP_BASE) \
		-H "Content-Type: application/json" \
		-d '{"jsonrpc":"2.0","id":'"$$(date +%s)"',"method":"tools/call","params":{"name":"rag-folder-list-tool","arguments":{"root":"$(MCP_LIST_ROOT)"}}}' | python3 -m json.tool

pipeline:
	@if [ -z "$(PIPELINE_URL)" ]; then echo "Set PIPELINE_URL, e.g.: make pipeline PIPELINE_URL=https://hawk.de"; exit 1; fi
	@cmd="docker exec -it hawki_app php artisan rawki:pipeline \"$(PIPELINE_URL)\" --max-pages=$(PIPELINE_MAX_PAGES) --provider=$(PIPELINE_PROVIDER) --graph-engine=$(PIPELINE_GRAPH_ENGINE) --distance=$(PIPELINE_DISTANCE) --chunk-chars=$(PIPELINE_CHUNK_CHARS) --chunk-overlap=$(PIPELINE_CHUNK_OVERLAP) --batch=$(PIPELINE_BATCH) --timeout=$(PIPELINE_TIMEOUT) --base-url=$(PIPELINE_BASE_URL)"; \
	if [ "$(PIPELINE_GRAPH)" = "true" ] || [ "$(PIPELINE_GRAPH)" = "1" ]; then cmd="$$cmd --graph"; fi; \
	if [ -n "$(PIPELINE_OUTPUT_DIR)" ]; then cmd="$$cmd --output-dir=$(PIPELINE_OUTPUT_DIR)"; fi; \
	if [ -n "$(PIPELINE_LABEL)" ]; then cmd="$$cmd --label=$(PIPELINE_LABEL)"; fi; \
	if [ -n "$(PIPELINE_COLLECTION)" ]; then cmd="$$cmd --collection=$(PIPELINE_COLLECTION)"; fi; \
	eval $$cmd

pipeline-async:
	@if [ -z "$(PIPELINE_URL)" ]; then echo "Set PIPELINE_URL, e.g.: make pipeline-async PIPELINE_URL=https://hawk.de"; exit 1; fi
	@payload="{\"url\":\"$(PIPELINE_URL)\",\"max_pages\":$(PIPELINE_MAX_PAGES),\"provider\":\"$(PIPELINE_PROVIDER)\",\"graph_engine\":\"$(PIPELINE_GRAPH_ENGINE)\",\"distance\":\"$(PIPELINE_DISTANCE)\",\"chunk_chars\":$(PIPELINE_CHUNK_CHARS),\"chunk_overlap\":$(PIPELINE_CHUNK_OVERLAP),\"batch\":$(PIPELINE_BATCH),\"timeout\":$(PIPELINE_TIMEOUT),\"base_url\":\"$(PIPELINE_BASE_URL)\""; \
	if [ "$(PIPELINE_GRAPH)" = "true" ] || [ "$(PIPELINE_GRAPH)" = "1" ]; then payload="$$payload,\\\"graph\\\":true"; fi; \
	if [ -n "$(PIPELINE_OUTPUT_DIR)" ]; then payload="$$payload,\\\"output_dir\\\":\\\"$(PIPELINE_OUTPUT_DIR)\\\""; fi; \
	if [ -n "$(PIPELINE_LABEL)" ]; then payload="$$payload,\\\"label\\\":\\\"$(PIPELINE_LABEL)\\\""; fi; \
	if [ -n "$(PIPELINE_COLLECTION)" ]; then payload="$$payload,\\\"collection\\\":\\\"$(PIPELINE_COLLECTION)\\\""; fi; \
	payload="$$payload}"; \
	curl -fsS http://localhost:8080/api/pipeline/start -H "Content-Type: application/json" -d "$$payload" | python3 -m json.tool

logs-core:
	@$(COMPOSE_CMD) logs -f qdrant mysql nginx $(OLLAMA_SERVICE) app

logs-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@docker compose -f $(OPS_COMPOSE) --env-file $(ENV_FILE) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mysql nginx $(OLLAMA_SERVICE) app

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
