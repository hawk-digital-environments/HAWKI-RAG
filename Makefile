# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose

# Core stack variables (override via `make VAR=value`)
ENV_FILE ?= .env
HOST_OS := $(shell uname -s)

BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
LOCAL_OVERRIDE_COMPOSE ?= docker-compose.local.yml
COMPOSE_FILE_SEP ?= :
# USE_OLLAMA_GPU: auto (default), 1 (force GPU override), 0 (force CPU mode)
USE_OLLAMA_GPU ?= auto
CORE_PROFILES_BASE ?= pipeline-events

ifeq ($(USE_OLLAMA_GPU),auto)
	ifeq ($(HOST_OS),Linux)
		USE_OLLAMA_GPU := $(shell if command -v nvidia-smi >/dev/null 2>&1; then echo 1; else echo 0; fi)
	else
		USE_OLLAMA_GPU := 0
	endif
endif

OLLAMA_SERVICE := ollama
OLLAMA_CONTAINER ?= hawki_ollama

CORE_GPU_COMPOSE_SUFFIX :=
CORE_PROFILES := $(CORE_PROFILES_BASE)

ifeq ($(USE_OLLAMA_GPU),1)
	CORE_GPU_COMPOSE_SUFFIX := $(COMPOSE_FILE_SEP)$(GPU_OVERRIDE_COMPOSE)
	CORE_PROFILES := gpu,$(CORE_PROFILES_BASE)
	GPU_MESSAGE := Ollama GPU override enabled.
else
	GPU_MESSAGE := Ollama CPU mode.
endif

CORE_SERVER_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)
CORE_LOCAL_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)$(COMPOSE_FILE_SEP)$(LOCAL_OVERRIDE_COMPOSE)
COMPOSE_FILE_LIST ?= $(CORE_SERVER_COMPOSE_FILE_LIST)
COMPOSE_PROFILES ?= $(CORE_PROFILES)
PROFILE_MESSAGE ?= $(GPU_MESSAGE)

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
COMPOSE_CMD = $(COMPOSE_ENV_PREFIX) COMPOSE_FILE=$(COMPOSE_FILE_LIST) $(if $(strip $(COMPOSE_PROFILES)),COMPOSE_PROFILES=$(COMPOSE_PROFILES)) $(COMPOSE_BIN) --env-file $(ENV_FILE)


.PHONY: clean network pull-core build-app migrate-core _up-core up-core up-core-server health pull-models scraped-folders crawl convert ingest convert-ingest-folder pipeline logs-core down-core down-rag restart-core test-services neo4j-fresh python-test python-deps

clean:
	@find . -type d -name "__pycache__" -exec rm -rf {} +
	@find . -type f -name "*.py[cod]" -delete
	@find . -type f -name "*.pyc" -delete
	@find . -type f -name "*.pyo" -delete
	@find . -type f -name "*.log" -delete
	@rm -rf .pytest_cache .ruff_cache .mypy_cache .coverage* .tox .venv dist build

python-deps:
	@python3 -m pip install --upgrade pip setuptools wheel
	@python3 -m pip install -r python_rag/requirements.txt

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

migrate-core:
	@echo "Running Laravel migrations..."
	@attempt=1; \
	while [ "$$attempt" -le 30 ]; do \
		if $(COMPOSE_CMD) exec -T hawki_rag_app php artisan migrate --force; then \
			$(COMPOSE_CMD) exec -T hawki_rag_app php artisan optimize:clear >/dev/null 2>&1 || true; \
			echo "Laravel migrations are up to date."; \
			exit 0; \
		fi; \
		echo "Migration attempt $$attempt failed; retrying in 2s..."; \
		attempt=$$((attempt + 1)); \
		sleep 2; \
	done; \
	echo "Laravel migrations failed after 30 attempts."; \
	exit 1

