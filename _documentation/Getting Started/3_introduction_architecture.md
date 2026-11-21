# Introduction & Architecture

Browser → static Svelte/nginx → `/ui-api/v1/*` → server-side Python UI gateway → FastAPI `/v1/*`. HAWKI owns human authentication; the gateway uses its own service credential. Direct API callers need a registered service JWT.

FastAPI owns dataset metadata, exact tenant/service grants and operational state in PostgreSQL `hawki_backend`. Temporal schedules Python workflow and activity workers. Workers use signed internal callbacks, generation fences and writer barriers. The public nginx boundary denies internal callback routes.

Ingestion accepts managed uploads, direct text and URLs/sitemaps. Conversion produces immutable Markdown artifacts; the indexer owns vector/graph writes. Qdrant stores vectors and Neo4j stores graph contributions. Physical storage targets and model contracts come from the authorized dataset, not browser overrides.

Queries authenticate and authorize before retrieval and context generation. Fast retrieval is the default; deep retrieval includes available graph facts. Model providers and the reranker are deployment configuration.

The [deployment guide](../Operations/python_runtime.md) covers service trust, storage preservation and readiness. See [repository map](../Reference/8_repo_map.md), [retrieval](../Core%20Concepts/query_retrieval.md) and [authorization](../Core%20Concepts/authorization_dataset_scope.md).
