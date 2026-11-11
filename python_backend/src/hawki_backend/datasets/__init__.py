"""The authoritative dataset management surface."""

from .api import DATASET_ADMIN_SCOPE, router
from .catalog import QdrantCatalog
from .cleanup import DatasetCleanupCoordinator, DatasetSourceCleanup
from .errors import (
    DatasetCreationConflictError,
    DatasetError,
    DatasetNotFoundError,
    DatasetNotReadyError,
    DatasetUnavailableError,
    GrantTokenInvalidError,
    GrantTokenRequiredError,
    InvalidDatasetRequestError,
)
from .service import DatasetCreation, DatasetDeletion, DatasetService, physical_scope

__all__ = [
    "DATASET_ADMIN_SCOPE",
    "DatasetCleanupCoordinator",
    "DatasetCreation",
    "DatasetCreationConflictError",
    "DatasetDeletion",
    "DatasetError",
    "DatasetNotFoundError",
    "DatasetNotReadyError",
    "DatasetService",
    "DatasetSourceCleanup",
    "DatasetUnavailableError",
    "GrantTokenInvalidError",
    "GrantTokenRequiredError",
    "InvalidDatasetRequestError",
    "QdrantCatalog",
    "physical_scope",
    "router",
]
