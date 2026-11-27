# ==============================================================================
# HAWKI RAG development and operations commands
# ==============================================================================
#
# Start with `make help` to see the public commands grouped by responsibility.
# Variables can be overridden per invocation, for example:
#
#   make up-core USE_OLLAMA_GPU=0 ENV_FILE=.env.production
#   make up-core-local
#
# Internal implementation targets start with an underscore and are intentionally
# omitted from the help output.

SHELL := /bin/bash
.DEFAULT_GOAL := help

# ==============================================================================
# Docker Compose configuration
# ==============================================================================

# Compose executable, environment, and file layers.
COMPOSE_BIN ?= docker compose
COMPOSE_PARALLEL_LIMIT ?= 1
ENV_FILE ?= .env
BUILD_STACK ?= 1
HOST_OS := $(shell uname -s)
BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
LOCAL_OVERRIDE_COMPOSE ?= docker-compose.local.yml
COMPOSE_FILE_SEP ?= :
PYTHON_UV_CACHE ?= /tmp/rawki-uv-cache
PYTHON_RERANKER_ENV ?= .venv-reranker
PYTHON_DATA_PLANE_PACKAGES := \
	--package hawki-workflow-worker \
	--package hawki-scraper-worker \
	--package hawki-converter-worker \
	--package hawki-indexer-worker

# Ollama acceleration policy: auto (default), 1 (force GPU), or 0 (force CPU).
USE_OLLAMA_GPU ?= auto
CORE_PROFILES_BASE ?=

ifeq ($(USE_OLLAMA_GPU),auto)
	ifeq ($(HOST_OS),Linux)
		USE_OLLAMA_GPU := $(shell if command -v nvidia-smi >/dev/null 2>&1; then echo 1; else echo 0; fi)
	else
		USE_OLLAMA_GPU := 0
	endif
endif

OLLAMA_SERVICE := ollama
OLLAMA_CONTAINER ?= hawki_ollama
FILE_CONVERTER_CONTAINER ?= hawki-toolkit-file-converter-file-converter-1

# Sibling file-converter checkout.
FILE_CONVERTER_DIR ?= ../hawki-toolkit-file-converter
FILE_CONVERTER_PROJECT ?= hawki-toolkit-file-converter

# Derived Compose suffixes and profiles.
CORE_GPU_COMPOSE_SUFFIX :=
CORE_PROFILES := $(CORE_PROFILES_BASE)

ifeq ($(USE_OLLAMA_GPU),1)
	CORE_GPU_COMPOSE_SUFFIX := $(COMPOSE_FILE_SEP)$(GPU_OVERRIDE_COMPOSE)
	PYTHON_TORCH_EXTRA := gpu
	GPU_MESSAGE := Ollama and reranker GPU acceleration enabled.
else
	PYTHON_TORCH_EXTRA := cpu
	GPU_MESSAGE := CPU mode; NVIDIA Python packages are excluded.
endif

CORE_SERVER_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)
CORE_LOCAL_COMPOSE_FILE_LIST := $(CORE_SERVER_COMPOSE_FILE_LIST)$(COMPOSE_FILE_SEP)$(LOCAL_OVERRIDE_COMPOSE)
COMPOSE_FILE_LIST ?= $(CORE_SERVER_COMPOSE_FILE_LIST)
COMPOSE_PROFILES ?= $(CORE_PROFILES)
PROFILE_MESSAGE ?= $(GPU_MESSAGE)

# Host compatibility. Clearing an inherited amd64 platform avoids incorrect
# image selection on Apple Silicon.
COMPOSE_ENV_PREFIX :=
ifeq ($(HOST_OS),Darwin)
	COMPOSE_ENV_PREFIX := DOCKER_DEFAULT_PLATFORM=
endif

# Canonical wrapper used by every Compose-backed command.
COMPOSE_CMD = $(COMPOSE_ENV_PREFIX) \
	COMPOSE_PARALLEL_LIMIT=$(COMPOSE_PARALLEL_LIMIT) \
	COMPOSE_FILE=$(COMPOSE_FILE_LIST) \
	RAG_COMPOSE_ENV_FILE="$(ENV_FILE)" \
	$(if $(strip $(COMPOSE_PROFILES)),COMPOSE_PROFILES=$(COMPOSE_PROFILES)) \
	$(COMPOSE_BIN) --env-file $(ENV_FILE)

# ==============================================================================
# Target registry and command discovery
# ==============================================================================

