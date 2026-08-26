"""Concrete dependency composition for the converter process."""

from hawki_artifact_store.local import LocalArtifactStore

from hawki_converter_worker.adapters.external_converter_client import (
    ExternalConverterJobClient,
)
from hawki_converter_worker.adapters.direct_extract_client import (
    RequestsDirectExtractClient,
)
from hawki_converter_worker.application.dependencies import ConversionDependencies


def build_conversion_dependencies() -> ConversionDependencies:
    """Bind converter ports to local artifacts and production HTTP adapters."""

    return ConversionDependencies(
        artifact_store_factory=LocalArtifactStore,
        direct_extract_client_factory=RequestsDirectExtractClient,
        external_converter_client_factory=ExternalConverterJobClient,
    )


__all__ = ["build_conversion_dependencies"]
