"""Remote reranking through the LiteLLM Python SDK."""

from collections.abc import Callable

import litellm
from litellm.types.rerank import RerankResponse


class LiteLLMReranker:
    """Rerank text documents using a LiteLLM-supported remote provider.

    Create this adapter once for a model, then reuse it across requests.
    API keys are resolved by LiteLLM from the provider's environment settings.
    """

    def __init__(
        self,
        model: str,
        *,
        rerank_fn: Callable[..., RerankResponse] | None = None,
        fallback_model: str | None = None,
    ) -> None:
        if not model.strip():
            raise ValueError("model must be non-empty")
        if fallback_model is not None and (
            not fallback_model.strip() or fallback_model == model
        ):
            raise ValueError(
                "fallback_model must be non-empty and different from primary"
            )
        self.model = model
        self.fallback_model = fallback_model
        # Allow injecting a fake SDK call in unit tests.
        self._rerank_fn = rerank_fn if rerank_fn is not None else litellm.rerank

    def rerank(
        self,
        query: str,
        documents: list[str],
        top_n: int | None = None,
    ) -> RerankResponse:
        """Return the provider's relevance scores and original document indices.

        ``top_n`` limits the number of returned rankings (LiteLLM semantics).
        The bridge must separately decide how many hits to *send* for reranking.
        """
        if top_n is not None and top_n <= 0:
            raise ValueError("top_n must be positive when supplied")
        if not documents:
            return RerankResponse(results=[])

        arguments = dict(
            query=query, documents=documents, top_n=top_n, return_documents=False
        )
        try:
            response = self._rerank_fn(model=self.model, **arguments)
        except (
            litellm.APIConnectionError,
            litellm.Timeout,
            litellm.RateLimitError,
            litellm.InternalServerError,
            litellm.ServiceUnavailableError,
        ):
            if self.fallback_model is None:
                raise
            # One direct attempt outside the primary try: never recurse or chain.
            # The injected transport retains gateway, key, timeout and retry options.
            # SDK exceptions may contain secrets, so do not log their contents.
            response = self._rerank_fn(model=self.fallback_model, **arguments)
        if not isinstance(response, RerankResponse):
            raise TypeError("LiteLLM rerank must return RerankResponse")
        return response
