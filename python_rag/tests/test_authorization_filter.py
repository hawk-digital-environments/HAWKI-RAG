from __future__ import annotations

import json
import sys
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


def test_authorization_filter_keeps_only_permission_graph_allowed_documents(monkeypatch) -> None:
    from application.workflows.authorization_filter import AuthorizationContext, filter_authorized_hits

    monkeypatch.setenv("AUTHZ_ENABLED", "true")
    monkeypatch.setattr(
        "application.workflows.authorization_filter.batch_check_documents",
        lambda context, doc_ids: {"doc-allowed": True, "doc-denied": False},
    )

    hits = [
        {"payload": {"doc_id": "doc-allowed", "content": "allowed"}},
        {"payload": {"doc_id": "doc-denied", "content": "denied"}},
    ]

    filtered = filter_authorized_hits(hits, {"provider": "local", "user_id": "u1"})

    assert [hit["payload"]["doc_id"] for hit in filtered] == ["doc-allowed"]


def test_authorization_filter_returns_original_hits_when_disabled(monkeypatch) -> None:
    from application.workflows.authorization_filter import filter_authorized_hits

    monkeypatch.setenv("AUTHZ_ENABLED", "false")
    monkeypatch.setattr(
        "application.workflows.authorization_filter.batch_check_documents",
        lambda context, doc_ids: (_ for _ in ()).throw(AssertionError("permission graph should not be called")),
    )
    hits = [{"payload": {"doc_id": "doc-1", "content": "visible while disabled"}}]

    assert filter_authorized_hits(hits, None) is hits


def test_authorization_filter_denies_all_hits_when_enabled_without_auth_context(monkeypatch) -> None:
    from application.workflows.authorization_filter import filter_authorized_hits

    monkeypatch.setenv("AUTHZ_ENABLED", "true")
    monkeypatch.setattr(
        "application.workflows.authorization_filter.batch_check_documents",
        lambda context, doc_ids: (_ for _ in ()).throw(AssertionError("permission graph should not be called")),
    )

    assert filter_authorized_hits([{"payload": {"doc_id": "doc-1"}}], None) == []


def test_batch_check_documents_sends_spicedb_checkbulk_payload_and_maps_results(monkeypatch) -> None:
    from application.workflows.authorization_filter import AuthorizationContext, batch_check_documents

    monkeypatch.setenv("AUTHZ_GRAPH_BACKEND", "spicedb")
    monkeypatch.setenv("SPICEDB_API_URL", "http://spicedb.test")
    monkeypatch.setenv("SPICEDB_PRESHARED_KEY", "secret-token")
    monkeypatch.setenv("SPICEDB_CONSISTENCY", "fully_consistent")
    captured: dict[str, object] = {}

    class Response:
        def __enter__(self) -> "Response":
            return self

        def __exit__(self, exc_type, exc, tb) -> None:
            return None

        def read(self) -> bytes:
            return json.dumps(
                {
                    "pairs": [
                        {"item": {"permissionship": "PERMISSIONSHIP_HAS_PERMISSION"}},
                        {"item": {"permissionship": "PERMISSIONSHIP_NO_PERMISSION"}},
                    ]
                }
            ).encode("utf-8")

    def fake_urlopen(request, timeout):
        captured["url"] = request.full_url
        captured["timeout"] = timeout
        captured["headers"] = dict(request.header_items())
        captured["payload"] = json.loads(request.data.decode("utf-8"))
        return Response()

    monkeypatch.setattr("application.workflows.authorization_filter.urllib.request.urlopen", fake_urlopen)

    allowed = batch_check_documents(AuthorizationContext(provider="local", user_id="user 1"), ["doc-1", "doc-2", "doc-1"])

    assert allowed == {"doc-1": True, "doc-2": False}
    assert captured["url"] == "http://spicedb.test/v1/permissions/checkbulk"
    assert captured["headers"]["Authorization"] == "Bearer secret-token"
    assert captured["payload"] == {
        "consistency": {"fully_consistent": True},
        "items": [
            {
                "resource": {"object_type": "document", "object_id": "doc-1"},
                "permission": "viewer",
                "subject": {"object": {"object_type": "user", "object_id": "local__user_1"}},
            },
            {
                "resource": {"object_type": "document", "object_id": "doc-2"},
                "permission": "viewer",
                "subject": {"object": {"object_type": "user", "object_id": "local__user_1"}},
            },
        ],
    }


