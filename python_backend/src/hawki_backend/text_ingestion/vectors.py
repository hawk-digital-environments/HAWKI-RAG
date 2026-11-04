"""Owned Qdrant point deletion for direct-text sources.

Only points that carry the exact ``doc_id``, ``source_id`` and ``dataset_id``
of the owned source are deleted. The collection is server-side configuration;
the caller never supplies it. Direct text never contributes Neo4j data, so no
graph cleanup exists here.
"""

from __future__ import annotations

import logging
from typing import Any, Protocol
from urllib.parse import quote

from .settings import TextIngestionSettings

logger = logging.getLogger(__name__)


class HttpResponse(Protocol):
    status_code: int

    def json(self) -> Any: ...


class HttpClient(Protocol):
    headers: Any

    def post(self, url: str, json: dict[str, Any]) -> HttpResponse: ...


class TextVectorDeletionError(RuntimeError):
    """Raised when Qdrant did not confirm the owned point deletion."""


class TextVectorDeletion:
    """Delete direct-text points from the dataset's Qdrant collection."""

    def __init__(
        self,
        settings: TextIngestionSettings,
        client: HttpClient | None = None,
    ) -> None:
        self._settings = settings
        self._client = client

    def delete(
        self,
        *,
        collection: str,
        dataset_id: str,
        source_id: str,
        document_id: str,
        operation_key: str,
    ) -> None:
        if not collection.strip():
            raise TextVectorDeletionError("the dataset has no Qdrant collection")

        client = self._client
        if client is None:
            import httpx

            headers = {"X-Operation-Id": operation_key}
            if self._settings.qdrant_api_key:
                headers["api-key"] = self._settings.qdrant_api_key
            client = httpx.Client(
                timeout=15.0,
                headers=headers,
            )
            owned = True
        else:
            owned = False

        url = (
            self._settings.qdrant_url.rstrip("/")
            + "/collections/"
            + quote(collection, safe="")
            + "/points/delete?wait=true"
        )
        try:
            response = client.post(
                url,
                json={
                    "filter": {
                        "must": [
                            {"key": "doc_id", "match": {"value": document_id}},
                            {"key": "source_id", "match": {"value": source_id}},
                            {"key": "dataset_id", "match": {"value": dataset_id}},
                        ]
                    }
                },
            )
            if not 200 <= response.status_code < 300:
                raise TextVectorDeletionError(
                    f"Qdrant rejected deletion with HTTP {response.status_code}"
                )
            body = response.json()
            if (
                not isinstance(body, dict)
                or body.get("status") != "ok"
                or not isinstance(body.get("result"), dict)
                or body["result"].get("status") != "completed"
            ):
                raise TextVectorDeletionError("Qdrant did not confirm deletion")
            # A successful RPC alone is insufficient: verify the owned filter
            # is empty before allowing source identity reuse.
            verification = client.post(
                url.split("/points/delete", 1)[0] + "/points/count",
                json={
                    "filter": {
                        "must": [
                            {"key": "doc_id", "match": {"value": document_id}},
                            {"key": "source_id", "match": {"value": source_id}},
                            {"key": "dataset_id", "match": {"value": dataset_id}},
                        ]
                    },
                    "exact": True,
                },
            )
            counted = verification.json()
            if (
                verification.status_code != 200
                or not isinstance(counted, dict)
                or counted.get("status") != "ok"
                or not isinstance(counted.get("result"), dict)
                or type(counted["result"].get("count")) is not int
                or counted["result"]["count"] != 0
            ):
                raise TextVectorDeletionError("Qdrant deletion verification failed")
        except TextVectorDeletionError:
            raise
        except Exception as error:  # noqa: BLE001 - stable, non-leaking failure
            logger.warning(
                "direct-text Qdrant deletion failed error=%s",
                type(error).__name__,
            )
            raise TextVectorDeletionError("Qdrant deletion did not complete") from None
        finally:
            if owned:
                client.close()


__all__ = [
    "HttpClient",
    "HttpResponse",
    "TextVectorDeletion",
    "TextVectorDeletionError",
]
