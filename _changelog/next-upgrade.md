# Upgrade to v%%VERSION%%

## Keep the database and artifact volumes

Retain the Compose project name, PostgreSQL, Qdrant, Neo4j, and shared storage
volumes. Keep historical PostgreSQL tables and archived ownership evidence.
Provision Alembic revision `0009` and the restricted backend runtime login
through the approved schema procedure. Background cleanup remains disabled in
the supplied deployment models pending the historical ownership handoff.

## Connect API clients

The Compose stack keeps FastAPI on the private Docker network. Connect from a
client with access to that network and send a registered service JWT to
`http://rag_backend/v1`. Update removed addon entries in `COMPOSE_FILE`.

## Update the environment

Use the current root or production examples. Transfer the required backend
settings from any old backend-only overlay, keeping database and issuer
credentials in restricted operator-managed files. Gateway credential mounts
are no longer used. Check for shared database and issuer material before
retiring an old credential directory.

The deployment still requires published Python images, a provisioned schema
and roles, configured identity, and a compatible model readiness probe.
See [Python RAG deployment](../_documentation/Operations/python_runtime.md).
