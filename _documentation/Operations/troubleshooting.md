# Troubleshooting

| Symptom | Check |
| --- | --- |
| API unavailable | check backend health and confirm the client is on the backend's Docker network |
| Direct API 401 | JWT signature, issuer/audience/lifetime, registered service client and HTTPS JWKS |
| API 403 | required service scope or caller policy |
| Dataset unavailable | tenant, active dataset and exact ingest/query grant; management scope alone does not imply ingest |
| Upload rejected | canonical dataset preparation, accepted extension, size, multipart file field and idempotency |
| Empty saved crawler catalog | check whether the optional profile UI is available. `crawler-api` can be healthy with an empty catalog |
| URL ingestion 503 | scraper queue, worker reachability, crawler contract and health, shared storage, and callback configuration |
| Empty stage logs | inspect real task-owned signed events; older receipts may lack stage detail |
| Incorrect model summary | safe `config.runtime` metadata; dataset embedding contract and configured query models |

Never disable authentication, contract validation, TLS verification, ownership checks or schema guards to resolve a symptom. Read [monitoring](monitoring.md), [recovery](ingestion_recovery.md) and the [deployment guide](python_runtime.md). Run readiness after configuring the dependencies. Retire disposable acceptance
data through the owning API.
