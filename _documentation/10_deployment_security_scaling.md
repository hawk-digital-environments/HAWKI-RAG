# 10. Deployment, Security, Scaling

## Production deployment (clear steps)
1) Provision a host with Docker (GPU preferred).
2) Set strong secrets in `.env` (APP_KEY, DB, Neo4j credentials).
3) Keep only Nginx port 8080 public; do NOT expose DB, Qdrant, Neo4j.
4) (Optional) Change MariaDB binding to localhost-only or internal network only.
5) Run: `make network && make up-core`.
6) Verify with `make health`.
7) Put HTTPS in front (reverse proxy/ingress) if on the internet.

## Security considerations
- Secrets: never commit `.env`; rotate passwords periodically.
- Surface area: only 8080 exposed; others internal.
- Data paths: ingest is restricted to shared root; avoids directory traversal.
- Updates: rebuild images regularly to get security patches.
- Access control: add auth in Laravel for any user-facing ingest/query endpoints before production use.

## Scaling considerations
- Laravel app: scale horizontally; keep sessions in DB/Redis; load-balance behind Nginx/LB.
- RAG API: run multiple `raganything_api_gpu` instances; place behind internal LB.
- Ollama: one instance per GPU; for multiple GPUs, run multiple Ollama containers and shard requests.
- Qdrant: consider clustering/sharding for large datasets (not configured here).
- Neo4j: single instance here; for large graphs, plan for a managed cluster.
- Queues: default setup uses Laravel queue drivers (`sync`/`database`); add external queue infrastructure if workloads grow.
