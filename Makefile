# ==============================================================================
# HAWKI RAG development and operations commands
# ==============================================================================
#
# Start with `make help` to see the public commands grouped by responsibility.
# Variables can be overridden per invocation, for example:
#
#   make up-core USE_OLLAMA_GPU=0 UI_AUTO_BUILD=0
#   make up-core-server ENV_FILE=.env.production
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
HOST_OS := $(shell uname -s)
BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
LOCAL_OVERRIDE_COMPOSE ?= docker-compose.local.yml
COMPOSE_FILE_SEP ?= :
COMMA := ,
PYTHON_MINERU_WHEEL_ROOT ?= /tmp/rawki-mineru-compat

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

# Derived Compose suffixes and profiles.
CORE_GPU_COMPOSE_SUFFIX :=
CORE_PROFILES := $(CORE_PROFILES_BASE)

ifeq ($(USE_OLLAMA_GPU),1)
	CORE_GPU_COMPOSE_SUFFIX := $(COMPOSE_FILE_SEP)$(GPU_OVERRIDE_COMPOSE)
	CORE_PROFILES := gpu$(if $(strip $(CORE_PROFILES_BASE)),$(COMMA)$(CORE_PROFILES_BASE),)
	GPU_MESSAGE := Ollama GPU override enabled.
else
	GPU_MESSAGE := Ollama CPU mode.
endif

CORE_SERVER_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)
CORE_LOCAL_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)$(COMPOSE_FILE_SEP)$(LOCAL_OVERRIDE_COMPOSE)
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
	$(if $(strip $(COMPOSE_PROFILES)),COMPOSE_PROFILES=$(COMPOSE_PROFILES)) \
	$(COMPOSE_BIN) --env-file $(ENV_FILE)

# ==============================================================================
# UI asset configuration
# ==============================================================================

UI_AUTO_BUILD ?= 1
UI_BUILD_DIR ?= /tmp/rawki-vite-build
UI_NODE_IMAGE ?= node:22-bookworm-slim
UI_NPM_CACHE_DIR ?= /tmp/rawki-npm-cache
UI_NODE_RUN = docker run --rm --entrypoint /usr/bin/env \
	--user "$$(id -u):$$(id -g)" \
	-e HOME=/tmp \
	-e npm_config_cache=/tmp/rawki-npm-cache \
	-v "$(CURDIR):/work" \
	-v "$(UI_BUILD_DIR):$(UI_BUILD_DIR)" \
	-v "$(UI_NPM_CACHE_DIR):/tmp/rawki-npm-cache" \
	-w /work $(UI_NODE_IMAGE)

# ==============================================================================
# Target registry and command discovery
# ==============================================================================

.PHONY: help
.PHONY: clean python-lock python-deps python-test python-integration provider-test system-test
.PHONY: network pull-core build-app build-ui publish-ui
.PHONY: migrate-core
.PHONY: _up-core up-core up-core-server
.PHONY: health test-services
.PHONY: pull-models logs-core
.PHONY: down-core down-rag restart-core
.PHONY: neo4j-fresh

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
	@rm -rf .pytest_cache .ruff_cache .mypy_cache .coverage* .tox .venv dist build

python-lock: ## Resolve and lock all Python runtime dependencies for Python 3.11.
	@command -v uv >/dev/null 2>&1 || { echo "uv is required to regenerate python_rag/requirements.lock.txt"; exit 1; }
	@mkdir -p "$(PYTHON_MINERU_WHEEL_ROOT)/upstream" "$(PYTHON_MINERU_WHEEL_ROOT)/patched"
	@uv run --python 3.11 --with pip python -m pip download --no-deps \
		--dest "$(PYTHON_MINERU_WHEEL_ROOT)/upstream" "mineru==3.4.4"
	@uv run --python 3.11 python python_rag/scripts/build_mineru_transformers5_wheel.py \
		"$(PYTHON_MINERU_WHEEL_ROOT)/upstream/mineru-3.4.4-py3-none-any.whl" \
		"$(PYTHON_MINERU_WHEEL_ROOT)/patched"
	@uv pip compile python_rag/requirements.txt python_rag/requirements-security.txt \
		--python-version 3.11 --universal \
		--find-links "$(PYTHON_MINERU_WHEEL_ROOT)/patched" \
		--output-file python_rag/requirements.lock.txt

