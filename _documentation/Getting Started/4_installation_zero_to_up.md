# Installation

1. Configure the shared infrastructure from `.env.example` and backend options from `python_backend/.env.example`. The backend template at `docker/env/epoch15c.cutover.env.example` contains placeholders, not usable credentials.
2. Provision PostgreSQL, a dedicated restricted runtime role, and Alembic revision 0009 using an operator migration DSN. The runtime role must not have DDL or historical public-schema authority.
3. Configure HTTPS issuer/JWKS verification, audience, registered service clients, scopes, tenant grants and server-side gateway credentials. Configure the default HAWKI management approval endpoint or select the explicit loopback local profile.
4. Configure model providers, embedding contract, reranker, Qdrant/Neo4j, shared storage and a compliant crawler. Preserve certificate verification and signed callbacks.
5. Build/start with the commands in the [deployment guide](../Operations/python_runtime.md). Require 26/26 live readiness before accepting traffic.
6. Run disposable ingestion/retrieval checks from the [testing guide](../Developer/testing.md), followed by cleanup audits. Keep credentials out of command output and browser assets.
