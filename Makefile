# Minimal Docker Compose wrapper for HAWKI RAG

SHELL := /bin/bash

COMPOSE_BIN ?= docker compose
ENV_FILE ?= .env
HOST_OS := $(shell uname -s)

BASE_COMPOSE_FILE ?= docker-compose.yml
GPU_OVERRIDE_COMPOSE ?= docker-compose-gpu-override.yml
COMPOSE_FILE_SEP ?= :

# USE_OLLAMA_GPU: auto (default), 1 (force GPU override), 0 (force CPU mode)
USE_OLLAMA_GPU ?= auto
OLLAMA_CONTAINER ?= hawki_ollama

ifeq ($(USE_OLLAMA_GPU),auto)
	ifeq ($(HOST_OS),Linux)
		USE_OLLAMA_GPU := $(shell if command -v nvidia-smi >/dev/null 2>&1; then echo 1; else echo 0; fi)
	else
		USE_OLLAMA_GPU := 0
	endif
endif

COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)
COMPOSE_PROFILES :=
GPU_MESSAGE := Ollama CPU mode.

ifeq ($(USE_OLLAMA_GPU),1)
	COMPOSE_FILE_LIST := $(BASE_COMPOSE_FILE)$(COMPOSE_FILE_SEP)$(GPU_OVERRIDE_COMPOSE)
	COMPOSE_PROFILES := gpu
	GPU_MESSAGE := Ollama GPU override enabled.
endif

COMPOSE_ENV_PREFIX :=
ifeq ($(HOST_OS),Darwin)
	# Avoid inheriting a forced amd64 platform on Apple Silicon hosts.
	COMPOSE_ENV_PREFIX := DOCKER_DEFAULT_PLATFORM=
endif

COMPOSE_CMD = $(COMPOSE_ENV_PREFIX) COMPOSE_FILE=$(COMPOSE_FILE_LIST) $(if $(strip $(COMPOSE_PROFILES)),COMPOSE_PROFILES=$(COMPOSE_PROFILES)) $(COMPOSE_BIN) --env-file $(ENV_FILE)

.PHONY: up down restart health

up:
	@echo $(GPU_MESSAGE)
	@$(COMPOSE_CMD) up -d --build --remove-orphans

down:
	@$(COMPOSE_CMD) down

restart:
	@echo $(GPU_MESSAGE)
	@$(COMPOSE_CMD) up -d --force-recreate

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
	echo $(GPU_MESSAGE); \
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