python-deps: ## Install locked Python runtime and test dependencies.
	@python3 -m pip install --upgrade pip setuptools wheel
	@mkdir -p "$(PYTHON_MINERU_WHEEL_ROOT)/upstream" "$(PYTHON_MINERU_WHEEL_ROOT)/patched"
	@python3 -m pip download --no-deps --dest "$(PYTHON_MINERU_WHEEL_ROOT)/upstream" "mineru==3.4.4"
	@python3 python_rag/scripts/build_mineru_transformers5_wheel.py \
		"$(PYTHON_MINERU_WHEEL_ROOT)/upstream/mineru-3.4.4-py3-none-any.whl" \
		"$(PYTHON_MINERU_WHEEL_ROOT)/patched"
	@python3 -m pip install --find-links "$(PYTHON_MINERU_WHEEL_ROOT)/patched" \
		-r python_rag/requirements.lock.txt -r python_rag/requirements-test.txt

# ==============================================================================
# Docker foundation and application images
# ==============================================================================

##@ Docker foundation

network: ## Create the external Docker networks when they do not exist.
	@for net in hawki-network hosting_network; do \
		if docker network inspect $$net >/dev/null 2>&1; then \
			echo "$$net already exists; skipping create"; \
		else \
			echo "Creating $$net..."; \
			docker network create $$net; \
		fi; \
	done

pull-core: ## Pull third-party runtime images used directly by the core stack.
	@$(COMPOSE_CMD) pull postgres temporal hawki_rag_neo4j $(OLLAMA_SERVICE)

build-app: ## Build the Laravel HAWKI RAG application image.
	@$(COMPOSE_CMD) build hawki_rag_app

# ==============================================================================
# Frontend assets
# ==============================================================================

##@ Frontend

build-ui: ## Build the Svelte/Vite frontend in an isolated Node container.
	@echo "Building HAWKI RAG UI assets..."
	@mkdir -p "$(UI_BUILD_DIR)"
	@mkdir -p "$(UI_NPM_CACHE_DIR)"
	@if [ ! -x node_modules/.bin/vite ] || [ ! -d node_modules/@sveltejs/vite-plugin-svelte ] || ! $(UI_NODE_RUN) node -e "require('rollup/dist/native.js')" >/dev/null 2>&1; then \
		echo "Node dependencies are missing, incomplete, or built for the wrong platform; reinstalling cleanly..."; \
		rm -rf node_modules; \
		$(UI_NODE_RUN) npm ci; \
	fi
	@$(UI_NODE_RUN) npm run build -- --outDir "$(UI_BUILD_DIR)" --emptyOutDir

publish-ui: build-ui ## Publish freshly built frontend assets into the app container.
	@echo "Publishing HAWKI RAG UI assets to hawki_rag_app..."
	@docker exec hawki_rag_app sh -lc 'mkdir -p /var/www/built_resources && find /var/www/built_resources -mindepth 1 -maxdepth 1 -exec rm -rf {} +'
	@docker cp "$(UI_BUILD_DIR)/." hawki_rag_app:/var/www/built_resources/
	@docker exec hawki_rag_app sh -lc 'chown -R www-data:www-data storage bootstrap/cache /var/www/built_resources'
	@echo "UI assets are ready at http://localhost:8080"

# ==============================================================================
# Database lifecycle
# ==============================================================================

##@ Database

migrate-core: ## Run Laravel migrations with startup retry handling.
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

# ==============================================================================
# Stack startup profiles
# ==============================================================================

##@ Stack startup

_up-core: network
	@echo $(PROFILE_MESSAGE)
	@echo "Launching full stack (COMPOSE_FILE=$(COMPOSE_FILE_LIST), profiles: $(if $(strip $(COMPOSE_PROFILES)),$(COMPOSE_PROFILES),none))..."
	@$(COMPOSE_CMD) up -d --build --remove-orphans
	@$(COMPOSE_CMD) rm -f hawki-rag-shared-storage-init >/dev/null
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true
	@$(MAKE) --no-print-directory migrate-core COMPOSE_FILE_LIST="$(COMPOSE_FILE_LIST)" COMPOSE_PROFILES="$(COMPOSE_PROFILES)" ENV_FILE="$(ENV_FILE)" COMPOSE_BIN="$(COMPOSE_BIN)"

