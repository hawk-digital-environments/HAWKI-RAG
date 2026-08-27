"""Bridge startup-check injection behavior."""

from __future__ import annotations

import logging
from types import SimpleNamespace
from unittest.mock import patch

from requests import ConnectionError as RequestsConnectionError


def test_startup_checks_accept_injected_dependency_checks() -> None:
    from hawki_bridge.startup_checks import run_startup_checks

    calls: list[str] = []

    def check_qdrant(timeout: float) -> None:
        calls.append(f"qdrant:{timeout}")
        if calls.count(f"qdrant:{timeout}") == 1:
            raise RequestsConnectionError("not yet")

    def check_neo4j() -> None:
        calls.append("neo4j")

    settings = SimpleNamespace(
        startup_check_attempts=2,
        startup_check_timeout_seconds=1.0,
        startup_check_backoff_seconds=0.01,
        startup_checks_enabled=True,
    )

    with patch("hawki_bridge.startup_checks.time.sleep"):
        run_startup_checks(
            settings,
            logger=logging.getLogger("startup-boundary-test"),
            check_qdrant_fn=lambda: check_qdrant(1.0),
            check_neo4j_fn=check_neo4j,
        )

    assert calls == ["qdrant:1.0", "qdrant:1.0", "neo4j"]
