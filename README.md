# HAWKI-RAG

HAWKI-RAG indexes documents and web pages for retrieval and optional answer
generation. FastAPI serves REST at `/v1` and MCP at `/mcp` on the private Docker
network. Clients such as HAWKI, Stud.IP, and HISinOne authenticate with registered
service JWTs. Queries and ingestion require the corresponding grant on the
active dataset in the caller's tenant. Dataset creation and authenticated
readiness use the dataset management scope.

Python workers execute ingestion workflows and activities through Temporal. The APIs support
file and inline text submissions, URL ingestion, typed metadata filters, vector
and graph retrieval, and reranking.

- [Install and configure HAWKI-RAG](_documentation/Getting%20Started/4_installation_zero_to_up.md)
- [Run and operate the Python stack](_documentation/Operations/python_runtime.md)
- [Public REST API contracts](_documentation/Reference/rest_apis.md)
- [Authentication and dataset authorization](_documentation/Core%20Concepts/authorization_dataset_scope.md)
- [Development and tests](_documentation/Developer/testing.md)

The root Compose files build local images. The pull-only production model lives
in [_docker_production](_docker_production/). Production requires published
images, a provisioned PostgreSQL schema, a restricted runtime role, and a service JWT
issuer. Live model readiness currently checks Ollama. See
[the operational guide](_documentation/Operations/python_runtime.md#historical-evidence-and-reservations)
for the database records and artifacts to preserve.
