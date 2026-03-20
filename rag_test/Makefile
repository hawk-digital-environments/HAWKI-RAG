PYTHON ?= python3
DOCKER_NETWORK ?= rawki_default
QWEN_NOMIC_INTERNAL ?= 0
QDRANT_IP := $(shell docker network inspect $(DOCKER_NETWORK) --format '{{range .Containers}}{{if eq .Name "hawki_qdrant"}}{{.IPv4Address}}{{end}}{{end}}' 2>/dev/null | cut -d/ -f1)
OLLAMA_IP := $(shell docker network inspect $(DOCKER_NETWORK) --format '{{range .Containers}}{{if eq .Name "hawki_ollama"}}{{.IPv4Address}}{{end}}{{end}}' 2>/dev/null | cut -d/ -f1)
NEO4J_IP := $(shell docker network inspect $(DOCKER_NETWORK) --format '{{range .Containers}}{{if eq .Name "hawki_rag_neo4j"}}{{.IPv4Address}}{{end}}{{end}}' 2>/dev/null | cut -d/ -f1)
BUILD_ENV :=
ifneq ($(strip $(QDRANT_IP)),)
BUILD_ENV += RAG_TEST_QDRANT_URL=http://$(QDRANT_IP):6333
endif
ifneq ($(strip $(OLLAMA_IP)),)
BUILD_ENV += RAG_TEST_EMBEDDING_API_BASE=http://$(OLLAMA_IP):11434 RAG_TEST_BGE_M3_API_BASE=http://$(OLLAMA_IP):11434 OLLAMA_EMBED_TIMEOUT=300
ifeq ($(QWEN_NOMIC_INTERNAL),1)
BUILD_ENV += RAG_TEST_QWEN3_EMBEDDING_API_BASE=http://$(OLLAMA_IP):11434 RAG_TEST_NOMIC_EMBED_TEXT_API_BASE=http://$(OLLAMA_IP):11434
endif
endif
ifneq ($(strip $(NEO4J_IP)),)
BUILD_ENV += RAG_TEST_NEO4J_URI=bolt://$(NEO4J_IP):7687
endif

.PHONY: validate prepare build retrieval graph all export

validate:
	$(PYTHON) scripts/validate_fairness.py

prepare:
	$(PYTHON) scripts/prepare_test_data.py

build:
	$(BUILD_ENV) $(PYTHON) scripts/build_qdrant_collections.py

retrieval:
	$(PYTHON) scripts/run_retrieval_benchmark.py

graph:
	$(PYTHON) scripts/run_graph_benchmark.py

all:
	$(PYTHON) scripts/run_all.py

export:
	$(PYTHON) scripts/export_results.py
