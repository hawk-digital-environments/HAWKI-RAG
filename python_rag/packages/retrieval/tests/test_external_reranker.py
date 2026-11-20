"""The runtime call and readiness share the effective external wire settings."""

from unittest.mock import Mock

from hawki_rag_retrieval.adapters.reranker_client import rerank_hits


def test_runtime_uses_the_shared_endpoint_credential_and_model(monkeypatch):
    monkeypatch.setenv("RERANKER_API_URL", "http://runtime.test/v1/rerank")
    monkeypatch.setenv("RERANKER_API_KEY", "runtime-key")
    monkeypatch.setenv("RERANKER_MODEL", "runtime-model")
    monkeypatch.setenv("RERANKER_API_DIALECT", "hawki-cohere-v1")
    http = Mock()
    http.post.return_value.ok = True
    http.post.return_value.status_code = 200
    http.post.return_value.json.return_value = {
        "results": [{"index": 0, "relevance_score": 0.9}]
    }
    hits = [{"score": 0.5, "payload": {"snippet": "fixture"}}]
    result = rerank_hits(
        hits=hits,
        user_query="question",
        provider=None,
        query_vector=None,
        mode="external",
        top_n=1,
        mix_mode=False,
        mix_weight=0.5,
        http_client=http,
    )
    http.post.assert_called_once_with(
        "http://runtime.test/v1/rerank",
        headers={
            "Content-Type": "application/json",
            "Authorization": "Bearer runtime-key",
        },
        json={"query": "question", "documents": ["fixture"], "model": "runtime-model"},
        timeout=30,
        allow_redirects=False,
    )
    assert result[0]["score"] == 1.0


def test_runtime_rejects_redirect_without_following_or_parsing(monkeypatch):
    monkeypatch.setenv("RERANKER_API_URL", "http://canonical/v1/rerank")
    http = Mock()
    http.post.return_value.status_code = 302
    http.post.return_value.ok = True
    hits = [{"score": 0.5, "payload": {"snippet": "fixture"}}]
    result = rerank_hits(
        hits=hits,
        user_query="question",
        provider=None,
        query_vector=None,
        mode="external",
        top_n=1,
        mix_mode=False,
        mix_weight=0.5,
        http_client=http,
    )
    assert result is hits
    assert http.post.call_args.kwargs["allow_redirects"] is False
    http.post.return_value.json.assert_not_called()
