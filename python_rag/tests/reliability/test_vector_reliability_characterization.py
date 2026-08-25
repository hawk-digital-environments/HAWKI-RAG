"""Vector-store reliability scenarios for payloads, HTTP boundaries, parsing, and merged search results."""

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    requests_http_error_type as _requests_http_error_type,
)


class QdrantReliabilityCharacterizationTests(unittest.TestCase):
    """Protect Qdrant filtering, timeout policy, fault-tolerant parsing, and result normalization."""

    def test_qdrant_payload_helpers_build_expected_filters_and_batches(self) -> None:
        from hawki_rag_stores.qdrant.payloads import (
            build_delete_filter,
            build_search_body,
            build_text_filter,
            iter_batches,
        )

        self.assertEqual(
            build_delete_filter("doc-1"),
            {"must": [{"key": "doc_id", "match": {"value": "doc-1"}}]},
        )
        self.assertEqual(list(iter_batches([1, 2, 3, 4, 5], 2)), [[1, 2], [3, 4], [5]])
        self.assertEqual(
            build_text_filter(
                ["wooden", "", "blocks"],
                ["content", "title"],
                max_terms=2,
                require_all=True,
            ),
            {
                "must": [
                    {
                        "should": [
                            {"key": "content", "match": {"text": "wooden"}},
                            {"key": "title", "match": {"text": "wooden"}},
                        ]
                    },
                    {
                        "should": [
                            {"key": "content", "match": {"text": "blocks"}},
                            {"key": "title", "match": {"text": "blocks"}},
                        ]
                    },
                ]
            },
        )
        self.assertEqual(
            build_search_body(
                [0.1, 0.2],
                top_k=3,
                filters={"source_format": "markdown"},
                with_payload=True,
                with_vector=False,
                keyword_terms=["toys"],
                keyword_fields=["content"],
                payload_projection=["title"],
            ),
            {
                "vector": [0.1, 0.2],
                "limit": 3,
                "with_payload": {"include": ["title"]},
                "with_vector": False,
                "filter": {
                    "must": [{"key": "source_format", "match": {"value": "markdown"}}],
                    "should": [{"key": "content", "match": {"value": "toys"}}],
                },
            },
        )

    def test_qdrant_settings_parse_env_and_fall_back_on_invalid_numbers(self) -> None:
        from hawki_rag_stores.qdrant.settings import qdrant_settings_from_env

        with patch.dict(
            os.environ,
            {
                "QDRANT_SCHEME": "https",
                "QDRANT_HOST": "qdrant.local",
                "QDRANT_PORT": "bad",
                "QDRANT_COLLECTION": " toy_docs ",
                "QDRANT_API_KEY": "secret",
                "QDRANT_TIMEOUT": "bad",
                "QDRANT_RETRY_ATTEMPTS": "5",
            },
            clear=False,
        ):
            settings = qdrant_settings_from_env()

        self.assertEqual(settings.base_url, "https://qdrant.local:6333")
        self.assertEqual(settings.collection, "toy_docs")
        self.assertEqual(settings.api_key, "secret")
        self.assertEqual(settings.timeout, 30.0)
        self.assertEqual(settings.max_attempts, 5)

    def test_qdrant_http_settings_parse_and_defaults(self) -> None:
        from hawki_rag_stores.qdrant.settings import qdrant_http_settings_from_env

        with patch.dict(
            os.environ,
            {
                "QDRANT_LOG_LATENCY": "yes",
                "QDRANT_SEARCH_ALL": "1",
                "QDRANT_FALLBACK_ALL": "0",
                "QDRANT_UPSERT_TIMEOUT": "bad",
                "QDRANT_SEARCH_TIMEOUT": "bad",
                "QDRANT_TEXT_FALLBACK_TERMS": "7",
                "QDRANT_TEXT_SCROLL_HARD_CAP": "bad",
            },
            clear=False,
        ):
            settings = qdrant_http_settings_from_env(base_timeout=12.5)

        self.assertTrue(settings.log_latency)
        self.assertTrue(settings.search_all)
        self.assertFalse(settings.fallback_all)
        self.assertEqual(settings.search_all_per_collection, 0)
        self.assertEqual(settings.fallback_per_collection, 0)
        self.assertEqual(settings.upsert_timeout, 12.5)
        self.assertEqual(settings.search_timeout, 12.5)
        self.assertEqual(settings.text_fallback_terms, 7)
        self.assertEqual(settings.text_scroll_hard_cap, 50000)

    def test_qdrant_http_uses_injected_http_settings_for_timeouts(self) -> None:
        from hawki_rag_stores.qdrant.client import QdrantHTTP
        from hawki_rag_stores.qdrant.settings import QdrantHTTPSettings, QdrantSettings

        requests: list[tuple[str, str, dict[str, object]]] = []

        class FakeResponse:
            status_code = 200

            def raise_for_status(self) -> None:
                return None

            def json(self):
                return {"result": [{"id": "sample", "score": 0.9}]}

        class FakeSession:
            def request(self, method: str, url: str, **kwargs: object) -> FakeResponse:
                requests.append((method, url, dict(kwargs)))
                return FakeResponse()

        with patch(
            "hawki_rag_stores.qdrant.client.requests.Session",
            return_value=FakeSession(),
        ):
            client = QdrantHTTP(
                settings=QdrantSettings(
                    scheme="http",
                    host="qdrant-host",
                    port=6333,
                    collection="toy_collection",
                    api_key="",
                    timeout=3.0,
                    max_attempts=1,
                ),
                http_settings=QdrantHTTPSettings(
                    log_latency=False,
                    search_all=False,
                    search_all_per_collection=0,
                    fallback_all=True,
                    fallback_per_collection=0,
                    upsert_timeout=9.0,
                    search_timeout=8.0,
                    count_timeout=7.0,
                    delete_timeout=6.0,
                    text_timeout=5.0,
                    text_fallback_terms=2,
                    text_scroll_hard_cap=500,
                    text_scroll_batch=32,
                ),
            )

            self.assertEqual(
                client.upsert([{"id": "1", "vector": [1.0], "payload": {}}]), None
            )

        self.assertEqual(len(requests), 1)
        method, url, kwargs = requests[0]
        self.assertEqual(method, "PUT")
        self.assertEqual(
            url, "http://qdrant-host:6333/collections/toy_collection/points"
        )
        self.assertEqual(kwargs["timeout"], 9.0)

    def test_qdrant_http_delegates_requests_to_primitive_gateway(self) -> None:
        from hawki_rag_stores.qdrant.client import QdrantHTTP
        from hawki_rag_stores.qdrant.settings import QdrantHTTPSettings, QdrantSettings

        calls: list[tuple[str, object]] = []

        class FakeResponse:
            status_code = 200

            def __init__(self, payload: object) -> None:
                self._payload = payload
                self.text = ""

            def raise_for_status(self) -> None:
                return None

            def json(self):
                return self._payload

        class FakeGateway:
            collection = "toy_collection"

            def get_collection(self):
                calls.append(("get_collection", self))
                return FakeResponse({"result": {"status": "ok"}})

            def list_collections(self):
                calls.append(("list_collections", self))
                return FakeResponse({"result": {"collections": []}})

            def search(self, collection, body, timeout):
                calls.append(("search", collection, timeout))
                return FakeResponse({"result": [{"id": "1", "score": 0.4}]})

            def upsert(self, points, timeout):
                calls.append(("upsert", len(points), timeout))
                return FakeResponse({})

            def count_points(self, collection, exact, timeout):
                calls.append(("count", collection, exact, timeout))
                return FakeResponse({"result": {"count": 42}})

            def delete_by_filter(self, filter_body, timeout):
                calls.append(("delete_by_filter", timeout))
                return FakeResponse({})

            def scroll(self, collection, body, timeout):
                calls.append(("scroll", collection, timeout))
                return FakeResponse(
                    {"result": {"points": [], "next_page_offset": None}}
                )

            def ensure_collection(self, **kwargs):
                calls.append(("ensure_collection", kwargs))
                return FakeResponse({})

        fake_gateway = FakeGateway()

        with patch(
            "hawki_rag_stores.qdrant.client.QdrantHTTPGateway",
            return_value=fake_gateway,
        ):
            client = QdrantHTTP(
                settings=QdrantSettings(
                    scheme="http",
                    host="qdrant-host",
                    port=6333,
                    collection="toy_collection",
                    api_key=None,
                    timeout=1.0,
                    max_attempts=1,
                ),
                http_settings=QdrantHTTPSettings(
                    log_latency=False,
                    search_all=False,
                    search_all_per_collection=0,
                    fallback_all=False,
                    fallback_per_collection=0,
                    upsert_timeout=2.0,
                    search_timeout=2.0,
                    count_timeout=2.0,
                    delete_timeout=2.0,
                    text_timeout=2.0,
                    text_fallback_terms=3,
                    text_scroll_hard_cap=500,
                    text_scroll_batch=32,
                ),
            )

            self.assertEqual(
                client.search([0.1, 0.2], top_k=2), [{"id": "1", "score": 0.4}]
            )
            client.upsert([{"id": "a", "vector": [1, 2], "payload": {}}])
            self.assertEqual(client.count_points(), 42)
            client.delete_by_filter({"must": []})
            client.set_collection("runtime_collection")

        self.assertIn(("search", "toy_collection", 2.0), calls)
        self.assertIn(("upsert", 1, 2.0), calls)
        self.assertIn(("count", "toy_collection", True, 2.0), calls)
        self.assertIn(("delete_by_filter", 2.0), calls)
        self.assertEqual(fake_gateway.collection, "runtime_collection")

    def test_qdrant_http_defaults_search_all_limit_to_top_k_when_not_configured(
        self,
    ) -> None:
        from hawki_rag_stores.qdrant.client import QdrantHTTP
        from hawki_rag_stores.qdrant.settings import QdrantHTTPSettings, QdrantSettings

        limits: list[int] = []

        class FakeSession:
            def request(self, *args: object, **kwargs: object):
                raise AssertionError("network must not be used in this test")

        client = QdrantHTTP(
            settings=QdrantSettings(
                scheme="http",
                host="qdrant-host",
                port=6333,
                collection="toy_collection",
                api_key=None,
                timeout=2.0,
                max_attempts=1,
            ),
            http_settings=QdrantHTTPSettings(
                log_latency=False,
                search_all=True,
                search_all_per_collection=0,
                fallback_all=False,
                fallback_per_collection=0,
                upsert_timeout=2.0,
                search_timeout=2.0,
                count_timeout=2.0,
                delete_timeout=2.0,
                text_timeout=2.0,
                text_fallback_terms=1,
                text_scroll_hard_cap=100,
                text_scroll_batch=16,
            ),
        )

        def fake_search_collection(
            collection: str, body: dict[str, object], timeout: float
        ) -> list[dict[str, object]]:
            limits.append(int(body["limit"]))
            return []

        with (
            patch(
                "hawki_rag_stores.qdrant.client.requests.Session",
                return_value=FakeSession(),
            ),
            patch.object(
                client,
                "list_collections",
                return_value=["a", "b"],
            ),
            patch.object(
                client,
                "_search_collection",
                side_effect=fake_search_collection,
            ),
        ):
            client.search([0.1], top_k=7, with_vector=False, with_payload=True)

        self.assertEqual(limits, [7, 7])

    def test_qdrant_collection_helpers_parse_names_counts_and_vector_size(self) -> None:
        from hawki_rag_stores.qdrant.collections import (
            collection_names,
            pick_most_populated_collection,
            vector_size_from_config,
        )

        self.assertEqual(
            collection_names(
                {"result": {"collections": [{"name": "a"}, {"name": "b"}, {}]}}
            ),
            ["a", "b"],
        )
        self.assertEqual(
            pick_most_populated_collection(
                [("empty", 0), ("missing", None), ("full", 12)]
            ),
            "full",
        )
        self.assertEqual(
            vector_size_from_config({"config": {"params": {"vectors": {"size": 384}}}}),
            384,
        )
        self.assertEqual(
            vector_size_from_config(
                {"config": {"params": {"vectors": {"params": {"text": {"size": 768}}}}}}
            ),
            768,
        )

    def test_qdrant_response_parsers_are_fault_tolerant(self) -> None:
        from hawki_rag_stores.qdrant.responses import (
            parse_collection_config,
            parse_collection_names,
            parse_count,
            parse_scroll_points,
            parse_search_result,
        )

        self.assertEqual(
            parse_collection_names({"result": {"collections": [{"name": "a"}, {}]}}),
            ["a"],
        )
        self.assertEqual(
            parse_search_result({"result": [{"id": "x", "score": 0.9}]}),
            [{"id": "x", "score": 0.9}],
        )
        self.assertEqual(parse_search_result({"other": []}), [])
        self.assertEqual(parse_count({"result": {"count": "9"}}), 9)
        self.assertIsNone(parse_count({"result": {"count": None}}))
        self.assertIsNone(parse_count({"result": {}}))
        points, next_offset = parse_scroll_points(
            {"result": {"points": [{"id": "a"}], "next_page_offset": "abc"}}
        )
        self.assertEqual(points, [{"id": "a"}])
        self.assertEqual(next_offset, "abc")
        self.assertEqual(
            parse_collection_config({"result": {"config": 1}}), {"config": 1}
        )

    def test_qdrant_interpretation_helpers_handle_404_and_missing_scores(self) -> None:
        from hawki_rag_stores.qdrant.interpretation import (
            attach_collection,
            parse_scroll_payload,
            parse_search_payload,
            sort_hits_by_score,
        )

        HTTPError = _requests_http_error_type()

        class FakeResponse:
            def __init__(
                self, status_code: int, payload: dict[str, object] | None = None
            ) -> None:
                self.status_code = status_code
                self._payload = payload or {}

            def json(self) -> dict[str, object]:
                return self._payload

            def raise_for_status(self) -> None:
                if self.status_code >= 400:
                    raise HTTPError(f"status={self.status_code}")

        no_rows = FakeResponse(404, {"result": []})
        self.assertEqual(parse_search_payload(no_rows, empty_on_not_found=True), [])
        self.assertEqual(
            parse_scroll_payload(no_rows, empty_on_not_found=True), ([], None)
        )

        with self.assertRaises(HTTPError):
            parse_search_payload(no_rows, empty_on_not_found=False)

        hits = [
            {"id": "1", "score": 0.12},
            {"id": "2", "score": 0.87},
            {"id": "3"},
            {"id": "4", "score": 0.5},
        ]
        self.assertEqual(
            sort_hits_by_score(hits)[:2],
            [{"id": "2", "score": 0.87}, {"id": "4", "score": 0.5}],
        )
        self.assertEqual(
            sort_hits_by_score(hits, limit=2),
            [{"id": "2", "score": 0.87}, {"id": "4", "score": 0.5}],
        )

        with_collection = attach_collection(
            [{"id": "x"}, {"id": "y", "collection": "custom"}], "default"
        )
        self.assertEqual(with_collection[0]["collection"], "default")
        self.assertEqual(with_collection[1]["collection"], "custom")

        missing_payload = FakeResponse(200, {})
        points, next_offset = parse_scroll_payload(
            missing_payload, empty_on_not_found=True
        )
        self.assertEqual(points, [])
        self.assertIsNone(next_offset)

    def test_qdrant_search_helpers_normalize_and_merge_results(self) -> None:
        from hawki_rag_stores.qdrant.search import (
            merge_search_results,
            normalize_query_inputs,
            search_with_fallback_collections,
        )

        self.assertEqual(
            normalize_query_inputs(["", "toy", None, "graph"], ["", "title"]),
            (["toy", "graph"], ["title"]),
        )

        merged = merge_search_results(
            [
                ("a", [{"id": "1", "score": 0.2}, {"id": "2", "score": 0.7}]),
                ("b", [{"id": "3", "score": 0.9}]),
            ],
            top_k=2,
        )
        self.assertEqual(
            merged,
            [
                {"id": "3", "score": 0.9, "collection": "b"},
                {"id": "2", "score": 0.7, "collection": "a"},
            ],
        )

        calls: list[tuple[str, dict, float]] = []

        def execute(collection: str, body: dict, timeout: float):
            calls.append((collection, dict(body), timeout))
            return [{"id": f"{collection}-1", "score": 0.1}]

        merged_all = search_with_fallback_collections(
            ["alpha", "beta"],
            {"vector": [0.1], "limit": 9},
            timeout=8.0,
            top_k=2,
            per_collection_limit=3,
            execute=execute,
        )
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][0], "alpha")
        self.assertEqual(calls[0][1]["limit"], 3)
        self.assertEqual(merged_all[0]["id"], "alpha-1")
        self.assertEqual(merged_all[0]["collection"], "alpha")


if __name__ == "__main__":
    unittest.main()
