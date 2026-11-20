"""Shared availability policy for cross-service live tests."""

from __future__ import annotations

from collections.abc import Callable
import os
from typing import NoReturn

import pytest


_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _required() -> bool:
    return os.environ.get("RAWKI_INTEGRATION_REQUIRED", "").strip().lower() in _TRUTHY


def unavailable(reason: str) -> NoReturn:
    """Skip unavailable infrastructure, or fail in required mode."""

    message = f"Live integration dependency unavailable: {reason}"
    if _required():
        pytest.fail(
            f"{message}. RAWKI_INTEGRATION_REQUIRED=1 requires every selected "
            "integration dependency to be configured and reachable."
        )
    pytest.skip(message)


@pytest.fixture
def integration_unavailable() -> Callable[[str], NoReturn]:
    """Expose the optional-versus-required policy to end-to-end tests."""

    return unavailable
