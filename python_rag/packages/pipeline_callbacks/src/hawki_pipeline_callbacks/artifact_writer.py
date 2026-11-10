"""URL artifact writer admission/drainage using the existing managed ledger."""

from contextlib import contextmanager
import hashlib

from .client import LaravelCallbackClient, LaravelCallbackSettings


@contextmanager
def url_artifact_writer(wire, info, settings, *, client_factory=LaravelCallbackClient):
    if wire.get("owner") != "url":
        yield
        return
    identity = (
        f"{info.workflow_id}|{info.workflow_run_id}|{info.activity_id}|{info.attempt}"
    )
    body = dict(
        schema_version=1,
        workflow_id=info.workflow_id,
        run_id=info.workflow_run_id,
        activity_id=info.activity_id,
        attempt=info.attempt,
        owner="url",
        url_ingestion_operation_id=wire["url_ingestion_operation_id"],
        generation=wire["generation"],
        dataset_id=wire["dataset_id"],
        source_id=wire["source_id"],
        job_id=wire["job_id"],
        targets=[],
    )
    callback = LaravelCallbackSettings(
        endpoint=settings.callback_url.rsplit("/", 1)[0] + "/managed-writers",
        secret=settings.callback_secret,
        timeout_seconds=settings.callback_timeout_seconds,
        retry_attempts=settings.callback_retry_attempts,
    )
    with client_factory(callback) as client:

        def send(action):
            receipt = client.send(
                dict(
                    body,
                    action=action,
                    event_id=hashlib.sha256(identity.encode()).hexdigest()
                    + ":artifact:"
                    + action,
                )
            )
            if (
                receipt.get("success") is not True
                or receipt.get("status")
                != {
                    "acquire": "active",
                    "release": "released",
                    "uncertain": "uncertain",
                }[action]
            ):
                raise RuntimeError("URL artifact writer permission is unconfirmed")

        send("acquire")
        try:
            yield
        except BaseException:
            # An ambiguous local/external conversion must retain its ledger
            # entry. Workflow cancellation cannot release a live worker thread.
            send("uncertain")
            raise
        else:
            send("release")
