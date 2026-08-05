# ==============================================================================
# HAWKI RAG development and operations commands
# ==============================================================================
#
# Start with `make help` to see the public commands grouped by responsibility.
# Variables can be overridden per invocation, for example:
#
#   make up-core USE_OLLAMA_GPU=0 ENV_FILE=.env.production
#   make up-core-local UI_AUTO_BUILD=0
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
UI_OVERRIDE_COMPOSE ?= docker-compose.ui.yml
LOCAL_OVERRIDE_COMPOSE ?= docker-compose.local.yml
COMPOSE_FILE_SEP ?= :
PYTHON_UV_CACHE ?= /tmp/rawki-uv-cache
PYTHON_RERANKER_ENV ?= .venv-reranker
PYTHON_DATA_PLANE_PACKAGES := \
	--package hawki-bridge \
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
CRAWLER_CONTAINER ?= crawl4ai-service
FILE_CONVERTER_CONTAINER ?= hawki-toolkit-file-converter-file-converter-1

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
CORE_UI_COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(CORE_GPU_COMPOSE_SUFFIX)$(COMPOSE_FILE_SEP)$(UI_OVERRIDE_COMPOSE)
CORE_LOCAL_COMPOSE_FILE_LIST := $(CORE_UI_COMPOSE_FILE_LIST)$(COMPOSE_FILE_SEP)$(LOCAL_OVERRIDE_COMPOSE)
COMPOSE_FILE_LIST ?= $(CORE_UI_COMPOSE_FILE_LIST)
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
	HAWKI_RAG_COMPOSE_ENV_FILE="$(ENV_FILE)" \
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
.PHONY: clean python-lock python-deps python-quality python-test python-integration provider-test system-test migration-test
.PHONY: network pull-core build-app build-ui publish-ui
.PHONY: migrate-core _migrate-core-before-start
.PHONY: _up-core up-core up-core-local up-core-server
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

network: ## Create the external Docker networks when they do not exist.
	@set -e; \
	for net in hawki-network hosting_network; do \
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

migrate-core: network ## Run Laravel migrations with startup retry handling.
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

_migrate-core-before-start: network
	@echo "Running Laravel migrations before writable services start..."
	@attempt=1; \
	while [ "$$attempt" -le 30 ]; do \
		if $(COMPOSE_CMD) up --no-deps --force-recreate --abort-on-container-exit --exit-code-from hawki_rag_migrator hawki_rag_migrator; then \
			echo "Laravel migrations are up to date."; \
			exit 0; \
		fi; \
		echo "Migration attempt $$attempt failed; retrying in 2s..."; \
		attempt=$$((attempt + 1)); \
		sleep 2; \
	done; \
	echo "Laravel migrations failed after 30 attempts; writable services remain stopped."; \
	exit 1

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
	@echo "Quiescing application writers before database migration..."
	@$(COMPOSE_CMD) down --remove-orphans
	@$(COMPOSE_CMD) up -d --wait postgres
	@$(MAKE) --no-print-directory _migrate-core-before-start COMPOSE_FILE_LIST="$(COMPOSE_FILE_LIST)" COMPOSE_PROFILES="$(COMPOSE_PROFILES)" ENV_FILE="$(ENV_FILE)" COMPOSE_BIN="$(COMPOSE_BIN)"
	@echo "Launching the migrated stack..."
	@$(COMPOSE_CMD) up -d --remove-orphans
	@echo "Ensuring Ollama models are pulled..."
	@for model in bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@for container in "$(CRAWLER_CONTAINER)" "$(FILE_CONVERTER_CONTAINER)"; do \
		if docker container inspect "$$container" >/dev/null 2>&1; then \
			docker network connect hawki-network "$$container" >/dev/null 2>&1 || true; \
			echo "$$container is connected to hawki-network."; \
		else \
			echo "Optional external service $$container is not running; skipping network connection."; \
		fi; \
	done

up-core: COMPOSE_FILE_LIST = $(CORE_UI_COMPOSE_FILE_LIST)
up-core: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core: PROFILE_MESSAGE = $(GPU_MESSAGE) Runtime mode loaded from $(ENV_FILE), with loopback UI.
up-core: _up-core ## Start the stack at http://localhost:8080 using ENV_FILE.

up-core-local: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
up-core-local: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-local: BUILD_STACK = 0
up-core-local: PROFILE_MESSAGE = $(GPU_MESSAGE) Source-mounted development using $(ENV_FILE).
up-core-local: _up-core ## Reuse images, source-mount the app, and publish the UI.
	@if [ "$(UI_AUTO_BUILD)" = "1" ]; then \
		$(MAKE) --no-print-directory publish-ui UI_BUILD_DIR="$(UI_BUILD_DIR)"; \
	else \
		echo "Skipping UI asset publish (UI_AUTO_BUILD=$(UI_AUTO_BUILD))."; \
	fi

