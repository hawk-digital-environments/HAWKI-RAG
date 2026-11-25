# HAWKI-RAG

HAWKI-RAG is a headless Python RAG engine. Applications such as HAWKI, Stud.IP,
and HISinOne call the FastAPI service with their own registered service JWTs.
Dataset grants and tenant isolation apply to queries and mutations.

FastAPI serves `/v1/*` on the private Docker network. Temporal workers use
signed internal callbacks. HAWKI-RAG supports typed metadata filters, managed file and URL ingestion,
direct text ingestion, vector and graph retrieval, and reranking.

- [Install and configure HAWKI-RAG](_documentation/Getting%20Started/4_installation_zero_to_up.md)
- [Run and operate the Python stack](_documentation/Operations/python_runtime.md)
- [Public REST API contracts](_documentation/Reference/rest_apis.md)
- [Authentication and dataset authorization](_documentation/Core%20Concepts/authorization_dataset_scope.md)
- [Development and tests](_documentation/Developer/testing.md)

The root Compose model builds local Python images. The pull-only production
model is in [_docker_production](_docker_production/). Production needs
published Python images, a provisioned schema and restricted role, and a
service issuer. The current live model readiness probe requires Ollama.
Historical database records and shared artifact reservations are covered in
[the operational guide](_documentation/Operations/python_runtime.md#historical-evidence-and-reservations).
