"""External capabilities required by source conversion."""

from dataclasses import dataclass

from hawki_converter_worker.domain.ports import (
    ArtifactStoreFactory,
    DirectExtractClientFactory,
    ExternalConverterClientFactory,
)


@dataclass(frozen=True, slots=True)
class ConversionDependencies:
    """I/O collaborators supplied once by the converter composition root."""

    artifact_store_factory: ArtifactStoreFactory
    direct_extract_client_factory: DirectExtractClientFactory
    external_converter_client_factory: ExternalConverterClientFactory


__all__ = ["ConversionDependencies"]