up-core: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
up-core: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core: PROFILE_MESSAGE = $(GPU_MESSAGE) Local override enabled.
up-core: _up-core ## Start the complete local stack and publish the UI.
	@if [ "$(UI_AUTO_BUILD)" = "1" ]; then \
		$(MAKE) --no-print-directory publish-ui UI_BUILD_DIR="$(UI_BUILD_DIR)"; \
	else \
		echo "Skipping UI asset publish (UI_AUTO_BUILD=$(UI_AUTO_BUILD))."; \
	fi

up-core-server: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core-server: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-server: PROFILE_MESSAGE = $(GPU_MESSAGE) Server mode and Temporal workers enabled.
up-core-server: _up-core ## Start the server-oriented stack without local overrides.

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
	check_running hawki_rag_postgres 1; \
	check_running temporal 1; \
	check_running hawki_rag_app 1; \
	check_running hawki_qdrant 1; \
	check_running hawki_rag_neo4j 1; \
	check_running $(OLLAMA_CONTAINER) 1; \
	check_running hawki_rag_bridge 0; \
	check_running hawki_rag_rerank 0; \
	check_running hawki_rag_temporal_workflow_worker 0; \
	check_running hawki_rag_temporal_scraper_worker 0; \
	check_running hawki_rag_temporal_converter_worker 0; \
	check_running hawki_rag_temporal_ingestion_worker 0; \
	echo ""; \
	echo "Service checks"; \
	check_exec "PostgreSQL ping" hawki_rag_postgres 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"' 1; \
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

test-services: ## Run focused Qdrant, Neo4j, bridge, and reranker smoke checks.
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

# ==============================================================================
# Automated tests
# ==============================================================================

##@ Tests

python-test: ## Run deterministic Python contract and API tests.
	@PYTHONPATH=python_rag python -m pytest -c python_rag/pytest.ini -m "not integration"

python-integration: ## Run live storage and Temporal integration tests (unavailable services skip).
	@PYTHONPATH=python_rag python -m pytest -c python_rag/pytest.ini -m "integration and not model" python_rag/tests/integration

provider-test: ## Run live Ollama and LiteLLM compatibility tests (unavailable services skip).
	@PYTHONPATH=python_rag python -m pytest -c python_rag/pytest.ini -m "integration and model" python_rag/tests/integration

system-test: ## Run Laravel authenticated query, isolation, and PDF upload system flows.
	@php artisan test --testsuite=System

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
	@$(COMPOSE_CMD) logs -f postgres temporal qdrant hawki_rag_neo4j $(OLLAMA_SERVICE) hawki_rag_app hawki_rag_bridge hawki_rag_rerank hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker

# ==============================================================================
# Stack shutdown and restart
# ==============================================================================

##@ Stack lifecycle

down-core: ## Stop and remove the active HAWKI RAG Compose stack.
	@$(COMPOSE_CMD) down

down-rag: down-core ## Backward-compatible alias for stopping the RAG stack.

restart-core: ## Recreate all core services and Temporal workers.
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate

# ==============================================================================
# Destructive data maintenance
# ==============================================================================

##@ Maintenance

neo4j-fresh: ## DESTRUCTIVE: recreate Neo4j with an empty persisted store.
	@echo "Stopping Neo4j service..."
	@$(COMPOSE_CMD) stop hawki_rag_neo4j >/dev/null 2>&1 || true
	@$(COMPOSE_CMD) rm -f hawki_rag_neo4j >/dev/null 2>&1 || true
	@echo "Removing persisted Neo4j data (databases, transactions)..."
	@$(COMPOSE_CMD) run --rm --entrypoint bash hawki_rag_neo4j -lc 'rm -rf /data/databases/* /data/transactions/*' >/dev/null
	@echo "Starting Neo4j service..."
	@$(COMPOSE_CMD) up -d hawki_rag_neo4j >/dev/null
	@echo "Neo4j store reset complete."
