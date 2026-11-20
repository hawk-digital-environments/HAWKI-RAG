# Shared reranking clients

Local CrossEncoder and LiteLLM SDK clients return native LiteLLM RerankResponse.
No bridge or HTTP-service dependency. Local inference dependencies are optional.

The bridge depends on this package, not on the standalone hawki-reranker HTTP
service. The bridge's local-reranker extra forwards to this package's local extra;
the root lock isolates it from the standalone service's incompatible model stack.

From `python_rag`, run tests: `uv run --frozen --no-sync pytest packages/reranking/tests`.

The SDK client accepts an optional fallback_model supplied by its caller. It tries
that model once only for typed retryable SDK errors, using the same SDK transport.
It has no environment or gateway-specific defaults; production composition owns
the narrowly scoped IxDLab default. Benchmark measurements remain single-model.

Production dependency composition reuses the clients through the bridge's existing
`RerankHitsPort`. Candidate hit dictionaries, indexed score mapping, blending,
and fallback to original hits belong to the bridge, not this package. The external
IxDLab gateway owns hosted routing; a LiteLLM SDK client does not require a local
LiteLLM Proxy container. See [production configuration](../../services/hawki_bridge/README.md#production-reranking-backends).
