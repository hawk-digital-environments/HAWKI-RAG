"""SDK client contracts with an injected transport, without gateway access."""

import pytest
import httpx
from litellm.types.rerank import RerankResponse

from hawki_reranking.litellm_reranker import LiteLLMReranker


def test_client_reuses_injected_sdk_and_preserves_response():
    calls = []
    response = RerankResponse(results=[{"index": 0, "relevance_score": 0.2}])
    client = LiteLLMReranker(
        "cohere/rerank-v3.5",
        rerank_fn=lambda **kwargs: calls.append(kwargs) or response,
    )
    assert client.rerank("q", ["text"], 1) is response
    assert client.rerank("q", ["text"], 1) is response
    assert len(calls) == 2
    assert calls[0] == {
        "model": "cohere/rerank-v3.5",
        "query": "q",
        "documents": ["text"],
        "top_n": 1,
        "return_documents": False,
    }


def test_client_rejects_invalid_transport_response():
    client = LiteLLMReranker("cohere/model", rerank_fn=lambda **kwargs: {})
    with pytest.raises(TypeError):
        client.rerank("q", ["text"])


import litellm

PRIMARY = "litellm_proxy/jina-rerank"
FALLBACK = "litellm_proxy/jlu/jina-rerank"


@pytest.fixture
def mock_reranker_factory():
    def _create(*outcomes, fallback_model=FALLBACK):
        calls = []
        results = iter(outcomes)

        def sdk(**kwargs):
            calls.append(kwargs)
            outcome = next(results)
            if isinstance(outcome, Exception):
                raise outcome
            return outcome

        return LiteLLMReranker(
            PRIMARY, fallback_model=fallback_model, rerank_fn=sdk
        ), calls

    return _create


def test_primary_success_does_not_call_fallback(mock_reranker_factory):
    response = RerankResponse(results=[])
    client, calls = mock_reranker_factory(response)
    assert client.rerank("q", ["text"], 1) is response
    assert [c["model"] for c in calls] == [PRIMARY]


@pytest.mark.parametrize(
    "error_type",
    [
        litellm.APIConnectionError,
        litellm.Timeout,
        litellm.RateLimitError,
        litellm.InternalServerError,
        litellm.ServiceUnavailableError,
    ],
)
def test_retryable_failure_calls_fallback_once(mock_reranker_factory, error_type):
    error = error_type("synthetic", model=PRIMARY, llm_provider="litellm_proxy")
    response = RerankResponse(results=[{"index": 0, "relevance_score": 0.7}])
    client, calls = mock_reranker_factory(error, response)
    assert client.rerank("q", ["text"], 1) is response
    assert [c["model"] for c in calls] == [PRIMARY, FALLBACK]
    assert {k: v for k, v in calls[0].items() if k != "model"} == {
        k: v for k, v in calls[1].items() if k != "model"
    }


@pytest.mark.parametrize(
    "error_type",
    [
        litellm.AuthenticationError,
        litellm.PermissionDeniedError,
        litellm.BadRequestError,
        litellm.NotFoundError,
    ],
)
def test_nonretryable_sdk_errors_do_not_call_fallback(
    mock_reranker_factory, error_type
):
    kwargs = (
        {
            "response": httpx.Response(
                403, request=httpx.Request("POST", "https://gateway.invalid/rerank")
            )
        }
        if error_type is litellm.PermissionDeniedError
        else {}
    )
    error = error_type(
        "synthetic", model=PRIMARY, llm_provider="litellm_proxy", **kwargs
    )
    client, calls = mock_reranker_factory(error)
    with pytest.raises(error_type):
        client.rerank("q", ["text"], 1)
    assert len(calls) == 1


def test_invalid_response_does_not_trigger_inference_retry(mock_reranker_factory):
    client, calls = mock_reranker_factory({})
    with pytest.raises(TypeError):
        client.rerank("q", ["text"], 1)
    assert len(calls) == 1


def test_both_fail_without_loop_and_disabled_fallback(mock_reranker_factory):
    error = litellm.Timeout("synthetic", model=PRIMARY, llm_provider="litellm_proxy")
    client, calls = mock_reranker_factory(error, error)
    with pytest.raises(litellm.Timeout):
        client.rerank("q", ["text"], 1)
    assert [c["model"] for c in calls] == [PRIMARY, FALLBACK]
    client, calls = mock_reranker_factory(error, fallback_model=None)
    with pytest.raises(litellm.Timeout):
        client.rerank("q", ["text"], 1)
    assert len(calls) == 1


def test_primary_cannot_be_its_own_fallback():
    with pytest.raises(ValueError):
        LiteLLMReranker(PRIMARY, fallback_model=PRIMARY)


def test_explicit_fallback_model_is_forwarded(mock_reranker_factory):
    timeout = litellm.Timeout("synthetic", model=PRIMARY, llm_provider="litellm_proxy")
    response = RerankResponse(results=[])
    client, calls = mock_reranker_factory(
        timeout, response, fallback_model="litellm_proxy/override"
    )
    assert client.rerank("q", ["text"], 1) is response
    assert [call["model"] for call in calls] == [PRIMARY, "litellm_proxy/override"]


def test_configuration_error_does_not_trigger_retry(mock_reranker_factory):
    client, calls = mock_reranker_factory(ValueError("invalid configuration"))
    with pytest.raises(ValueError):
        client.rerank("q", ["text"], 1)
    assert len(calls) == 1
