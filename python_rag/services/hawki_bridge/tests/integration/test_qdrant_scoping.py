"""Live Qdrant scenarios for collection locking and mandatory dataset filters.

The test creates disposable collections containing deliberately mixed dataset
payloads.  It proves that the application policy overwrites hostile scope
filters and that a scoped client never searches a fallback collection.
"""

from __future__ import annotations

import time
from typing import Any
from urllib.parse import urlsplit
from uuid import uuid4

import pytest


pytestmark = pytest.mark.integration


def _http_settings() -> Any:
    from hawki_vector_store.settings import QdrantHTTPSettings

    return QdrantHTTPSettings(
        log_latency=False,
        search_all=True,
        search_all_per_collection=10,
        fallback_all=True,
        fallback_per_collection=10,
        upsert_timeout=5.0,
        search_timeout=5.0,
        count_timeout=5.0,
        delete_timeout=5.0,
        text_timeout=5.0,
        text_fallback_terms=3,
        text_scroll_hard_cap=100,
        text_scroll_batch=20,
    )


def _client(live_qdrant: Any, collection: str) -> Any:
    from hawki_vector_store.client import QdrantHTTP
    from hawki_vector_store.settings import QdrantSettings

    parsed = urlsplit(live_qdrant.base_url)
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    settings = QdrantSettings(
        scheme=parsed.scheme,
        host=parsed.hostname or "127.0.0.1",
        port=port,
        collection=collection,
        api_key=live_qdrant.api_key,
        timeout=5.0,
        max_attempts=1,
        retry_attempts_by_operation={},
    )
    return QdrantHTTP(settings=settings, http_settings=_http_settings())


def _create_collection(live_qdrant: Any, collection: str) -> None:
    response = live_qdrant.session.put(
        f"{live_qdrant.base_url}/collections/{collection}",
        json={"vectors": {"size": 3, "distance": "Cosine"}},
        timeout=5.0,
    )
    response.raise_for_status()


def _upsert_and_wait(
    live_qdrant: Any, collection: str, points: list[dict[str, Any]]
) -> None:
    response = live_qdrant.session.put(
        f"{live_qdrant.base_url}/collections/{collection}/points",
        params={"wait": "true"},
        json={"points": points},
        timeout=5.0,
    )
    response.raise_for_status()

    # Older Qdrant releases may acknowledge before the count endpoint reflects
    # the write even with wait=true. Keep the bounded poll local to this test.
    deadline = time.monotonic() + 5.0
    while time.monotonic() < deadline:
        count_response = live_qdrant.session.post(
            f"{live_qdrant.base_url}/collections/{collection}/points/count",
            json={"exact": True},
            timeout=5.0,
        )
        count_response.raise_for_status()
        count = (count_response.json().get("result") or {}).get("count")
        if count == len(points):
            return
        time.sleep(0.05)
    pytest.fail(f"Qdrant did not make {len(points)} test points visible in time")


@pytest.fixture
def qdrant_scope_resources(live_qdrant: Any) -> dict[str, str]:
    """Create unique identifiers and always delete their disposable collections."""

    token = uuid4().hex
    resources = {
        "collection_a": f"rawki_it_scope_a_{token}",
        "collection_b": f"rawki_it_scope_b_{token}",
        "missing_collection": f"rawki_it_scope_missing_{token}",
        "dataset_a": f"rawki-it-dataset-a-{token}",
        "dataset_b": f"rawki-it-dataset-b-{token}",
        "topic": f"rawki-it-topic-{token}",
    }
    try:
        yield resources
    finally:
        for collection in (resources["collection_a"], resources["collection_b"]):
            try:
                live_qdrant.session.delete(
                    f"{live_qdrant.base_url}/collections/{collection}",
                    timeout=5.0,
                )
            except Exception:
                # Cleanup is best-effort only after the endpoint itself becomes
                # unavailable; names are unique and cannot overlap real data.
                pass


class TestLiveQdrantScoping:
    """Prove dataset authorization survives a round trip through real Qdrant."""

    def test_scoped_search_combines_sanitized_user_filter_with_dataset_scope(
        self,
        live_qdrant: Any,
        qdrant_scope_resources: dict[str, str],
    ) -> None:
        from hawki_bridge.application.query.scope import build_scoped_query_filters

        resource = qdrant_scope_resources
        _create_collection(live_qdrant, resource["collection_a"])
        _create_collection(live_qdrant, resource["collection_b"])

        _upsert_and_wait(
            live_qdrant,
            resource["collection_a"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_a"],
                        "topic": resource["topic"],
                        "marker": "authorized-in-selected-collection",
                    },
                },
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "topic": resource["topic"],
                        "marker": "wrong-dataset-in-selected-collection",
                    },
                },
            ],
        )
        _upsert_and_wait(
            live_qdrant,
            resource["collection_b"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "topic": resource["topic"],
                        "marker": "wrong-physical-collection",
                    },
                }
            ],
        )

        # The hostile dataset/storage keys are removed and the trusted dataset
        # value is written last by the same policy used by /query.
        filters = build_scoped_query_filters(
            resource["dataset_a"],
            {
                "dataset_id": resource["dataset_b"],
                "qdrantCollection": resource["collection_b"],
                "topic": resource["topic"],
            },
        )
        assert filters == {
            "topic": resource["topic"],
            "dataset_id": resource["dataset_a"],
        }

        qdrant = _client(live_qdrant, resource["collection_b"])
        qdrant.select_scoped_collection(resource["collection_a"])
        hits = qdrant.search([1.0, 0.0, 0.0], top_k=10, filters=filters)

        markers = {(hit.get("payload") or {}).get("marker") for hit in hits}
        assert markers == {"authorized-in-selected-collection"}

    def test_missing_scoped_collection_never_falls_back_globally(
        self,
        live_qdrant: Any,
        qdrant_scope_resources: dict[str, str],
    ) -> None:
        from hawki_vector_store.client import ScopedCollectionNotReadyError

        resource = qdrant_scope_resources
        _create_collection(live_qdrant, resource["collection_b"])
        _upsert_and_wait(
            live_qdrant,
            resource["collection_b"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "marker": "must-never-be-used-as-fallback",
                    },
                }
            ],
        )

        qdrant = _client(live_qdrant, resource["collection_b"])
        qdrant.select_scoped_collection(resource["missing_collection"])

        with pytest.raises(
            ScopedCollectionNotReadyError,
            match="Authorized dataset storage is not ready",
        ):
            qdrant.search(
                [1.0, 0.0, 0.0],
                top_k=10,
                filters={"dataset_id": resource["dataset_a"]},
            )
