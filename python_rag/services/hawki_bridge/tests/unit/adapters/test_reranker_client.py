"""Reranker boundary scenarios for request documents, score formats, and backward compatibility."""

from __future__ import annotations

import os
import unittest
from copy import deepcopy
from types import SimpleNamespace
from unittest.mock import patch

import pytest
from litellm.types.rerank import RerankResponse

from hawki_bridge.adapters.reranker_client import rerank_hits


class _FakeResponse:
    ok = True

    def __init__(self, payload: dict[str, object]) -> None:
        self._payload = payload

    def json(self) -> dict[str, object]:
        return self._payload


class _FakeRequests:
    def __init__(self, response_payload: dict[str, object]) -> None:
        self.response_payload = response_payload
        self.posts: list[dict[str, object]] = []

    def post(
        self,
        url: str,
        *,
        headers: dict[str, str],
        json: dict[str, object],
        timeout: float,
    ) -> _FakeResponse:
        self.posts.append(
            {
                "url": url,
                "headers": headers,
                "json": json,
                "timeout": timeout,
            }
        )
        return _FakeResponse(self.response_payload)


def _hits() -> list[dict[str, object]]:
    return [
        {
            "id": "first",
            "score": 0.8,
            "payload": {
                "source_url": "upload://first",
                "content": "The first document describes page one.",
            },
        },
        {
            "id": "second",
            "score": 0.7,
            "payload": {
                "source_url": "upload://second",
                "content": "ATLAS-KAPPA-1010 appears on page ten.",
            },
        },
    ]


class ExternalRerankerContractTests(unittest.TestCase):
    """Verify external reranking preserves document identity while normalizing provider scores."""

    def test_external_reranker_uses_string_documents_and_cohere_scores(self) -> None:
        from hawki_bridge.adapters.reranker_client import rerank_hits

        fake_requests = _FakeRequests(
            {
                "results": [
                    {"index": 1, "relevance_score": 0.95},
                    {"index": 0, "relevance_score": 0.1},
                ]
            }
        )

        with patch.dict(
            os.environ,
            {
                "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                "RERANKER_API_KEY": "",
            },
            clear=False,
        ):
            ranked = rerank_hits(
                hits=_hits(),
                user_query="Which keyword appears on page ten?",
                provider=SimpleNamespace(),
                query_vector=None,
                mode="external",
                top_n=2,
                mix_mode=False,
                mix_weight=0.5,
                http_client=fake_requests,
            )

        self.assertEqual([hit["id"] for hit in ranked], ["second", "first"])
        self.assertEqual(
            fake_requests.posts[0]["json"],
            {
                "query": "Which keyword appears on page ten?",
                "documents": [
                    "The first document describes page one.",
                    "ATLAS-KAPPA-1010 appears on page ten.",
                ],
            },
        )

    def test_external_reranker_retains_legacy_id_score_response_support(self) -> None:
        from hawki_bridge.adapters.reranker_client import rerank_hits

        fake_requests = _FakeRequests(
            {
                "results": [
                    {"id": "upload://second", "score": 0.9},
                    {"id": "upload://first", "score": 0.2},
                ]
            }
        )

        with patch.dict(
            os.environ,
            {
                "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                "RERANKER_API_KEY": "",
            },
            clear=False,
        ):
            ranked = rerank_hits(
                hits=_hits(),
                user_query="Which keyword appears on page ten?",
                provider=SimpleNamespace(),
                query_vector=None,
                mode="external",
                top_n=2,
                mix_mode=False,
                mix_weight=0.5,
                http_client=fake_requests,
            )

        self.assertEqual([hit["id"] for hit in ranked], ["second", "first"])

    def test_mixed_reranker_exposes_comparable_final_scores(self) -> None:
        from hawki_bridge.adapters.reranker_client import rerank_hits

        fake_requests = _FakeRequests(
            {
                "results": [
                    {"index": 0, "relevance_score": 0.95},
                    {"index": 1, "relevance_score": 0.1},
                ]
            }
        )
        hits = [
            {
                "id": "answer",
                "score": 1.0,
                "payload": {"content": "3.3 für die dritte Mahnung 10"},
            },
            {
                "id": "introduction",
                "score": 0.4,
                "payload": {"content": "Gebührenordnung und Inhaltsverzeichnis"},
            },
        ]

        with patch.dict(
            os.environ,
            {
                "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                "RERANKER_API_KEY": "",
            },
            clear=False,
        ):
            ranked = rerank_hits(
                hits=hits,
                user_query="Was ist die dritte Mahnung?",
                provider=SimpleNamespace(),
                query_vector=None,
                mode="external",
                top_n=2,
                mix_mode=True,
                mix_weight=0.5,
                http_client=fake_requests,
            )

        self.assertEqual([hit["id"] for hit in ranked], ["answer", "introduction"])
        self.assertEqual(ranked[0]["score"], 1.0)
        self.assertEqual(ranked[1]["score"], 0.0)


# These exercise the production hit wrapper, not just the document clients.
class MockReranker:
    def __init__(self, response):
        self.response = response
        self.calls = []

    def rerank(self, query, documents, top_n=None):
        self.calls.append((query, documents, top_n))
        if isinstance(self.response, Exception):
            raise self.response
        return self.response


@pytest.fixture
def candidate_hits():
    return [
        {"id": "a", "score": 0.9, "payload": {"content": "same"}},
        {"id": "b", "score": 0.1, "payload": {"content": "same"}},
        {"id": "tail", "score": 7.0, "payload": {"content": "tail"}},
    ]


