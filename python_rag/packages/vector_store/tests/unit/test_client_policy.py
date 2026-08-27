"""Qdrant client gateway and collection-selection policies."""

from __future__ import annotations


def test_qdrant_client_ops_capture_gateway_and_limit_policy() -> None:
    from hawki_vector_store._client_policy import (
        gateway_supports_operation_id,
        resolve_per_collection_limit,
        resolve_selected_collection,
    )

    class NewGateway:
        def upsert(
            self,
            points: list[dict[str, object]],
            *,
            timeout: float,
            operation_id: str | None = None,
        ) -> None:
            return None

    class LegacyGateway:
        def upsert(self, points: list[dict[str, object]], *, timeout: float) -> None:
            return None

    assert gateway_supports_operation_id(NewGateway(), "upsert") is True
    assert gateway_supports_operation_id(LegacyGateway(), "upsert") is False
    assert resolve_per_collection_limit(4, 10) == 4
    assert resolve_per_collection_limit(0, 10) == 10
    assert resolve_selected_collection("", lambda: "picked") == "picked"
    assert resolve_selected_collection("explicit", lambda: "picked") == "explicit"