.PHONY: help
.PHONY: clean python-lock python-deps test-backend test-rag
.PHONY: network pull-core build-backend
.PHONY: migrate-core
.PHONY: _up-core up-core up-core-local up-core-server
.PHONY: health
.PHONY: pull-models logs-core
.PHONY: down-core down-rag restart-core teardown
.PHONY: neo4j-fresh
.PHONY: up-file-converter-tool down-file-converter-tool
.PHONY: up-tools down-tools

##@ General
help: ## Show the available commands grouped by module.
	@awk 'BEGIN { FS = ":.*## "; printf "HAWKI RAG commands\n" } /^##@/ { printf "\n%s\n", substr($$0, 5) } /^[a-zA-Z0-9_-]+:.*## / { printf "  %-20s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

# ==============================================================================
# Workspace and Python dependencies
# ==============================================================================

##@ Workspace

clean: ## Remove generated Python caches, logs, coverage, and build artifacts.
	@find . -type d -name "__pycache__" -exec rm -rf {} +
	@find . -type f -name "*.py[cod]" -delete
	@find . -type f -name "*.pyc" -delete
	@find . -type f -name "*.pyo" -delete
	@find . -type f -name "*.log" -delete
	@rm -rf .pytest_cache .ruff_cache .mypy_cache .coverage* .tox .venv python_rag/.venv python_rag/.venv-reranker dist build

python-lock: ## Resolve the Python 3.13 workspace and verify CPU and CUDA variants.
	@command -v uv >/dev/null 2>&1 || { echo "uv is required to resolve the Python workspace"; exit 1; }
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv lock
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv lock --check
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv export --quiet --frozen --package hawki-reranker --extra cpu --no-dev --output-file /tmp/hawki-reranker.cpu.txt
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv export --quiet --frozen --package hawki-reranker --extra gpu --no-dev --output-file /tmp/hawki-reranker.gpu.txt
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv export --quiet --frozen --package hawki-indexer-worker --extra cpu --no-dev --output-file /tmp/hawki-indexer.cpu.txt
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv export --quiet --frozen --package hawki-indexer-worker --extra gpu --no-dev --output-file /tmp/hawki-indexer.gpu.txt
	@! grep -Eq '^(cuda-|nvidia-)' /tmp/hawki-reranker.cpu.txt /tmp/hawki-indexer.cpu.txt || \
		{ echo "CPU dependency resolution unexpectedly contains CUDA packages"; exit 1; }
	@grep -q 'torch==2.13.0' /tmp/hawki-reranker.cpu.txt /tmp/hawki-reranker.gpu.txt /tmp/hawki-indexer.cpu.txt /tmp/hawki-indexer.gpu.txt
	@grep -q 'numpy==2.5.1' /tmp/hawki-indexer.cpu.txt /tmp/hawki-indexer.gpu.txt
	@grep -q 'av==13.1.0' /tmp/hawki-indexer.cpu.txt /tmp/hawki-indexer.gpu.txt

python-deps: ## Sync the data plane and isolated reranker uv environments.
	@command -v uv >/dev/null 2>&1 || { echo "uv is required to sync the Python workspace"; exit 1; }
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv sync --frozen --group test --extra "$(PYTHON_TORCH_EXTRA)" $(PYTHON_DATA_PLANE_PACKAGES)
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv sync --frozen --only-group lint --inexact
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" UV_PROJECT_ENVIRONMENT="$(PYTHON_RERANKER_ENV)" uv sync --frozen --group test --package hawki-reranker --extra "$(PYTHON_TORCH_EXTRA)"

# ==============================================================================
# Docker foundation and application images
# ==============================================================================

##@ Docker foundation

network: ## Create the external network used by local workers and helper services.
	@docker network inspect hawki-network >/dev/null 2>&1 || docker network create hawki-network

pull-core: ## Pull third-party runtime images used directly by the core stack.
	@$(COMPOSE_CMD) pull postgres temporal neo4j $(OLLAMA_SERVICE)

build-backend: ## Build the FastAPI backend image.
	@$(COMPOSE_CMD) build rag_backend

# ==============================================================================
# Database lifecycle
# ==============================================================================

##@ Database

migrate-core: ## Explicit Python schema migration using an operator-provided migration DSN.
	@cd python_backend && uv run --frozen alembic upgrade head

# ==============================================================================
# Stack startup profiles
# ==============================================================================

##@ Stack startup