def test_batch_check_documents_denies_all_documents_when_spicedb_key_is_missing(monkeypatch) -> None:
    from application.workflows.authorization_filter import AuthorizationContext, batch_check_documents

    monkeypatch.setenv("AUTHZ_GRAPH_BACKEND", "spicedb")
    monkeypatch.delenv("SPICEDB_PRESHARED_KEY", raising=False)
    monkeypatch.setattr(
        "application.workflows.authorization_filter._post_json",
        lambda url, payload, bearer_token=None: (_ for _ in ()).throw(AssertionError("permission graph should not be called")),
    )

    allowed = batch_check_documents(AuthorizationContext(provider="local", user_id="user-1"), ["doc-1", "doc-2"])

    assert allowed == {"doc-1": False, "doc-2": False}


def test_batch_check_documents_can_use_openfga_backend_when_configured(monkeypatch) -> None:
    from application.workflows.authorization_filter import AuthorizationContext, batch_check_documents

    monkeypatch.setenv("AUTHZ_GRAPH_BACKEND", "openfga")
    monkeypatch.setenv("OPENFGA_API_URL", "http://openfga.test")
    monkeypatch.setenv("OPENFGA_STORE_ID", "store-1")
    monkeypatch.setenv("OPENFGA_AUTHORIZATION_MODEL_ID", "model-1")
    monkeypatch.setenv("OPENFGA_API_TOKEN", "secret-token")
    captured: dict[str, object] = {}

    def fake_post_json(url, payload, bearer_token=None):
        captured["url"] = url
        captured["payload"] = payload
        captured["token"] = bearer_token
        return {"result": [{"allowed": True}, {"allowed": False}]}

    monkeypatch.setattr("application.workflows.authorization_filter._post_json", fake_post_json)

    allowed = batch_check_documents(AuthorizationContext(provider="local", user_id="user-1"), ["doc-1", "doc-2"])

    assert allowed == {"doc-1": True, "doc-2": False}
    assert captured["url"] == "http://openfga.test/stores/store-1/batch-check"
    assert captured["token"] == "secret-token"


def test_query_context_is_built_only_from_authorized_hits(monkeypatch) -> None:
    from application.workflows.query_execution import run_query_documents

    monkeypatch.setenv("AUTHZ_ENABLED", "true")
    seen_context_hits: list[dict[str, object]] = []

    body = SimpleNamespace(
        query="policy",
        top_k=2,
        provider="test",
        filters={},
        generate=False,
        is_optimized=False,
        fast_mode=True,
        smart_lookup=False,
        structural_hops=0,
        preferred_tags=None,
        reranker="none",
        rerank_top_n=10,
        mix_mode=False,
        mix_weight=0.5,
        auth_context={"provider": "local", "user_id": "u1"},
    )

    class Provider:
        def embed(self, text: str) -> list[float]:
            return [0.1, 0.2]

    def prepare_context(hits, max_docs, max_tokens):
        seen_context_hits.extend(hits)
        return ([{"snippet": "ok"}], [], 1)

    result = run_query_documents(
        body,
        rag_service=object(),
        get_provider=lambda name: Provider(),
        qdrant_ctor=lambda: object(),
        build_query_rewrite_fn=lambda provider, query, **kwargs: {
            "enabled": False,
            "rewritten_query": query,
            "high_level_keys": [],
            "low_level_keys": [],
            "modality_hints": [],
            "entity_terms": [],
        },
        run_search_fn=lambda **kwargs: [
            {"id": "point-1", "score": 0.9, "payload": {"doc_id": "doc-allowed", "content": "allowed", "component_type": "chunk"}},
            {"id": "point-2", "score": 0.8, "payload": {"doc_id": "doc-denied", "content": "denied", "component_type": "chunk"}},
        ],
        run_high_recall_fn=lambda **kwargs: [],
        merge_hits_fn=lambda primary, secondary, limit: primary + secondary,
        build_fused_hits_fn=lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: sem_hits + struct_hits,
        rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
        prepare_context_fn=prepare_context,
        context_limits_fn=lambda: (1000, 10),
        score_thresholds_fn=lambda: (0.0, 0.0),
        generation_enabled_fn=lambda: False,
        configured_search_top_k_fn=lambda top_k: top_k,
        authorize_hits_fn=lambda hits, auth_context: [
            hit for hit in hits if hit["payload"]["doc_id"] == "doc-allowed"
        ],
    )

    assert result["count"] == 1
    assert [hit["payload"]["doc_id"] for hit in seen_context_hits] == ["doc-allowed"]


