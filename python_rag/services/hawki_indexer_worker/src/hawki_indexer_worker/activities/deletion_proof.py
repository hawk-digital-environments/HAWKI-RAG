"""Read a signed, operation-bound durable barrier; never repair writer state."""

import hashlib
import hmac
import json
import time

import httpx
from temporalio import activity
from temporalio.api.errordetails.v1 import NotFoundFailure
from temporalio.service import RPCError, RPCStatusCode
from google.protobuf.message import DecodeError

from hawki_indexer_worker.settings import IndexerSettings

from hawki_rag_contracts.pipeline.deletion import (
    DeleteManagedDocumentInput,
    DeletionWriter,
)


def execution_absent(error: RPCError) -> bool:
    """Only an exact typed execution absence qualifies for a proof lookup."""
    if error.status != RPCStatusCode.NOT_FOUND:
        return False
    try:
        status = error.grpc_status
        return (
            status.code == RPCStatusCode.NOT_FOUND.value
            and len(status.details) == 1
            and status.details[0].Is(NotFoundFailure.DESCRIPTOR)
            and status.details[0].Unpack(NotFoundFailure())
        )
    except (DecodeError, AttributeError, TypeError, ValueError):
        return False


def canonical(value: object) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


async def require_durable_proof(
    body: DeleteManagedDocumentInput, writer: DeletionWriter, settings: IndexerSettings
) -> None:
    """Unavailable/malformed proof always fails; targets and operation stay immutable."""
    info = activity.info()
    raw = canonical(
        {
            "operation_id": body.operation_id,
            "document_id": body.managed_document_id,
            "dataset_id": body.dataset_id,
            "deletion_workflow_id": info.workflow_id,
            "deletion_run_id": info.workflow_run_id,
            "writer_workflow_id": writer.workflow_id,
            "writer_run_id": writer.run_id,
            "targets_sha256": hashlib.sha256(
                canonical([t.model_dump(mode="json") for t in body.targets]).encode()
            ).hexdigest(),
        }
    ).encode()
    stamp = str(int(time.time()))
    secret = settings.callback_secret
    if not secret:
        raise RuntimeError("Deletion writer proof authentication is unavailable")
    endpoint = (
        settings.callback_url.rsplit("/", 1)[0] + "/managed-deletion-writer-proof"
    )
    headers = {
        "Content-Type": "application/json",
        "X-Hawki-Timestamp": stamp,
        "X-Hawki-Signature": "v1="
        + hmac.new(
            secret.encode(), stamp.encode() + b"." + raw, hashlib.sha256
        ).hexdigest(),
    }
    async with httpx.AsyncClient(
        timeout=settings.callback_timeout_seconds,
        follow_redirects=False,
        trust_env=False,
    ) as client:
        response = await client.post(endpoint, content=raw, headers=headers)
        response.raise_for_status()
        if len(response.content) > 64 * 1024:
            raise RuntimeError("Deletion writer proof is malformed")
        value = response.json()
    proof_raw = value["proof"]
    signature = hmac.new(
        secret.encode(),
        ("hawki-deletion-writer-proof-v1\n" + proof_raw).encode(),
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(signature, value["signature"]):
        raise RuntimeError("Deletion writer proof signature is invalid")
    proof = json.loads(proof_raw)
    if (
        proof.get("verified") is not True
        or proof.get("request_sha256") != hashlib.sha256(raw).hexdigest()
        or type(proof.get("issued_at")) is not int
        or abs(time.time() - proof["issued_at"]) > 300
        or type(proof.get("dispatch_sequence")) is not int
        or proof["dispatch_sequence"] < 1
        or not proof.get("sources")
        or any(
            p.get("kind") not in {"released", "approved_reconciliation"}
            for p in proof["sources"]
        )
    ):
        raise RuntimeError("Deletion writer proof is unconfirmed")
