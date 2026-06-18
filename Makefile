# Simple Makefile to streamline the HAWKI RAG pipeline

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose
COMPOSE_PARALLEL_LIMIT ?= 1

# Core stack variables (override via `make VAR=value`)
ENV_FILE ?= .env
HOST_OS := $(shell uname -s)

BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
LOCAL_OVERRIDE_COMPOSE ?= docker-compose.local.yml
COMPOSE_FILE_SEP ?= :
COMMA := ,
# USE_OLLAMA_GPU: auto (default), 1 (force GPU override), 0 (force CPU mode)
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
COMPOSE_CMD = $(COMPOSE_ENV_PREFIX) COMPOSE_PARALLEL_LIMIT=$(COMPOSE_PARALLEL_LIMIT) COMPOSE_FILE=$(COMPOSE_FILE_LIST) $(if $(strip $(COMPOSE_PROFILES)),COMPOSE_PROFILES=$(COMPOSE_PROFILES)) $(COMPOSE_BIN) --env-file $(ENV_FILE)

# UI asset variables
UI_AUTO_BUILD ?= 1
UI_BUILD_DIR ?= /tmp/rawki-vite-build

# Bruno collection test variables (override via `make VAR=value`)
BRUNO_BIN ?= bru
BRUNO_ENV ?= local
BRUNO_BASE_URL ?= http://localhost:8080
BRUNO_REPORT_DIR ?= $(CURDIR)/bruno/reports
BRUNO_RUN_FLAGS ?= --bail
BRUNO_REPORT_FLAGS ?= --reporter-skip-all-headers --reporter-skip-body
BRUNO_API_DIR ?= bruno/rag-api
BRUNO_WEB_DIR ?= bruno/rag-web
BRUNO_DATASET_ID ?= rawki-demo
BRUNO_UPLOAD_FILE ?= fixtures/turbo-paper.pdf
BRUNO_GRAPH ?= true
BRUNO_API_ARGS = --env $(BRUNO_ENV) --env-var baseUrl=$(BRUNO_BASE_URL)
BRUNO_PIPELINE_ARGS = --env-var datasetId=$(BRUNO_DATASET_ID) --env-var file=$(BRUNO_UPLOAD_FILE) --env-var graph=$(BRUNO_GRAPH)
export RAWKI_API_TOKEN
export BRUNO_ALLOW_DESTRUCTIVE

.PHONY: clean network pull-core build-app build-ui publish-ui migrate-core _up-core up-core up-core-ui up-core-server health pull-models logs-core logs-core-ui down-core down-rag restart-core restart-core-ui test-services test-bruno test-bruno-full test-bruno-smoke test-bruno-api test-bruno-pipeline test-bruno-scraper test-bruno-converter test-bruno-everything test-bruno-destructive bruno-reports _bruno-require-token _bruno-require-destructive neo4j-fresh python-test python-deps
.NOTPARALLEL: test-bruno test-bruno-full test-bruno-everything

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

build-ui:
	@echo "Building HAWKI RAG UI assets..."
	@if [ ! -x node_modules/.bin/vite ]; then \
		echo "Node dependencies are missing; running npm install..."; \
		npm install; \
	fi
	@npm run build -- --outDir "$(UI_BUILD_DIR)" --emptyOutDir

publish-ui: build-ui
	@echo "Publishing HAWKI RAG UI assets to hawki_rag_app..."
	@docker cp "$(UI_BUILD_DIR)/." hawki_rag_app:/var/www/built_resources/
	@docker exec hawki_rag_app sh -lc 'chown -R www-data:www-data storage bootstrap/cache /var/www/built_resources'
	@echo "UI assets are ready at http://localhost:8080"

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
	@for model in bge-m3 llama3.1:8b llama3.2:1b qwen2.5vl:7b; do \
		echo "Pulling $$model..."; \
		docker exec $(OLLAMA_CONTAINER) ollama pull $$model >/dev/null 2>&1 || true; \
	done
	@docker network connect hawki-network hawki-toolkit-file-converter-file-converter-1 >/dev/null 2>&1 || true
	@$(MAKE) --no-print-directory migrate-core COMPOSE_FILE_LIST="$(COMPOSE_FILE_LIST)" COMPOSE_PROFILES="$(COMPOSE_PROFILES)" ENV_FILE="$(ENV_FILE)" COMPOSE_BIN="$(COMPOSE_BIN)"

