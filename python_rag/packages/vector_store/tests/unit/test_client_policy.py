"""Qdrant client gateway and collection-selection policies."""

from __future__ import annotations

from pathlib import Path


def test_qdrant_client_ops_capture_gateway_and_limit_policy() -> None:
    from hawki_vector_store._client_policy import (
        resolve_per_collection_limit,
        resolve_selected_collection,
    )

    assert resolve_per_collection_limit(4, 10) == 4
    assert resolve_per_collection_limit(0, 10) == 10
    assert resolve_selected_collection("", lambda: "picked") == "picked"
    assert resolve_selected_collection("explicit", lambda: "picked") == "explicit"


def test_qdrant_client_has_no_gateway_version_reflection() -> None:
    source_root = Path(__file__).resolve().parents[2] / "src" / "hawki_vector_store"
    source = "\n".join(
        (source_root / name).read_text(encoding="utf-8")
        for name in ("_client_policy.py", "client.py")
    )

    assert "callable_supports_kwarg" not in source
    assert "gateway_supports_operation_id" not in source
    assert "from inspect import signature" not in source