_up-core: network
	@echo "$(PROFILE_MESSAGE)"
	@if [ "$(BUILD_STACK)" = "1" ]; then \
		echo "Building stack images (COMPOSE_FILE=$(COMPOSE_FILE_LIST), profiles: $(if $(strip $(COMPOSE_PROFILES)),$(COMPOSE_PROFILES),none))..."; \
		$(COMPOSE_CMD) build; \
	else \
		echo "Reusing existing development images (set BUILD_STACK=1 to rebuild them)."; \
	fi
	@echo "Starting the Python runtime; pre-provisioned schema, role and JWT readiness are required."
	@$(COMPOSE_CMD) up -d --wait $(OLLAMA_SERVICE)
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b; do \
		echo "Pulling $$model..."; \
		$(COMPOSE_CMD) exec -T $(OLLAMA_SERVICE) ollama pull $$model || exit $$?; \
	done
	@for container in "$(FILE_CONVERTER_CONTAINER)"; do \
		if docker container inspect "$$container" >/dev/null 2>&1; then \
			docker network connect hawki-network "$$container" >/dev/null 2>&1 || true; \
			echo "$$container is connected to hawki-network."; \
		else \
			echo "Optional external service $$container is not running; skipping network connection."; \
		fi; \
	done
	@$(COMPOSE_CMD) up -d --wait

up-core: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core: PROFILE_MESSAGE = $(GPU_MESSAGE) Runtime mode loaded from $(ENV_FILE), on the private Docker network.
up-core: _up-core ## Start the private stack using ENV_FILE.

up-core-local: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
up-core-local: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-local: PROFILE_MESSAGE = $(GPU_MESSAGE) Python local development using $(ENV_FILE).
up-core-local: _up-core ## Start the stack with a local service issuer.

up-core-server: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core-server: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-server: PROFILE_MESSAGE = $(GPU_MESSAGE) Runtime settings loaded from $(ENV_FILE).
up-core-server: _up-core ## Start the headless stack using ENV_FILE.

# ==============================================================================
# Health and service diagnostics
# ==============================================================================

##@ Diagnostics

health: ## Check required and optional containers plus service endpoints.
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
	echo "Container status"; \
	check_running hawki_rag_postgres 1; \
	check_running temporal 1; \
	check_running rag_backend 1; \
	check_running hawki_qdrant 1; \
	check_running neo4j 1; \
	check_running $(OLLAMA_CONTAINER) 1; \
	check_running rag_reranker 0; \
	check_running "$$( $(COMPOSE_CMD) ps -q workflow_worker )" 1; \
	check_running "$$( $(COMPOSE_CMD) ps -q scraper_worker )" 1; \
	check_running "$$( $(COMPOSE_CMD) ps -q converter_worker )" 1; \
	check_running "$$( $(COMPOSE_CMD) ps -q indexer_worker )" 1; \
	echo ""; \
	echo "Service checks"; \
	check_exec "PostgreSQL ping" hawki_rag_postgres 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"' 1; \
	check_exec "FastAPI readiness" rag_backend "python -m hawki_backend.readiness --live" 1; \
	check_exec "Qdrant readyz" hawki_qdrant "curl -fsS http://localhost:6333/readyz" 1; \
	check_exec "Neo4j browser" neo4j "wget --spider -q http://localhost:7474/browser" 1; \
	check_exec "Ollama models" $(OLLAMA_CONTAINER) "ollama list" 1; \
	check_exec "Local reranker" rag_reranker "python -c 'import urllib.request; urllib.request.urlopen(\"http://localhost/health\", timeout=5).read()'" 0; \
	check_exec "FastAPI HTTP" rag_backend "python -c 'import urllib.request; urllib.request.urlopen(\"http://127.0.0.1/v1/health\", timeout=5).read()'" 1; \
	echo ""; \
	if [ "$$failed" = "0" ]; then \
		echo "Health checks completed."; \
	else \
		echo "Health checks completed with required failures."; \
		exit 1; \
	fi

# ==============================================================================
# Python tests
# ==============================================================================

##@ Tests

test-backend: ## Run the Python API tests.
	@cd python_backend && uv run --frozen pytest

test-rag: ## Run deterministic Python worker/package tests.
	@cd python_rag && uv run --frozen pytest -m "not integration"


# ==============================================================================
# Ollama model management
# ==============================================================================

##@ Models

pull-models: ## Pull all local embedding, chat, and vision models into Ollama.
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull bge-m3
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.1:8b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:1b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull qwen2.5vl:7b

# ==============================================================================
# Runtime logs
# ==============================================================================

##@ Logs

logs-core: ## Follow logs for the complete core stack.
	@$(COMPOSE_CMD) logs -f postgres temporal qdrant neo4j $(OLLAMA_SERVICE) rag_backend rag_reranker workflow_worker scraper_worker converter_worker indexer_worker

# ==============================================================================
# Stack shutdown and restart
# ==============================================================================

##@ Stack lifecycle

KEEP_OLLAMA_MODELS ?= 0
TEARDOWN_DATA_VOLUMES ?= hawki-rag_hawki_postgres_data hawki-rag_qdrant_data hawki-rag_neo4j_data rawki_shared_storage