_up-core: network
	@echo $(PROFILE_MESSAGE)
	@echo "Launching full stack (COMPOSE_FILE=$(COMPOSE_FILE_LIST), profiles: $(if $(strip $(COMPOSE_PROFILES)),$(COMPOSE_PROFILES),none))..."
	@$(COMPOSE_CMD) up -d --build --remove-orphans
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true
	@$(MAKE) --no-print-directory migrate-core COMPOSE_FILE_LIST="$(COMPOSE_FILE_LIST)" COMPOSE_PROFILES="$(COMPOSE_PROFILES)" ENV_FILE="$(ENV_FILE)" COMPOSE_BIN="$(COMPOSE_BIN)"

up-core: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
up-core: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core: PROFILE_MESSAGE = $(GPU_MESSAGE) Local override enabled and pipeline event workers enabled.
up-core: _up-core

up-core-server: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core-server: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-server: PROFILE_MESSAGE = $(GPU_MESSAGE) Server mode and pipeline event workers enabled.
up-core-server: _up-core

health:
	@set +e; \
	failed=0; \
	check_running() { \
		name="$$1"; required="$${2:-1}"; \
		printf "%-38s" "$$name:"; \
		state=$$(docker inspect -f '{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "$$name" 2>&1); \
		inspect_status=$$?; \
		if [ "$$inspect_status" != "0" ]; then \
			if printf "%s" "$$state" | grep -qi "permission denied"; then \
				if [ "$$required" = "1" ]; then echo "FAIL (docker unavailable: permission denied)"; failed=1; else echo "WARN (docker unavailable: permission denied)"; fi; \
				return; \
			fi; \
			if [ "$$required" = "1" ]; then echo "FAIL (container missing)"; failed=1; else echo "SKIPPED (container missing)"; fi; \
			return; \
		fi; \
		running=$$(printf "%s" "$$state" | awk '{print $$1}'); \
		health=$$(printf "%s" "$$state" | awk '{print $$2}'); \
		if [ "$$running" != "true" ]; then \
			if [ "$$required" = "1" ]; then echo "FAIL ($$state)"; failed=1; else echo "WARN ($$state)"; fi; \
		elif [ "$$health" = "healthy" ] || [ "$$health" = "no-healthcheck" ]; then \
			echo "OK ($$health)"; \
		else \
			echo "WARN ($$state)"; \
		fi; \
	}; \
	check_exec() { \
		label="$$1"; container="$$2"; command="$$3"; required="$${4:-1}"; \
		printf "%-38s" "$$label:"; \
		if docker exec "$$container" sh -lc "$$command" >/dev/null 2>&1; then \
			echo "OK"; \
		elif [ "$$required" = "1" ]; then \
			echo "FAIL"; failed=1; \
		else \
			echo "WARN"; \
		fi; \
	}; \
	check_url() { \
		label="$$1"; url="$$2"; required="$${3:-1}"; \
		printf "%-38s" "$$label:"; \
		if curl -fsS -m 5 "$$url" >/dev/null 2>&1; then \
			echo "OK"; \
		elif [ "$$required" = "1" ]; then \
			echo "FAIL"; failed=1; \
		else \
			echo "WARN"; \
		fi; \
	}; \
	echo "Container status"; \
	check_running mariadb 1; \
	check_running rabbitmq 1; \
	check_running hawki_rag_app 1; \
	check_running hawki_qdrant 1; \
	check_running hawki_rag_neo4j 1; \
	check_running $(OLLAMA_CONTAINER) 1; \
	check_running hawki_rag_bridge 0; \
	check_running hawki_rag_rerank 0; \
	check_running hawki_rag_scrape_monitor_worker 0; \
	check_running hawki_rag_scraper_event_worker 0; \
	check_running hawki_rag_converter_event_worker 0; \
	check_running hawki_rag_ingestion_event_worker 0; \
	echo ""; \
	echo "Service checks"; \
	check_exec "MariaDB ping" mariadb 'mariadb-admin ping -h 127.0.0.1 -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD"' 1; \
	check_exec "RabbitMQ ping" rabbitmq "rabbitmq-diagnostics -q ping" 1; \
	check_exec "Laravel artisan" hawki_rag_app "php artisan list --raw" 1; \
	check_exec "Qdrant readyz" hawki_qdrant "curl -fsS http://localhost:6333/readyz" 1; \
	check_exec "Neo4j browser" hawki_rag_neo4j "wget --spider -q http://localhost:7474/browser" 1; \
	check_exec "Ollama models" $(OLLAMA_CONTAINER) "ollama list" 1; \
	check_exec "Ingestion bridge" hawki_rag_bridge "curl -fsS http://localhost:8000/health" 0; \
	check_exec "Local reranker" hawki_rag_rerank "curl -fsS http://localhost:8000/health" 0; \
	check_url "Laravel HTTP" "http://127.0.0.1:8080/rag/health" 0; \
	echo ""; \
	if [ "$$failed" = "0" ]; then \
		echo "Health checks completed."; \
	else \
		echo "Health checks completed with required failures."; \
		exit 1; \
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

python-test:
	@PYTHONPATH=python_rag python -m unittest discover -s python_rag/tests -p 'test_*.py'

pull-models:
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull bge-m3
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.1:8b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:1b

# Pipeline helper variables (override via `make VAR=value`)
ARTISAN ?= docker exec hawki_rag_app php artisan
URL ?=
JOB_ID_FULL ?=
LABEL ?= $(if $(JOB_ID_FULL),$(JOB_ID_FULL),manual-crawl)
CRAWLED_ROOT ?= /app/shared/crawled-data
SHARED_ROOT ?= /app/shared
SCRAPED_FOLDER ?=
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
AUTOMATION ?= $(or $(HAWKI_RAG_PIPELINE_AUTOMATION),false)
EXISTING ?= $(or $(HAWKI_RAG_CONVERT_EXISTING_MODE),continue)
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
RESUME_MODE ?= $(or $(HAWKI_RAG_INGEST_RESUME_MODE),resume)
DRY ?= false
ESTIMATE_ONLY ?= false
SUMMARY_FILE ?=

scraped-folders:
	@docker exec hawki_rag_app sh -lc 'root="$(SHARED_ROOT)"; \
		folders=$$(find "$$root" -mindepth 2 -maxdepth 2 -name completed_urls.json -exec dirname {} \; 2>/dev/null | sort); \
		count=$$(printf "%s\n" "$$folders" | sed "/^$$/d" | wc -l | tr -d " "); \
		echo "Found $$count scraped folder(s) under $$root"; \
		if [ "$$count" -gt 0 ]; then printf "%s\n" "$$folders" | nl -w1 -s". "; fi; \
		echo ""; \
		echo "Convert and ingest one folder with:"; \
		echo "  make convert-ingest-folder SCRAPED_FOLDER=/app/shared/<folder-name>"'

crawl:
	@if [ -z "$(URL)" ]; then echo "Set URL, for example: make crawl URL=https://www.hawk.de JOB_ID_FULL=manual_001"; exit 2; fi
	@EXTRA_FLAGS=""; \
	if [ "$(SKIP_IMAGES)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --skip-images"; fi; \
	if [ -n "$(IMAGE_EXCEPTIONS)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --image-exceptions='$(IMAGE_EXCEPTIONS)'"; fi; \
	if [ -n "$(DATE_SELECTOR)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --date='$(DATE_SELECTOR)'"; fi; \
	if [ -n "$(REQUEST_DELAY)" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --request-delay=$(REQUEST_DELAY)"; fi; \
	if [ "$(DISCOVERY_MODE)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --discovery-mode"; fi; \
		docker exec -e HAWKI_RAG_PIPELINE_AUTOMATION="$(AUTOMATION)" hawki_rag_app php artisan scraper:scrape "$(URL)" --max-pages=$(MAX_PAGES) --output-dir="$(OUTPUT_DIR)" --label="$(LABEL)" --max-concurrency=$(MAX_CONCURRENCY) --max-rpm=$(MAX_RPM) $$EXTRA_FLAGS

convert:
	@if [ "$(OUTPUT_DIR)" = "/absolute/path/to/crawled-data" ]; then echo "Set OUTPUT_DIR to the crawl output directory"; exit 2; fi
	@EXTRA_FLAGS=""; \
	if [ "$(SCAN_ALL)" = "true" ]; then EXTRA_FLAGS="$$EXTRA_FLAGS --scan-all"; fi; \
		docker exec -e HAWKI_RAG_PIPELINE_AUTOMATION="$(AUTOMATION)" -e HAWKI_RAG_CONVERT_EXISTING_MODE="$(EXISTING)" hawki_rag_app php artisan convert:crawled-pdfs "$(OUTPUT_DIR)" --extensions="$(EXTENSIONS)" --existing="$(EXISTING)" $$EXTRA_FLAGS

ingest:
	@if [ "$(CRAWLED_ROOT)" = "/absolute/path/to/crawled-data" ]; then echo "Set CRAWLED_ROOT to a path mounted in shared storage (default /app/shared inside hawki_rag_bridge)" && exit 2; fi
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
		docker exec -e HAWKI_RAG_PIPELINE_AUTOMATION="$(AUTOMATION)" -e HAWKI_RAG_INGEST_RESUME_MODE="$(RESUME_MODE)" hawki_rag_bridge sh -lc "python -m application.cli.commands.ingest_crawled --root $(CRAWLED_ROOT) --base-url $(BASE_URL) --provider $(PROVIDER) --graph-engine $(GRAPH_ENGINE) $$EXTRA_FLAGS --batch $(BATCH)"

convert-ingest-folder:
	@if [ -z "$(SCRAPED_FOLDER)" ]; then \
		echo "Set SCRAPED_FOLDER to one of the folders below:"; \
		$(MAKE) --no-print-directory scraped-folders; \
		exit 2; \
	fi
	@$(MAKE) convert OUTPUT_DIR="$(SCRAPED_FOLDER)" EXTENSIONS="$(EXTENSIONS)" EXISTING="$(EXISTING)" SCAN_ALL="$(SCAN_ALL)"
	@$(MAKE) ingest CRAWLED_ROOT="$(SCRAPED_FOLDER)" COLLECTION="$(COLLECTION)" GRAPH="$(GRAPH)" GRAPH_ONLY="$(GRAPH_ONLY)" GRAPH_ENGINE="$(GRAPH_ENGINE)" EMBEDDING_MODEL="$(EMBEDDING_MODEL)" NEO4J_DATABASE="$(NEO4J_DATABASE)" CHUNK_CHARS="$(CHUNK_CHARS)" CHUNK_OVERLAP="$(CHUNK_OVERLAP)" BATCH="$(BATCH)" PROVIDER="$(PROVIDER)" BASE_URL="$(BASE_URL)" TIMEOUT="$(TIMEOUT)" RESUME_MODE="$(RESUME_MODE)" DRY="$(DRY)" ESTIMATE_ONLY="$(ESTIMATE_ONLY)" SUMMARY_FILE="$(SUMMARY_FILE)"

pipeline: crawl convert
	@$(MAKE) ingest CRAWLED_ROOT="$(OUTPUT_DIR)" COLLECTION="$(COLLECTION)" GRAPH="$(GRAPH)" GRAPH_ONLY="$(GRAPH_ONLY)" GRAPH_ENGINE="$(GRAPH_ENGINE)" EMBEDDING_MODEL="$(EMBEDDING_MODEL)" NEO4J_DATABASE="$(NEO4J_DATABASE)" CHUNK_CHARS="$(CHUNK_CHARS)" CHUNK_OVERLAP="$(CHUNK_OVERLAP)" BATCH="$(BATCH)" PROVIDER="$(PROVIDER)" BASE_URL="$(BASE_URL)" TIMEOUT="$(TIMEOUT)" RESUME_MODE="$(RESUME_MODE)" DRY="$(DRY)" ESTIMATE_ONLY="$(ESTIMATE_ONLY)" SUMMARY_FILE="$(SUMMARY_FILE)"

logs-core:
	@$(COMPOSE_CMD) logs -f qdrant mariadb rabbitmq $(OLLAMA_SERVICE) hawki_rag_app hawki-rag-scrape-monitor-worker hawki-rag-scraper-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker
	@$(COMPOSE_CMD) logs -f

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@$(COMPOSE_CMD) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate qdrant mariadb hawki_rag_nginx $(OLLAMA_SERVICE) hawki_rag_app
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