up-core: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
up-core: COMPOSE_PROFILES = $(if $(strip $(CORE_PROFILES)),devtools$(COMMA)$(CORE_PROFILES),devtools)
up-core: PROFILE_MESSAGE = $(GPU_MESSAGE) Full local experience enabled with Temporal UI/devtools.
up-core: _up-core
	@if [ "$(UI_AUTO_BUILD)" = "1" ]; then \
		$(MAKE) --no-print-directory publish-ui UI_BUILD_DIR="$(UI_BUILD_DIR)"; \
	else \
		echo "Skipping UI asset publish (UI_AUTO_BUILD=$(UI_AUTO_BUILD))."; \
	fi

up-core-ui: up-core

up-core-server: COMPOSE_FILE_LIST = $(CORE_SERVER_COMPOSE_FILE_LIST)
up-core-server: COMPOSE_PROFILES = $(CORE_PROFILES)
up-core-server: PROFILE_MESSAGE = $(GPU_MESSAGE) Server mode and Temporal workers enabled.
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
	check_running hawki_rag_postgres 1; \
	check_running temporal 1; \
	check_running temporal_ui 0; \
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

bruno-reports:
	@mkdir -p "$(BRUNO_REPORT_DIR)"

_bruno-require-token:
	@if [ -z "$${RAWKI_API_TOKEN:-}" ]; then \
		echo "RAWKI_API_TOKEN is required for Sanctum-protected Bruno API tests."; \
		echo "Create a local token with: docker exec -it hawki_rag_app php artisan user:token"; \
		echo "Then run: export RAWKI_API_TOKEN=your-local-token"; \
		exit 1; \
	fi

_bruno-require-destructive:
	@if [ "$(BRUNO_ALLOW_DESTRUCTIVE)" != "1" ]; then \
		echo "Refusing destructive Bruno tests."; \
		echo "Re-run with BRUNO_ALLOW_DESTRUCTIVE=1 if you intend to clear local graph data."; \
		exit 1; \
	fi

test-bruno: test-bruno-full

test-bruno-full: test-bruno-smoke test-bruno-api test-bruno-pipeline test-bruno-converter
	@echo "Bruno full non-destructive suite completed. Reports: $(BRUNO_REPORT_DIR)"

test-bruno-smoke: _bruno-require-token bruno-reports
	@echo "Running Bruno smoke tests against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_API_DIR) && $(BRUNO_BIN) run requests -r \
		$(BRUNO_API_ARGS) \
		--env-var token="$${RAWKI_API_TOKEN}" \
		--tags smoke \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-smoke-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-smoke.json" \
		$(BRUNO_REPORT_FLAGS)

test-bruno-api: _bruno-require-token bruno-reports
	@echo "Running Bruno API tests against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_API_DIR) && $(BRUNO_BIN) run \
		requests/auth \
		requests/health \
		-r \
		$(BRUNO_API_ARGS) \
		--env-var token="$${RAWKI_API_TOKEN}" \
		--tags smoke \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-api-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-api.json" \
		$(BRUNO_REPORT_FLAGS)

test-bruno-pipeline: _bruno-require-token bruno-reports
	@echo "Running Bruno pipeline tests against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_API_DIR) && $(BRUNO_BIN) run \
		requests/pipeline-health/017-pipeline-health.yml \
		requests/pipeline-tasks/007-list-pipeline-tasks.yml \
		requests/pipeline-recovery/019-list-failed-jobs.yml \
		$(BRUNO_API_ARGS) \
		--env-var token="$${RAWKI_API_TOKEN}" \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-pipeline-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-pipeline.json" \
		$(BRUNO_REPORT_FLAGS)

test-bruno-scraper: bruno-reports
	@echo "Running Bruno scraper tests against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_WEB_DIR) && $(BRUNO_BIN) run requests/web-scrape -r \
		$(BRUNO_API_ARGS) \
		--exclude-tags destructive \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-scraper-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-scraper.json" \
		$(BRUNO_REPORT_FLAGS)

