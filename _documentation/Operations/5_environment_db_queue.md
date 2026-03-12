# 5. Environment, Database, Queue (Step-by-Step)

## Environment (.env) — every key explained

### Application
| Variable | Default Value | Description |
| --- | --- | --- |
| `APP_NAME` | `HAWKI RAG` | Name displayed in the Laravel UI and metadata. |
| `APP_URL` | `http://localhost:8080` | Public URL Laravel uses for generated links; normally replaced by your reverse-proxy domain. |
| `APP_KEY` | _Must be set_ | Laravel encryption/session secret; generate once and keep private. |

### Database services
| Variable | Default Value | Description |
| --- | --- | --- |
| `DB_HOST` | `mariadb` | MariaDB host used by Laravel app container. |
| `DB_PORT` | `3306` | MariaDB port exposed on the internal Docker network. |
| `DB_DATABASE` | _From `.env`_ | MariaDB database name for Laravel tables. |
| `DB_USERNAME` | _From `.env`_ | MariaDB username used by Laravel. |
| `DB_PASSWORD` | _From `.env`_ | MariaDB password used by Laravel. |
| `NEO4J_HTTP_URL` | `http://hawki_rag_neo4j:7474` | Neo4j HTTP endpoint for graph operations. |
| `NEO4J_USER` | _From `.env`_ | Neo4j login user. |
| `NEO4J_PASSWORD` | _From `.env`_ | Neo4j login password. |
| `QDRANT_HTTP_URL` | `http://qdrant:6333` | Qdrant HTTP endpoint for vector search/indexing. |

### HAWKI RAG endpoints and paths
| Variable | Default Value | Description |
| --- | --- | --- |
| `HAWKI_RAG_BRIDGE_URL` | _From `.env`_ | Ingest bridge base URL used for ingest operations. |
| `HAWKI_RAG_API_URL` | _From `.env`_ | Question-answer API URL used by the app. |
| `HAWKI_RAG_SHARED_ROOT` | `/app/shared` | Shared files path inside containers for ingestion input. |

### Ollama and models
| Variable | Default Value | Description |
| --- | --- | --- |
| `OLLAMA_API_URL` | `http://ollama:11434/api` | Ollama API base URL (compose alias like `http://hawki_ollama:11434/api` can also be used). |
| `OLLAMA_EMBED_MODEL` | `bge-m3` | Embedding model used during ingestion. |
| `GRAPH_OLLAMA_RAG_MODEL` | `llama3.2:1b` | Default graph extraction model. |

## Database setup
### Variables to verify before migrating
| Variable | Default Value | Description |
| --- | --- | --- |
| `DB_HOST` | `mariadb` | Must resolve from `hawki_rag_app` container to the MariaDB service. |
| `DB_PORT` | `3306` | Must match the MariaDB service port. |
| `DB_DATABASE` | _From `.env`_ | Target database for Laravel migrations. |
| `DB_USERNAME` | _From `.env`_ | User executing Laravel migrations. |
| `DB_PASSWORD` | _From `.env`_ | Password for migration user. |

!!! tip "Important"
    Run migrations with `php artisan migrate`; without it, Laravel cannot store jobs/sessions used by the UI.
