"""Proof is authenticated, fresh and bound to the exact request and immutable targets."""

import asyncio
import hashlib
import hmac
import json
import time
from types import SimpleNamespace

import httpx
import pytest

from hawki_indexer_worker.activities import deletion_proof as module
from hawki_rag_contracts.pipeline.deletion import DeleteManagedDocumentInput


def body():
    return DeleteManagedDocumentInput(
        operation_id="delete_" + "a" * 64,
        managed_document_id="adoc_a",
        dataset_id="dataset-a",
        targets=[],
        writers=[{"workflow_id": "writer", "run_id": "run"}],
    )


@pytest.mark.parametrize(
    "mode",
    [
        "released",
        "approved_reconciliation",
        "bad_signature",
        "wrong_request",
        "stale",
        "active",
        "refused",
        "malformed",
    ],
)
def test_proof_validation(monkeypatch, mode):
    secret = "test-secret"
    seen = []

    def handler(request):
        seen.append(request)
        if mode == "refused":
            return httpx.Response(409, json={"verified": False})
        if mode == "malformed":
            return httpx.Response(200, json={})
        proof = {
            "verified": True,
            "request_sha256": hashlib.sha256(request.content).hexdigest(),
            "issued_at": int(time.time()),
            "dispatch_sequence": 1,
            "sources": [
                {
                    "source_id": "source",
                    "kind": mode
                    if mode in {"released", "approved_reconciliation", "active"}
                    else "released",
                }
            ],
        }
        if mode == "wrong_request":
            proof["request_sha256"] = "wrong"
        if mode == "stale":
            proof["issued_at"] -= 1000
        raw = module.canonical(proof)
        signature = hmac.new(
            secret.encode(),
            ("hawki-deletion-writer-proof-v1\n" + raw).encode(),
            hashlib.sha256,
        ).hexdigest()
        return httpx.Response(
            200,
            json={
                "proof": raw,
                "signature": "bad" if mode == "bad_signature" else signature,
            },
        )

    factory = httpx.AsyncClient
    monkeypatch.setattr(
        module.httpx,
        "AsyncClient",
        lambda **kw: factory(transport=httpx.MockTransport(handler), **kw),
    )
    monkeypatch.setattr(
        module.activity,
        "info",
        lambda: SimpleNamespace(
            workflow_id="delete-workflow", workflow_run_id="delete-run"
        ),
    )
    value = body()
    settings = SimpleNamespace(
        callback_secret=secret,
        callback_url="http://laravel/api/internal/pipeline/worker-events",
        callback_timeout_seconds=1,
    )
    if mode in {"released", "approved_reconciliation"}:
        asyncio.run(module.require_durable_proof(value, value.writers[0], settings))
    else:
        with pytest.raises(Exception):
            asyncio.run(module.require_durable_proof(value, value.writers[0], settings))
    request = seen[0]
    stamp = request.headers["X-Hawki-Timestamp"]
    assert (
        request.headers["X-Hawki-Signature"]
        == "v1="
        + hmac.new(
            secret.encode(), stamp.encode() + b"." + request.content, hashlib.sha256
        ).hexdigest()
    )
    assert (
        json.loads(request.content)["targets_sha256"]
        == hashlib.sha256(b"[]").hexdigest()
    )
