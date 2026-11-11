"""Shared environment isolation for the dataset authority tests."""

from __future__ import annotations

import os

import pytest


@pytest.fixture(autouse=True)
def clean_runtime_environment(monkeypatch):
    """Keep the dataset selector and runtime settings out of unit tests."""

    for key in tuple(os.environ):
        if key.startswith(("HAWKI_BACKEND_", "HAWKI_WORKFLOW_", "TEMPORAL_")):
            monkeypatch.delenv(key)
    yield
