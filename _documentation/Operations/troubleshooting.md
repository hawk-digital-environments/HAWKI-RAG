# Troubleshooting

| Symptom | Check |
| --- | --- |
| API unavailable | check backend health and confirm the client is on the backend's Docker network |
| Direct API 401 | JWT signature, issuer/audience/lifetime, registered service client and HTTPS JWKS |
| API 403 | required scope, UI Origin/browser marker, HAWKI management approval |
| Dataset unavailable | tenant, active dataset and exact ingest/query grant; management scope alone does not imply ingest |
| Upload rejected | canonical dataset preparation, accepted extension, size, multipart file field and idempotency |
| Empty saved crawler catalog | optional profile UI is absent; genuine permanent-job API may still be healthy |
| URL ingestion 503 | full crawler contract/health, shared storage, Temporal and callback configuration |
| Empty stage logs | inspect real task-owned signed events; older receipts may lack stage detail |
| Incorrect model summary | safe `config.runtime` metadata; dataset embedding contract and configured query models |

Never disable authentication, contract validation, TLS verification, ownership checks or schema guards to resolve a symptom. Read [monitoring](monitoring.md), [recovery](ingestion_recovery.md) and the [deployment guide](python_runtime.md). Run readiness only after dependencies are configured and any disposable acceptance data is retired.