test-bruno-converter: _bruno-require-token bruno-reports
	@echo "Running Bruno converter-facing pipeline checks against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_API_DIR) && $(BRUNO_BIN) run \
		requests/pipeline-health/017-pipeline-health.yml \
		requests/pipeline-upload/018-upload-pipeline-file.yml \
		$(BRUNO_API_ARGS) \
		--env-var token="$${RAWKI_API_TOKEN}" \
		$(BRUNO_PIPELINE_ARGS) \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-converter-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-converter.json" \
		$(BRUNO_REPORT_FLAGS)

test-bruno-everything: test-bruno-full test-bruno-destructive
	@echo "Bruno full suite including destructive tests completed. Reports: $(BRUNO_REPORT_DIR)"

test-bruno-destructive: _bruno-require-token _bruno-require-destructive bruno-reports
	@echo "Running destructive Bruno tests against $(BRUNO_BASE_URL)..."
	@cd $(BRUNO_API_DIR) && $(BRUNO_BIN) run requests/rag-graph/039-clear-neo4j-graph.yml \
		$(BRUNO_API_ARGS) \
		--env-var token="$${RAWKI_API_TOKEN}" \
		$(BRUNO_RUN_FLAGS) \
		--reporter-junit "$(BRUNO_REPORT_DIR)/bruno-destructive-junit.xml" \
		--reporter-json "$(BRUNO_REPORT_DIR)/bruno-destructive.json" \
		$(BRUNO_REPORT_FLAGS)

python-test:
	@PYTHONPATH=python_rag python -m unittest discover -s python_rag/tests -p 'test_*.py'

pull-models:
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull bge-m3
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.1:8b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull llama3.2:1b
	@docker exec -it $(OLLAMA_CONTAINER) ollama pull qwen2.5vl:7b

logs-core:
	@$(COMPOSE_CMD) logs -f postgres temporal qdrant hawki_rag_neo4j $(OLLAMA_SERVICE) hawki_rag_app hawki_rag_bridge hawki_rag_rerank hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker
	@$(COMPOSE_CMD) logs -f

logs-core-ui: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
logs-core-ui: COMPOSE_PROFILES = $(if $(strip $(CORE_PROFILES)),devtools$(COMMA)$(CORE_PROFILES),devtools)
logs-core-ui:
	@$(COMPOSE_CMD) logs -f temporal-ui

down-core:
	@$(COMPOSE_CMD) down

down-rag:
	@$(COMPOSE_CMD) down

restart-core:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate postgres temporal qdrant hawki_rag_neo4j $(OLLAMA_SERVICE) hawki_rag_app hawki_rag_bridge hawki_rag_rerank hawki-rag-temporal-workflow-worker hawki-rag-temporal-scraper-worker hawki-rag-temporal-converter-worker hawki-rag-temporal-ingestion-worker
	@$(COMPOSE_CMD) up -d --force-recreate

restart-core-ui: COMPOSE_FILE_LIST = $(CORE_LOCAL_COMPOSE_FILE_LIST)
restart-core-ui: COMPOSE_PROFILES = $(if $(strip $(CORE_PROFILES)),devtools$(COMMA)$(CORE_PROFILES),devtools)
restart-core-ui: PROFILE_MESSAGE = $(GPU_MESSAGE) Local override enabled and Temporal UI enabled for dev diagnostics.
restart-core-ui:
	@echo $(PROFILE_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate temporal-ui

neo4j-fresh:
	@echo "Stopping Neo4j service..."
	@$(COMPOSE_CMD) stop neo4j >/dev/null 2>&1 || true
	@$(COMPOSE_CMD) rm -f neo4j >/dev/null 2>&1 || true
	@echo "Removing persisted Neo4j data (databases, transactions)..."
	@$(COMPOSE_CMD) run --rm --entrypoint bash neo4j -lc 'rm -rf /data/databases/* /data/transactions/*' >/dev/null
	@echo "Starting Neo4j service..."
	@$(COMPOSE_CMD) up -d neo4j >/dev/null
	@echo "Neo4j store reset complete."
