"""Transport-level contract tests for signed Laravel worker callbacks."""

from __future__ import annotations

import hashlib
import hmac
from collections.abc import Callable
from typing import Any

import httpx
import pytest

from hawki_worker_runtime.callbacks import (
    LaravelCallbackClient,
    LaravelCallbackError,
    LaravelCallbackSettings,
)


ENDPOINT = "https://laravel.example/api/internal/pipeline/worker-events"
SECRET = "test-signing-secret"


def _event(**updates: Any) -> dict[str, Any]:
    event: dict[str, Any] = {
        "schema_version": 1,
        "event_id": "event-123",
        "producer": "indexer",
        "metrics": {"zeta": 2, "alpha": "Grüße"},
        "status": "completed",
    }
    event.update(updates)
    return event


def _client(
    handler: Callable[[httpx.Request], httpx.Response],
    *,
    endpoint: str = ENDPOINT,
    timeout_seconds: float = 0.75,
    retry_attempts: int = 3,
    secret: str = SECRET,
) -> LaravelCallbackClient:
    transport = httpx.MockTransport(handler)
    http_client = httpx.Client(transport=transport)
    return LaravelCallbackClient(
        LaravelCallbackSettings(
            endpoint=endpoint,
            secret=secret,
            timeout_seconds=timeout_seconds,
            retry_attempts=retry_attempts,
        ),
        client=http_client,
    )


