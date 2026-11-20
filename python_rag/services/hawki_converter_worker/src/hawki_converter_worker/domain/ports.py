"""Converter-owned ports for artifacts and external conversion clients."""

from __future__ import annotations

from collections.abc import Callable
from pathlib import Path
from typing import Any, Protocol

from hawki_converter_worker.domain.models import ConverterEndpointConfig


class ConverterArtifactStorePort(Protocol):
    """Local artifact operations required by the converter workflow."""

    def resolve(self, location: str | Path) -> Path: ...

    def relative_path(self, location: str | Path, base: str | Path) -> str: ...

    def list_markdown(self, location: str | Path) -> list[str]: ...

    def read_bytes(self, location: str | Path) -> bytes: ...


class DirectExtractClientPort(Protocol):
    """Synchronous file extraction capability."""

    def extract(self, raw_file: Path) -> bytes: ...


class ExternalConverterClientPort(Protocol):
    """Asynchronous start-and-poll converter capability."""

    def start_and_wait(self, payload: dict[str, Any]) -> dict[str, Any]: ...


ArtifactStoreFactory = Callable[[str], ConverterArtifactStorePort]
DirectExtractClientFactory = Callable[
    [ConverterEndpointConfig], DirectExtractClientPort
]
ExternalConverterClientFactory = Callable[
    [ConverterEndpointConfig], ExternalConverterClientPort
]


__all__ = [
    "ArtifactStoreFactory",
    "ConverterArtifactStorePort",
    "DirectExtractClientFactory",
    "DirectExtractClientPort",
    "ExternalConverterClientFactory",
    "ExternalConverterClientPort",
]
