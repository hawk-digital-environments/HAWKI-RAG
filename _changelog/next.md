# v%%VERSION%%

### What's New

[//]: # (- The main new features and changes in this version.)

### Quality of Life

[//]: # (- Improvements and enhancements that improve the user experience.)

### Bugfix

[//]: # (- Bug fixes included in this version.)

### Internals

[//]: # (- Changes that are mostly relevant to maintainers and contributors, such as refactors, dependency updates, CI changes, etc.)

- The bridge `GET /config` endpoint was removed, and the Python services no longer
read model-selection environment variables (`RAG_DEFAULT_PROVIDER`,
`OLLAMA_*_MODEL`, `LITELLM_*_MODEL`, `GRAPH_OLLAMA_*` on the Python side).
Provider and model names now travel exclusively with each Laravel request:

    - ingestion payloads must include `ingestion.graph_model` and `ingestion.vision_model`
    - `/query` requests must include `provider`, `chat_model`, and `vision_model`

Any client that called the bridge `/query` endpoint directly (outside Laravel)
must send these fields explicitly; missing fields now fail validation instead of
falling back to environment defaults. The provider startup probe was removed
along with `RAG_DEFAULT_PROVIDER`; startup checks cover Qdrant and Neo4j only.
Endpoint/auth variables (`OLLAMA_API_URL`, `LITELLM_API_URL/KEY`, Qdrant/Neo4j
addresses) remain unchanged.

### Deprecation

[//]: # (- List of features or functionalities that have been deprecated in this version.)