down-core: ## Stop and remove the active HAWKI RAG Compose stack.
	@$(COMPOSE_CMD) down

down-rag: down-core ## Backward-compatible alias for stopping the RAG stack.

restart-core: network ## Recreate all core services and Temporal workers.
	@echo "$(PROFILE_MESSAGE)"
	@$(COMPOSE_CMD) up -d --force-recreate

teardown: ## DESTRUCTIVE: stop everything and remove all data volumes.
	@set -e; \
	echo "Tearing down the HAWKI RAG stack ($(if $(filter 1 true yes on,$(KEEP_OLLAMA_MODELS)),keeping,removing)) Ollama model cache..."; \
	$(MAKE) --no-print-directory down-tools >/dev/null 2>&1 \
		|| echo "External tools are not running or sibling checkouts are missing; continuing."; \
	if [ "$(KEEP_OLLAMA_MODELS)" = "1" ]; then \
		$(COMPOSE_CMD) down --remove-orphans; \
		for volume in $(TEARDOWN_DATA_VOLUMES); do \
			if docker volume inspect "$$volume" >/dev/null 2>&1; then \
				if docker volume rm "$$volume" >/dev/null 2>&1; then \
					echo "Removed volume $$volume"; \
				else \
					echo "WARNING: volume $$volume is still in use and was not removed."; \
				fi; \
			else \
				echo "Volume $$volume already absent; skipping."; \
			fi; \
		done; \
		echo "Kept volume hawki-rag_ollama (KEEP_OLLAMA_MODELS=1)."; \
	else \
		$(COMPOSE_CMD) down --volumes --remove-orphans; \
		echo "Removed all project volumes including hawki-rag_ollama."; \
	fi; \
	echo "Teardown complete. Recreate everything with: make up-core"

# ==============================================================================
# External file-converter tool
# ==============================================================================

##@ External tools

up-file-converter-tool: network ## Start the sibling file-converter container (health on port 8004).
	@set -e; \
	if [ ! -d "$(FILE_CONVERTER_DIR)" ]; then \
		echo "Missing sibling checkout: $(FILE_CONVERTER_DIR) (override with FILE_CONVERTER_DIR)."; \
		exit 1; \
	fi; \
	if ! docker volume inspect rawki_shared_storage >/dev/null 2>&1; then \
		echo "Creating the external rawki_shared_storage volume..."; \
		docker volume create rawki_shared_storage; \
	fi; \
	echo "Starting the file converter from $(FILE_CONVERTER_DIR)..."; \
	converter_token=$$(grep -m1 '^EXTERNAL_CONVERTER_TOKEN=' "$(ENV_FILE)" 2>/dev/null | cut -d= -f2-); \
	if [ -z "$$converter_token" ]; then \
		converter_token=$$(grep -m1 '^FILE_CONVERTER_TOKEN=' "$(ENV_FILE)" 2>/dev/null | cut -d= -f2-); \
	fi; \
	if [ -n "$$converter_token" ]; then \
		export F_API_KEY="$$converter_token"; \
	fi; \
	cd "$(FILE_CONVERTER_DIR)" && \
		$(COMPOSE_BIN) -p "$(FILE_CONVERTER_PROJECT)" -f docker-compose.yml -f docker-compose.local.yml up -d --build; \
	echo "Converter health: http://localhost:8004/health"

down-file-converter-tool: ## Stop the sibling file-converter container.
	@cd "$(FILE_CONVERTER_DIR)" && \
		$(COMPOSE_BIN) -p "$(FILE_CONVERTER_PROJECT)" -f docker-compose.yml -f docker-compose.local.yml down

up-tools: up-file-converter-tool ## Start the local converter.
down-tools: down-file-converter-tool ## Stop the local converter.

# ==============================================================================
# Destructive data maintenance
# ==============================================================================

##@ Maintenance

neo4j-fresh: ## DESTRUCTIVE: recreate Neo4j with an empty persisted store.
	@echo "Stopping Neo4j service..."
	@$(COMPOSE_CMD) stop neo4j >/dev/null 2>&1 || true
	@$(COMPOSE_CMD) rm -f neo4j >/dev/null 2>&1 || true
	@echo "Removing persisted Neo4j data (databases, transactions)..."
	@$(COMPOSE_CMD) run --rm --entrypoint bash neo4j -lc 'rm -rf /data/databases/* /data/transactions/*' >/dev/null
	@echo "Starting Neo4j service..."
	@$(COMPOSE_CMD) up -d neo4j >/dev/null
	@echo "Neo4j store reset complete."
