# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose

# Variables (override via `make VAR=value`)
ENV_FILE ?= .env
ARTISAN ?= docker exec hawki_rag_app php artisan
URL ?=
JOB_ID_FULL ?=
LABEL ?= $(if $(JOB_ID_FULL),$(JOB_ID_FULL),manual-crawl)
CRAWLED_ROOT ?= /app/shared/crawled-data
OUTPUT_DIR ?= $(CRAWLED_ROOT)/$(LABEL)
MAX_PAGES ?= 100
SITEMAP_PAGES ?= 100
MAX_PAGES_FULL ?=
SKIP_IMAGES ?= true
IMAGE_EXCEPTIONS ?=
DATE_SELECTOR ?=
MAX_CONCURRENCY ?= 4
MAX_RPM ?= 60
REQUEST_DELAY ?=
DISCOVERY_MODE ?= false
EXTENSIONS ?= pdf,doc,docx
SCAN_ALL ?= false
EXISTING ?= continue
GRAPH ?= true
GRAPH_ONLY ?= false
GRAPH_ENGINE ?= raganything
EMBEDDING_MODEL ?=
COLLECTION ?=
NEO4J_DATABASE ?=
CHUNK_CHARS ?=
CHUNK_OVERLAP ?=
BATCH ?= 16
PROVIDER ?= ollama
BASE_URL ?= http://localhost:8000
TIMEOUT ?=
RESUME_MODE ?= resume
DRY ?= false
ESTIMATE_ONLY ?= false
SUMMARY_FILE ?=

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
SCHEDULER_DB_HOST ?= 127.0.0.1
SCHEDULER_DB_PORT ?= 3306
SCRAPER_REPO_HOST_PATH ?= $(CURDIR)
RAG_REPO_HOST_PATH ?= $(CURDIR)
SCHEDULER_ARTISAN_ENV = DB_HOST=$(SCHEDULER_DB_HOST) DB_PORT=$(SCHEDULER_DB_PORT) SCRAPER_REPO_PATH=$(SCRAPER_REPO_HOST_PATH) RAG_REPO_PATH=$(RAG_REPO_HOST_PATH)

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


.PHONY: network pull-core build-app up-core health pull-models crawl convert crawl-and-convert ingest pipeline scheduler-run scheduled-crawls logs-core down-core down-rag restart-core test-services neo4j-fresh

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
	@echo "Launching full stack (COMPOSE_FILE=$(COMPOSE_FILE_LIST), profiles: $(if $(strip $(COMPOSE_PROFILES)),$(COMPOSE_PROFILES),none))..."
	@$(COMPOSE_CMD) up -d --build --remove-orphans
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true

health:
	@echo "Checking Qdrant..." && docker exec hawki_qdrant sh -lc "curl -fsS http://localhost:6333/readyz" >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
	@echo "Checking Ollama..." && docker exec $(OLLAMA_CONTAINER) sh -lc "ollama list" >/dev/null && echo " OK" || (echo " FAIL" && exit 1)
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

