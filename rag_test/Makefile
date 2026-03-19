PYTHON ?= python3

.PHONY: validate prepare build retrieval graph all export

validate:
	$(PYTHON) scripts/validate_fairness.py

prepare:
	$(PYTHON) scripts/prepare_test_data.py

build:
	$(PYTHON) scripts/build_qdrant_collections.py

retrieval:
	$(PYTHON) scripts/run_retrieval_benchmark.py

graph:
	$(PYTHON) scripts/run_graph_benchmark.py

all:
	$(PYTHON) scripts/run_all.py

export:
	$(PYTHON) scripts/export_results.py
