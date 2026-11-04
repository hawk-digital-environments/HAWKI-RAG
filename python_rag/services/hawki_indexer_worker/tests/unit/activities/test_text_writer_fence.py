"""Direct text reuses signed managed acquisition and guarded sink drainage."""

from concurrent.futures import ThreadPoolExecutor
from threading import Event

import httpx
import pytest

from hawki_indexer_worker.adapters import managed_writer
from hawki_indexer_worker.adapters.managed_writer import ManagedWriterRegistration
from hawki_indexer_worker.indexing.point_identity import deterministic_point_id
from hawki_pipeline_callbacks import LaravelCallbackClient
from hawki_vector_store.client import QdrantHTTP
from services.hawki_indexer_worker.tests.unit.activities.managed_writer_test_support import (
    registration,
)


def test_direct_text_requires_acquire_and_guards_each_sink_admission(
    tmp_path, monkeypatch
):
    import hashlib
    import hmac
    import json

    template = registration(tmp_path)
    template.workflow_input["text_writer_protocol"] = 1
    fenced, points, receipts = False, [], []

    def receive(request):
        event = json.loads(request.content)
        assert (
            request.headers["X-Hawki-Signature"]
            == "v1="
            + hmac.new(
                b"test-secret",
                request.headers["X-Hawki-Timestamp"].encode() + b"." + request.content,
                hashlib.sha256,
            ).hexdigest()
        )
        if event["action"] == "acquire" and fenced:
            return httpx.Response(409, json={"error": "fenced"})
        receipts.append(event["action"])
        return httpx.Response(
            200,
            json={
                "success": True,
                "status": "active" if event["action"] == "acquire" else "released",
                "targets": [],
            },
        )

    client = httpx.Client(transport=httpx.MockTransport(receive))
    monkeypatch.setattr(
        managed_writer,
        "LaravelCallbackClient",
        lambda settings: LaravelCallbackClient(settings, client=client),
    )
    writer = ManagedWriterRegistration.acquire_for(
        template.settings, template.workflow_input, template.info
    )
    assert writer is not None and receipts == ["acquire"]
    writer.targets["owned"] = None
    entered, proceed, released = Event(), Event(), Event()

    def delayed(*args, **kwargs):
        entered.set()
        assert proceed.wait(5)
        points.append("owned")

    monkeypatch.setattr(QdrantHTTP, "upsert", delayed)
    sink = writer.vector_writer()
    point = {
        "id": deterministic_point_id("owned", 0),
        "payload": {"doc_id": "owned", "source_id": "source", "dataset_id": "dataset"},
    }

    def finish():
        writer.release()
        released.set()

    with ThreadPoolExecutor(2) as pool:
        write = pool.submit(sink.upsert, [point])
        assert entered.wait(5)
        fenced = True
        with pytest.raises(RuntimeError):
            ManagedWriterRegistration.acquire_for(
                template.settings, template.workflow_input, template.info
            )
        with pytest.raises(RuntimeError):
            sink.upsert([point])  # cached adapter must recheck admission
        release = pool.submit(finish)
        try:
            assert not released.wait(0.05)
        finally:
            proceed.set()
        write.result(5)
        release.result(5)
    assert receipts[-1] == "release" and points == ["owned"]
    points.clear()  # cleanup is permitted only after this confirmed release
    with pytest.raises(RuntimeError, match="closed"):
        sink.upsert([point])
    assert points == []
    client.close()
