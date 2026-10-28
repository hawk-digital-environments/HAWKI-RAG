"""The persistence layer of the backend.

This package holds the adapter that makes the dataset grant reader real. It
is deliberately kept apart from `hawki_backend.authorization`: the
authorization domain is pure and imports no database driver, and this
package imports the domain but is never imported by it.
"""

from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import (
    ACTIVE_STATUS,
    SCHEMA_NAME,
    dataset_grants,
    datasets,
    metadata,
)

__all__ = [
    "ACTIVE_STATUS",
    "SCHEMA_NAME",
    "DatabaseGrantReader",
    "build_engine",
    "dataset_grants",
    "datasets",
    "metadata",
]