crawl:
	@if [ -z "$(URL)" ]; then echo "Set URL, for example: make crawl URL=https://www.hawk.de JOB_ID_FULL=manual_001"; exit 1; fi
	@EXTRA_FLAGS=""; \
	if [ "$(SKIP_IMAGES)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --skip-images"; fi; \
	if [ -n "$(IMAGE_EXCEPTIONS)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --image-exceptions='$(IMAGE_EXCEPTIONS)'"; fi; \
	if [ -n "$(DATE_SELECTOR)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --date='$(DATE_SELECTOR)'"; fi; \
	if [ -n "$(REQUEST_DELAY)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --request-delay=$(REQUEST_DELAY)"; fi; \
	if [ "$(DISCOVERY_MODE)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --discovery-mode"; fi; \
	$(ARTISAN) scraper:scrape "$(URL)" --max-pages=$(MAX_PAGES) --output-dir="$(OUTPUT_DIR)" --label="$(LABEL)" --max-concurrency=$(MAX_CONCURRENCY) --max-rpm=$(MAX_RPM) $$EXTRA_FLAGS

convert:
	@if [ "$(OUTPUT_DIR)" = "/absolute/path/to/crawled-data" ]; then echo "Set OUTPUT_DIR to the crawl output directory"; exit 1; fi
	@EXTRA_FLAGS=""; \
	if [ "$(SCAN_ALL)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --scan-all"; fi; \
	$(ARTISAN) convert:crawled-pdfs "$(OUTPUT_DIR)" --extensions="$(EXTENSIONS)" --existing="$(EXISTING)" $$EXTRA_FLAGS

crawl-and-convert:
	@if [ -z "$(URL)" ]; then echo "Set URL, for example: make crawl-and-convert URL=https://www.hawk.de JOB_ID_FULL=manual_001"; exit 1; fi
	@EXTRA_FLAGS=""; \
	if [ "$(SKIP_IMAGES)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --skip-images"; fi; \
	if [ "$(SCAN_ALL)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --scan-all"; fi; \
	if [ -n "$(IMAGE_EXCEPTIONS)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --image-exceptions='$(IMAGE_EXCEPTIONS)'"; fi; \
	if [ -n "$(DATE_SELECTOR)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --date='$(DATE_SELECTOR)'"; fi; \
	if [ -n "$(REQUEST_DELAY)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --request-delay=$(REQUEST_DELAY)"; fi; \
	if [ "$(DISCOVERY_MODE)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --discovery-mode"; fi; \
	$(ARTISAN) crawl:and-convert "$(URL)" --max-pages=$(MAX_PAGES) --output-dir="$(OUTPUT_DIR)" --label="$(LABEL)" --max-concurrency=$(MAX_CONCURRENCY) --max-rpm=$(MAX_RPM) --extensions="$(EXTENSIONS)" --existing="$(EXISTING)" $$EXTRA_FLAGS

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to a path mounted in shared storage (default /app/shared inside hawki_rag_bridge)" && exit 1; fi
	@EXTRA_FLAGS=""; \
	if [ "$(GRAPH)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --graph"; fi; \
	if [ "$(GRAPH_ONLY)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --graph-only"; fi; \
	if [ "$(DRY)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --dry"; fi; \
	if [ "$(ESTIMATE_ONLY)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --estimate-only"; fi; \
	if [ "$(RESUME_MODE)" = "resume" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --resume"; fi; \
	if [ "$(RESUME_MODE)" = "start" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --start"; fi; \
	if [ -n "$(EMBEDDING_MODEL)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --embedding-model $(EMBEDDING_MODEL)"; fi; \
	if [ -n "$(COLLECTION)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --collection $(COLLECTION)"; fi; \
	if [ -n "$(NEO4J_DATABASE)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --neo4j-database $(NEO4J_DATABASE)"; fi; \
	if [ -n "$(CHUNK_CHARS)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --chunk-chars $(CHUNK_CHARS)"; fi; \
	if [ -n "$(CHUNK_OVERLAP)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --chunk-overlap $(CHUNK_OVERLAP)"; fi; \
	if [ -n "$(TIMEOUT)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --timeout $(TIMEOUT)"; fi; \
	if [ -n "$(SUMMARY_FILE)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --summary-file $(SUMMARY_FILE)"; fi; \
	docker exec hawki_rag_bridge sh -lc "python /app/ingest/ingest_crawled.py --root $(CRAWLED_ROOT) --base-url $(BASE_URL) --provider $(PROVIDER) --graph-engine $(GRAPH_ENGINE) $$EXTRA_FLAGS --batch $(BATCH)"

pipeline: crawl convert
	@$(MAKE) ingest CRAWLED_ROOT="$(OUTPUT_DIR)" COLLECTION="$(COLLECTION)" GRAPH="$(GRAPH)" GRAPH_ONLY="$(GRAPH_ONLY)" GRAPH_ENGINE="$(GRAPH_ENGINE)" EMBEDDING_MODEL="$(EMBEDDING_MODEL)" NEO4J_DATABASE="$(NEO4J_DATABASE)" CHUNK_CHARS="$(CHUNK_CHARS)" CHUNK_OVERLAP="$(CHUNK_OVERLAP)" BATCH="$(BATCH)" PROVIDER="$(PROVIDER)" BASE_URL="$(BASE_URL)" TIMEOUT="$(TIMEOUT)" RESUME_MODE="$(RESUME_MODE)" DRY="$(DRY)" ESTIMATE_ONLY="$(ESTIMATE_ONLY)" SUMMARY_FILE="$(SUMMARY_FILE)"

scheduler-run:
	@$(SCHEDULER_ARTISAN_ENV) php artisan schedule:run

scheduled-crawls:
	@$(SCHEDULER_ARTISAN_ENV) php artisan rag:run-scheduled-crawls

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
