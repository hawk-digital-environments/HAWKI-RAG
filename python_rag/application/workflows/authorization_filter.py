"""Retrieval-time permission graph authorization filtering."""

from __future__ import annotations

import json
import logging
import os
import urllib.error
import urllib.request
from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class AuthorizationContext:
    """Stable user identity used for authorization checks."""

    provider: str
    user_id: str

    @classmethod
    def from_payload(cls, payload: Any) -> "AuthorizationContext | None":
        if isinstance(payload, Mapping):
            provider = _string(payload.get("provider"))
            user_id = _string(payload.get("user_id"))
        else:
            provider = _string(getattr(payload, "provider", None))
            user_id = _string(getattr(payload, "user_id", None))
        if not provider or not user_id:
            return None
        return cls(provider=provider, user_id=user_id)

    @property
    def user_object(self) -> str:
        return f"user:{_graph_id(self.provider, self.user_id)}"


def authorization_enabled() -> bool:
    return os.getenv("AUTHZ_ENABLED", "false").strip().lower() in {"1", "true", "yes", "on"}


def filter_authorized_hits(hits: list[dict[str, Any]], auth_context: Any) -> list[dict[str, Any]]:
    """Return only hits whose document object is viewable by the user."""

    if not authorization_enabled():
        return hits

    context = AuthorizationContext.from_payload(auth_context)
    if context is None:
        logger.warning("authz:retrieval denied all hits because auth_context is missing")
        return []

    doc_ids = _document_ids(hits)
    allowed = batch_check_documents(context, doc_ids)
    filtered = [hit for hit in hits if allowed.get(_document_id(hit), False)]
    logger.info("authz:retrieval user=%s candidates=%s allowed=%s", context.user_object, len(hits), len(filtered))
    return filtered


def batch_check_documents(context: AuthorizationContext, document_ids: list[str]) -> dict[str, bool]:
    """Check document viewer access through the configured permission graph."""

    document_ids = list(dict.fromkeys(doc_id for doc_id in document_ids if doc_id))
    if not document_ids:
        return {}

    backend = os.getenv("AUTHZ_GRAPH_BACKEND", "spicedb").strip().lower()
    if backend == "openfga":
        return _batch_check_documents_openfga(context, document_ids)

    return _batch_check_documents_spicedb(context, document_ids)


def _batch_check_documents_spicedb(context: AuthorizationContext, document_ids: list[str]) -> dict[str, bool]:
    base_url = os.getenv("SPICEDB_API_URL", "http://spicedb:8443").rstrip("/")
    token = os.getenv("SPICEDB_PRESHARED_KEY", "").strip()
    if not token:
        logger.warning("authz:spicedb missing SPICEDB_PRESHARED_KEY; denying %s docs", len(document_ids))
        return {doc_id: False for doc_id in document_ids}

    payload: dict[str, Any] = {
        "consistency": _spicedb_consistency(),
        "items": [
            {
                "resource": {
                    "object_type": "document",
                    "object_id": _safe(doc_id),
                },
                "permission": "viewer",
                "subject": {
                    "object": {
                        "object_type": "user",
                        "object_id": _graph_id(context.provider, context.user_id),
                    }
                },
            }
            for doc_id in document_ids
        ],
    }

    try:
        response = _post_json(f"{base_url}/v1/permissions/checkbulk", payload, bearer_token=token)
    except Exception as exc:
        logger.warning("authz:spicedb checkbulk failed: %s", exc)
        return {doc_id: False for doc_id in document_ids}

    pairs = response.get("pairs")
    if not isinstance(pairs, list):
        return {doc_id: False for doc_id in document_ids}

    return {
        doc_id: _spicedb_has_permission(
            ((pairs[index] if index < len(pairs) and isinstance(pairs[index], dict) else {}).get("item") or {}).get(
                "permissionship"
            )
        )
        for index, doc_id in enumerate(document_ids)
    }


def _batch_check_documents_openfga(context: AuthorizationContext, document_ids: list[str]) -> dict[str, bool]:
    base_url = os.getenv("OPENFGA_API_URL", "http://openfga:8080").rstrip("/")
    store_id = os.getenv("OPENFGA_STORE_ID", "").strip()
    if not store_id:
        logger.warning("authz:openfga missing OPENFGA_STORE_ID; denying %s docs", len(document_ids))
        return {doc_id: False for doc_id in document_ids}

    checks = [
        {
            "tuple_key": {
                "user": context.user_object,
                "relation": "viewer",
                "object": f"document:{_safe(doc_id)}",
            },
            "contextual_tuples": {"tuple_keys": []},
        }
        for doc_id in document_ids
    ]
    payload: dict[str, Any] = {"checks": checks}
    model_id = os.getenv("OPENFGA_AUTHORIZATION_MODEL_ID", "").strip()
    if model_id:
        payload["authorization_model_id"] = model_id

    try:
        response = _post_json(
            f"{base_url}/stores/{store_id}/batch-check",
            payload,
            bearer_token=os.getenv("OPENFGA_API_TOKEN", "").strip() or None,
        )
    except Exception as exc:
        logger.warning("authz:openfga batch check failed: %s", exc)
        return {doc_id: False for doc_id in document_ids}

    results = response.get("result")
    if not isinstance(results, list):
        return {doc_id: False for doc_id in document_ids}

    return {
        doc_id: bool((results[index] if index < len(results) and isinstance(results[index], dict) else {}).get("allowed"))
        for index, doc_id in enumerate(document_ids)
    }


def _post_json(url: str, payload: dict[str, Any], bearer_token: str | None = None) -> dict[str, Any]:
    data = json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"}, method="POST")
    if bearer_token:
        request.add_header("Authorization", f"Bearer {bearer_token}")
    timeout = float(os.getenv("AUTHZ_GRAPH_TIMEOUT_SECONDS", os.getenv("SPICEDB_TIMEOUT_SECONDS", "5")))
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body = response.read().decode("utf-8")
    decoded = json.loads(body or "{}")
    return decoded if isinstance(decoded, dict) else {}


def _document_ids(hits: list[dict[str, Any]]) -> list[str]:
    return list(dict.fromkeys(doc_id for hit in hits if (doc_id := _document_id(hit))))


def _document_id(hit: dict[str, Any]) -> str:
    payload = hit.get("payload") or {}
    if not isinstance(payload, dict):
        payload = {}
    return _string(payload.get("doc_id") or payload.get("document_id") or hit.get("id")) or ""


def _safe(value: str) -> str:
    text = value.strip()
    return "".join(character if character.isalnum() or character in {"/", "_", "|", "-", "=", "+"} else "_" for character in text) or "unknown"


def _graph_id(provider: str, external_id: str) -> str:
    return f"{_safe(provider)}__{_safe(external_id)}"


def _spicedb_consistency() -> dict[str, bool]:
    if os.getenv("SPICEDB_CONSISTENCY", "minimize_latency").strip().lower() == "fully_consistent":
        return {"fully_consistent": True}
    return {"minimize_latency": True}


def _spicedb_has_permission(permissionship: Any) -> bool:
    if isinstance(permissionship, int):
        return permissionship == 2
    return isinstance(permissionship, str) and permissionship.endswith("HAS_PERMISSION")


def _string(value: Any) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text or None


__all__ = ["AuthorizationContext", "batch_check_documents", "filter_authorized_hits"]
