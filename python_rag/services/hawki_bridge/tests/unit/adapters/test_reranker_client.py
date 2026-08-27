"""Reranker boundary scenarios for request documents, score formats, and backward compatibility."""

from __future__ import annotations

import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch


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

    def post(self, url: str, **kwargs: object) -> _FakeResponse:
        self.posts.append({"url": url, **kwargs})
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

        with (
            patch.dict(
                os.environ,
                {
                    "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                    "RERANKER_API_KEY": "",
                },
                clear=False,
            ),
            patch(
                "hawki_bridge.adapters.reranker_client._requests_module",
                return_value=fake_requests,
            ),
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

        with (
            patch.dict(
                os.environ,
                {
                    "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                    "RERANKER_API_KEY": "",
                },
                clear=False,
            ),
            patch(
                "hawki_bridge.adapters.reranker_client._requests_module",
                return_value=fake_requests,
            ),
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

        with (
            patch.dict(
                os.environ,
                {
                    "RERANKER_API_URL": "http://reranker.test/v1/rerank",
                    "RERANKER_API_KEY": "",
                },
                clear=False,
            ),
            patch(
                "hawki_bridge.adapters.reranker_client._requests_module",
                return_value=fake_requests,
            ),
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
            )

        self.assertEqual([hit["id"] for hit in ranked], ["answer", "introduction"])
        self.assertEqual(ranked[0]["score"], 1.0)
        self.assertEqual(ranked[1]["score"], 0.0)


if __name__ == "__main__":
    unittest.main()