def test_send_signs_exact_canonical_utf8_body() -> None:
    requests: list[httpx.Request] = []

    def accept(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return httpx.Response(202, json={"accepted": True}, request=request)

    result = _client(accept).send(_event(), timestamp=1_775_210_400)

    expected_body = (
        b'{"event_id":"event-123","metrics":{"alpha":"Gr\xc3\xbc\xc3\x9fe","zeta":2},'
        b'"producer":"indexer","schema_version":1,"status":"completed"}'
    )
    expected_digest = hmac.new(
        SECRET.encode("utf-8"),
        b"1775210400." + expected_body,
        hashlib.sha256,
    ).hexdigest()

    assert result == {"accepted": True}
    assert len(requests) == 1
    assert requests[0].content == expected_body
    assert requests[0].headers["content-type"] == "application/json"
    assert requests[0].headers["x-hawki-timestamp"] == "1775210400"
    assert requests[0].headers["x-hawki-signature"] == f"v1={expected_digest}"


def test_retry_reuses_one_payload_timestamp_and_signature(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    requests: list[tuple[bytes, str, str]] = []
    statuses = iter((503, 429, 202))
    sleeps: list[int] = []

    class CountingEvent:
        calls = 0

        def model_dump(self, *, mode: str, by_alias: bool) -> dict[str, Any]:
            assert mode == "json"
            assert by_alias is True
            self.calls += 1
            return _event()

    def respond(request: httpx.Request) -> httpx.Response:
        requests.append(
            (
                request.content,
                request.headers["x-hawki-timestamp"],
                request.headers["x-hawki-signature"],
            )
        )
        status = next(statuses)
        return httpx.Response(status, json={"status": status}, request=request)

    monkeypatch.setattr(
        "hawki_worker_runtime.callbacks.time.sleep",
        lambda seconds: sleeps.append(seconds),
    )
    event = CountingEvent()

    result = _client(respond).send(event, timestamp=1_775_210_401)

    assert result == {"status": 202}
    assert event.calls == 1
    assert len(requests) == 3
    assert requests[0] == requests[1] == requests[2]
    assert sleeps == [1, 2]


def test_timeout_is_explicit_and_retried_without_reflecting_secrets(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    timeout_extensions: list[dict[str, float]] = []
    sleeps: list[int] = []

    def time_out(request: httpx.Request) -> httpx.Response:
        timeout_extensions.append(request.extensions["timeout"])
        raise httpx.ReadTimeout(
            "authorization: Bearer timeout-ultra-secret",
            request=request,
        )

    monkeypatch.setattr(
        "hawki_worker_runtime.callbacks.time.sleep",
        lambda seconds: sleeps.append(seconds),
    )

    with pytest.raises(LaravelCallbackError) as caught:
        _client(time_out, timeout_seconds=0.125, retry_attempts=2).send(
            _event(),
            timestamp=1_775_210_402,
        )

    assert len(timeout_extensions) == 2
    assert all(set(extension.values()) == {0.125} for extension in timeout_extensions)
    assert sleeps == [1]
    assert str(caught.value) == (
        "Laravel did not accept worker event event-123: request timed out."
    )
    assert caught.value.__cause__ is None
    assert "timeout-ultra-secret" not in str(caught.value)


@pytest.mark.parametrize("status", [400, 401, 403, 404, 408, 422])
def test_ordinary_client_errors_are_not_retried(status: int) -> None:
    attempts = 0

    def reject(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        return httpx.Response(
            status,
            json={"error": "password=body-ultra-secret"},
            request=request,
        )

    with pytest.raises(LaravelCallbackError) as caught:
        _client(reject).send(_event(), timestamp=1_775_210_403)

    assert attempts == 1
    assert str(caught.value).endswith(f": HTTP {status}.")
    assert "body-ultra-secret" not in str(caught.value)


def test_exhausted_server_errors_do_not_expose_endpoint_body_or_secret(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    endpoint_secret = "endpoint-ultra-secret"
    callback_secret = "callback-ultra-secret"
    attempts = 0

    def fail(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        return httpx.Response(
            503,
            text="api_key=body-ultra-secret",
            request=request,
        )

    monkeypatch.setattr(
        "hawki_worker_runtime.callbacks.time.sleep", lambda _seconds: None
    )

    with pytest.raises(LaravelCallbackError) as caught:
        _client(
            fail,
            endpoint=f"{ENDPOINT}?access_token={endpoint_secret}",
            secret=callback_secret,
            retry_attempts=2,
        ).send(_event(), timestamp=1_775_210_404)

    error = str(caught.value)
    assert attempts == 2
    assert error.endswith(": HTTP 503.")
    assert caught.value.__cause__ is None
    assert endpoint_secret not in error
    assert callback_secret not in error
    assert "body-ultra-secret" not in error
    assert ENDPOINT not in error


def test_conflicting_event_id_is_reported_without_retry() -> None:
    attempts = 0

    def conflict(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        return httpx.Response(
            409,
            json={"error": "pipeline_worker_event_id_collision"},
            request=request,
        )

    with pytest.raises(LaravelCallbackError, match="reused callback event_id"):
        _client(conflict).send(_event(), timestamp=1_775_210_405)

    assert attempts == 1


@pytest.mark.parametrize(
    "error_code",
    [
        "pipeline_worker_event_target_unavailable",
        "pipeline_worker_event_state_unavailable",
    ],
)
def test_transient_laravel_conflicts_are_retried(
    error_code: str,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    attempts = 0

    def respond(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            return httpx.Response(409, json={"error": error_code}, request=request)
        return httpx.Response(202, json={"accepted": True}, request=request)

    monkeypatch.setattr(
        "hawki_worker_runtime.callbacks.time.sleep", lambda _seconds: None
    )

    assert _client(respond).send(_event(), timestamp=1_775_210_405) == {
        "accepted": True
    }
    assert attempts == 2


def test_target_mismatch_conflict_is_not_misreported_or_retried() -> None:
    attempts = 0

    def reject(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        return httpx.Response(
            409,
            json={"error": "pipeline_worker_event_target_mismatch"},
            request=request,
        )

    with pytest.raises(LaravelCallbackError) as caught:
        _client(reject).send(_event(), timestamp=1_775_210_405)

    assert attempts == 1
    assert str(caught.value).endswith(": HTTP 409.")
    assert "reused callback event_id" not in str(caught.value)


@pytest.mark.parametrize(
    ("response", "message"),
    [
        (httpx.Response(202, text="not-json"), "returned invalid JSON"),
        (httpx.Response(202, json=["not", "an", "object"]), "returned non-object JSON"),
    ],
)
def test_success_response_errors_are_safe(
    response: httpx.Response,
    message: str,
) -> None:
    def malformed(request: httpx.Request) -> httpx.Response:
        response.request = request
        return response

    with pytest.raises(LaravelCallbackError, match=message) as caught:
        _client(malformed).send(_event(), timestamp=1_775_210_406)

    assert caught.value.__cause__ is None


@pytest.mark.parametrize(
    "event",
    [
        {"schema_version": 2, "event_id": "event-123"},
        {"schema_version": 1, "event_id": ""},
        {"schema_version": 1, "event_id": "event-123", "metric": float("nan")},
    ],
)
def test_invalid_events_fail_before_transport(event: dict[str, Any]) -> None:
    def unexpected(_request: httpx.Request) -> httpx.Response:
        pytest.fail("invalid events must not reach the network")

    with pytest.raises(ValueError):
        _client(unexpected).send(event, timestamp=1_775_210_407)