def rank_with_client(hits, client, **options):
    return rerank_hits(
        hits=hits,
        user_query="question",
        provider=None,
        query_vector=None,
        **(
            {"mode": "external", "top_n": 2, "mix_mode": False, "mix_weight": 0.5}
            | options
        ),
        reranker=client,
    )


@pytest.mark.parametrize("weight,expected", [(0.0, ["b", "a"]), (1.0, ["a", "b"])])
def test_index_mapping_blending_and_untouched_tail(candidate_hits, weight, expected):
    original = deepcopy(candidate_hits)
    client = MockReranker(
        RerankResponse(
            results=[
                {"index": 1, "relevance_score": 4.0},
                {"index": 0, "relevance_score": -2.0},
            ]
        )
    )
    ranked = rank_with_client(candidate_hits, client, mix_mode=True, mix_weight=weight)
    assert [hit["id"] for hit in ranked] == expected + ["tail"]
    assert [hit["score"] for hit in ranked] == [1.0, 0.0, 7.0]
    assert ranked[2] is candidate_hits[2]
    for hit in ranked:
        assert hit["payload"] is next(
            h["payload"] for h in candidate_hits if h["id"] == hit["id"]
        )
    assert candidate_hits == original
    assert client.calls == [("question", ["same", "same"], 2)]


@pytest.mark.parametrize("count", [0, 1, 2])
def test_empty_singleton_and_stable_ties(candidate_hits, count):
    hits = candidate_hits[:count]
    client = MockReranker(
        RerankResponse(
            results=[
                {"index": index, "relevance_score": 0.3}
                for index in reversed(range(count))
            ]
        )
    )
    ranked = rank_with_client(hits, client)
    assert [h["id"] for h in ranked] == [h["id"] for h in hits]
    assert [h["score"] for h in ranked] == ([1.0] if count == 1 else [0.5] * count)
    assert len(client.calls) == bool(count)


@pytest.mark.parametrize(
    "results",
    [
        None,
        [],
        [{"index": 0, "relevance_score": 1.0}],
        [{"index": 0, "relevance_score": 1.0}] * 2,
        [{"index": -1, "relevance_score": 1.0}, {"index": 1, "relevance_score": 0.0}],
        [{"index": 2, "relevance_score": 1.0}, {"index": 1, "relevance_score": 0.0}],
        [{"index": True, "relevance_score": 1.0}, {"index": 1, "relevance_score": 0.0}],
        [{"index": 0.0, "relevance_score": 1.0}, {"index": 1, "relevance_score": 0.0}],
        [
            {"index": 0, "relevance_score": float("nan")},
            {"index": 1, "relevance_score": 0.0},
        ],
        [
            {"index": 0, "relevance_score": float("inf")},
            {"index": 1, "relevance_score": 0.0},
        ],
        [{"index": 0, "relevance_score": True}, {"index": 1, "relevance_score": 0.0}],
        [{"index": 0, "relevance_score": "1"}, {"index": 1, "relevance_score": 0.0}],
        [{"index": 0}, {"index": 1, "relevance_score": 0.0}],
    ],
)
def test_malformed_responses_fall_back_atomically(candidate_hits, results):
    # Bypass SDK construction validation to test our boundary against malformed data.
    response = RerankResponse(results=[])
    response.results = results
    original = deepcopy(candidate_hits)
    assert rank_with_client(candidate_hits, MockReranker(response)) is candidate_hits
    assert candidate_hits == original


@pytest.mark.parametrize(
    ("response", "error_type"),
    [
        (object(), "ValueError"),
        (RuntimeError("private text and credentials"), "RuntimeError"),
    ],
)
def test_client_failure_logs_type_but_not_details(
    candidate_hits, response, error_type, caplog
):
    assert rank_with_client(candidate_hits, MockReranker(response)) is candidate_hits
    assert "private text" not in caplog.text
    assert "credentials" not in caplog.text
    assert f"Reranker failed ({error_type})" in caplog.text
    assert "continuing with original order" in caplog.text


@pytest.mark.parametrize("mode", ["none", "cosine", "jina"])
def test_selected_client_does_not_replace_legacy_modes(
    candidate_hits, mode, monkeypatch
):
    client = MockReranker(RuntimeError("must not be called"))
    http_calls = []

    def post(url, **kwargs):
        http_calls.append(url)
        return SimpleNamespace(
            ok=True,
            json=lambda: {
                "results": [
                    {"index": 1, "relevance_score": 0.9},
                    {"index": 0, "relevance_score": 0.1},
                ]
            },
        )

    monkeypatch.setenv("JINA_API_KEY", "test-placeholder")
    ranked = rerank_hits(
        hits=candidate_hits,
        user_query="question",
        provider=SimpleNamespace(embed=lambda text: [1.0, 0.0]),
        query_vector=[1.0, 0.0],
        mode=mode,
        top_n=2,
        mix_mode=False,
        mix_weight=0.5,
        reranker=client,
        http_client=SimpleNamespace(post=post),
    )
    assert client.calls == []
    if mode == "none":
        assert ranked is candidate_hits
    elif mode == "cosine":
        assert [h["score"] for h in ranked] == [0.5, 0.5, 7.0]
    else:
        assert [h["id"] for h in ranked] == ["b", "a", "tail"]
        assert http_calls == ["https://api.jina.ai/v1/rerank"]


if __name__ == "__main__":
    unittest.main()
