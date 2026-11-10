from types import SimpleNamespace
from unittest.mock import Mock

import pytest

from hawki_pipeline_callbacks.artifact_writer import url_artifact_writer


def setup():
    wire = dict(
        owner="url",
        source_id="source",
        job_id="job",
        generation=3,
        dataset_id="web",
        url_ingestion_operation_id="operation",
    )
    info = SimpleNamespace(
        workflow_id="workflow", workflow_run_id="run", activity_id="convert", attempt=2
    )
    settings = SimpleNamespace(
        callback_url="http://bff/events",
        callback_secret="secret",
        callback_timeout_seconds=2,
        callback_retry_attempts=1,
    )
    sender = Mock()
    sender.send.side_effect = lambda body: dict(
        success=True,
        status={"acquire": "active", "release": "released", "uncertain": "uncertain"}[
            body["action"]
        ],
    )
    factory = Mock()
    factory.return_value.__enter__ = Mock(return_value=sender)
    factory.return_value.__exit__ = Mock(return_value=False)
    return wire, info, settings, sender, factory


def test_writer_is_registered_before_artifacts_and_released_after_drain():
    wire, info, settings, sender, factory = setup()
    with url_artifact_writer(wire, info, settings, client_factory=factory):
        assert sender.send.call_count == 1
    assert [call.args[0]["action"] for call in sender.send.call_args_list] == [
        "acquire",
        "release",
    ]
    assert sender.send.call_args.args[0]["generation"] == 3


def test_fenced_writer_cannot_touch_artifacts_and_failed_writer_stays_uncertain():
    wire, info, settings, sender, factory = setup()
    with pytest.raises(ValueError):
        with url_artifact_writer(wire, info, settings, client_factory=factory):
            raise ValueError("ambiguous converter")
    assert sender.send.call_args.args[0]["action"] == "uncertain"
    sender.send.side_effect = RuntimeError("fenced")
    with pytest.raises(RuntimeError):
        with url_artifact_writer(wire, info, settings, client_factory=factory):
            pytest.fail("Writer touched artifacts after admission closed")