def test_query_retries_high_recall_once_when_authorized_hits_are_too_few(monkeypatch) -> None:
    from application.workflows.query_execution import run_query_documents

    monkeypatch.setenv("AUTHZ_ENABLED", "true")
    high_recall_calls = 0
    seen_context_hits: list[dict[str, object]] = []

    body = SimpleNamespace(
        query="policy",
        top_k=2,
        provider="test",
        filters={},
        generate=False,
        is_optimized=False,
        fast_mode=True,
        smart_lookup=False,
        structural_hops=0,
        preferred_tags=None,
        reranker="none",
        rerank_top_n=10,
        mix_mode=False,
        mix_weight=0.5,
        auth_context={"provider": "local", "user_id": "u1"},
    )

    class Provider:
        def embed(self, text: str) -> list[float]:
            return [0.1, 0.2]

    def run_high_recall(**kwargs):
        nonlocal high_recall_calls
        high_recall_calls += 1
        return [
            {"id": "point-2", "score": 0.7, "payload": {"doc_id": "doc-allowed-1", "content": "allowed", "component_type": "chunk"}},
            {"id": "point-3", "score": 0.6, "payload": {"doc_id": "doc-allowed-2", "content": "allowed", "component_type": "chunk"}},
        ]

    def prepare_context(hits, max_docs, max_tokens):
        seen_context_hits.extend(hits)
        return ([{"snippet": "ok"} for _ in hits], [], 1)

    result = run_query_documents(
        body,
        rag_service=object(),
        get_provider=lambda name: Provider(),
        qdrant_ctor=lambda: object(),
        build_query_rewrite_fn=lambda provider, query, **kwargs: {
            "enabled": False,
            "rewritten_query": query,
            "high_level_keys": [],
            "low_level_keys": [],
            "modality_hints": [],
            "entity_terms": [],
        },
        run_search_fn=lambda **kwargs: [
            {"id": "point-1", "score": 0.9, "payload": {"doc_id": "doc-denied", "content": "denied", "component_type": "chunk"}},
        ],
        run_high_recall_fn=run_high_recall,
        merge_hits_fn=lambda primary, secondary, limit: primary + secondary,
        build_fused_hits_fn=lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: sem_hits + struct_hits,
        rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
        prepare_context_fn=prepare_context,
        context_limits_fn=lambda: (1000, 10),
        score_thresholds_fn=lambda: (0.0, 0.0),
        generation_enabled_fn=lambda: False,
        configured_search_top_k_fn=lambda top_k: top_k,
        authorize_hits_fn=lambda hits, auth_context: [
            hit for hit in hits if str(hit["payload"]["doc_id"]).startswith("doc-allowed")
        ],
    )

    assert high_recall_calls == 1
    assert result["count"] == 2
    assert [hit["payload"]["doc_id"] for hit in seen_context_hits] == ["doc-allowed-1", "doc-allowed-2"]