up-core-server: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core-server: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-server: PROFILE_MESSAGE = $(GPU_MESSAGE) Reverse-proxy mode loaded from $(ENV_FILE).
up-core-server: _up-core ## Start without a host UI port, using ENV_FILE.

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
	check_running hawki_rag_indexer_worker 0; \
	echo ""; \
	echo "Service checks"; \
	check_exec "PostgreSQL ping" hawki_rag_postgres 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"' 1; \
	check_exec "Laravel artisan" hawki_rag_app "php artisan list --raw" 1; \
	check_exec "Qdrant readyz" hawki_qdrant "curl -fsS http://localhost:6333/readyz" 1; \
	check_exec "Neo4j browser" hawki_rag_neo4j "wget --spider -q http://localhost:7474/browser" 1; \
	check_exec "Ollama models" $(OLLAMA_CONTAINER) "ollama list" 1; \
	check_exec "Read-only RAG bridge" hawki_rag_bridge "python -c 'import urllib.request; urllib.request.urlopen(\"http://localhost:8000/health?runtime=false\", timeout=5).read()'" 0; \
	check_exec "Local reranker" hawki_rag_rerank "python -c 'import urllib.request; urllib.request.urlopen(\"http://localhost:8000/health\", timeout=5).read()'" 0; \
	check_url "Laravel HTTP" "http://127.0.0.1:8080/up" 0; \
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
		printf "hawki_rag_bridge: "; docker exec hawki_rag_bridge python -c 'import urllib.request; urllib.request.urlopen("http://localhost:8000/health?runtime=false", timeout=5).read()' >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "hawki_rag_bridge: skipped (container not running)\n"; \
	fi; \
	if docker ps --format '{{.Names}}' | grep -q hawki_rag_rerank; then \
		printf "hawki_rag_rerank: "; docker exec hawki_rag_rerank python -c 'import urllib.request; urllib.request.urlopen("http://localhost:8000/health", timeout=5).read()' >/dev/null && echo "healthy" || (echo "WARN" && true); \
	else \
		printf "hawki_rag_rerank: skipped (container not running)\n"; \
	fi; \
	echo "Service checks completed."

# ==============================================================================
# Automated tests
# ==============================================================================

##@ Tests

python-quality: ## Check Python formatting and lint rules with the pinned Ruff version.
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv run --frozen --no-sync ruff format --check packages services tests
	@cd python_rag && UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv run --frozen --no-sync ruff check packages services tests

python-test: python-deps ## Run deterministic Python contract and API tests in the uv workspace.
	@cd python_rag && PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 PYTHONPATH="services/hawki_reranker/src" UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv run --frozen --no-sync pytest -c pytest.ini -m "not integration"

python-integration: python-deps ## Run live storage and Temporal integration tests (unavailable services skip).
	@cd python_rag && PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 PYTHONPATH="services/hawki_reranker/src" UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv run --frozen --no-sync pytest -c pytest.ini -m "integration and not model" tests/integration

provider-test: python-deps ## Run live Ollama and LiteLLM compatibility tests (unavailable services skip).
	@cd python_rag && PYTEST_DISABLE_PLUGIN_AUTOLOAD=1 PYTHONPATH="services/hawki_reranker/src" UV_CACHE_DIR="$(PYTHON_UV_CACHE)" uv run --frozen --no-sync pytest -c pytest.ini -m "integration and model" tests/integration

system-test: ## Run Laravel authenticated query, isolation, and PDF upload system flows.
	@php artisan test --testsuite=System

migration-test: ## Run isolated PostgreSQL migration-upgrade scenarios in the active stack.
	@$(COMPOSE_CMD) exec -T hawki_rag_app sh -lc '\
		RUN_POSTGRES_MIGRATION_TESTS=1 \
		MIGRATION_TEST_ALLOW_SHARED_DATABASE=1 \
		MIGRATION_TEST_DB_HOST="$${DB_HOST:-postgres}" \
		MIGRATION_TEST_DB_PORT="$${DB_PORT:-5432}" \
		MIGRATION_TEST_DB_DATABASE="$${DB_DATABASE:-hawki_rag}" \
		MIGRATION_TEST_DB_USERNAME="$${DB_USERNAME:-rag_user}" \
		MIGRATION_TEST_DB_PASSWORD="$${DB_PASSWORD:-}" \
		php artisan test tests/Feature/Database/PostgresMigrationUpgradeTest.php'

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
	@$(COMPOSE_CMD) logs -f postgres temporal qdrant hawki_rag_neo4j $(OLLAMA_SERVICE) hawki_rag_app hawki_rag_bridge hawki_rag_rerank hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-indexer-worker

# ==============================================================================
# Stack shutdown and restart
# ==============================================================================

##@ Stack lifecycle

down-core: ## Stop and remove the active HAWKI RAG Compose stack.
	@$(COMPOSE_CMD) down

down-rag: down-core ## Backward-compatible alias for stopping the RAG stack.

restart-core: network ## Recreate all core services and Temporal workers.
	@echo "$(PROFILE_MESSAGE)"
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
