"""Server-owned query scope and public query boundary helpers.

The query package owns the migration-time dataset scope registry, the public
request shape, response sanitization and the stable query error mapping. It
does not authenticate or authorize callers; those boundaries are reused from
``hawki_backend.auth`` and ``hawki_backend.authorization``.
"""

from hawki_backend.query.errors import (
    authorization_unavailable,
    dataset_not_found,
    dataset_not_ready,
    query_failed,
    query_unavailable,
    retrieval_error_to_http_exception,
)
from hawki_backend.query.response import sanitize_query_response
from hawki_backend.query.settings import (
    DatasetScope,
    ProviderModels,
    QueryConfigurationError,
    QuerySettings,
    load_query_settings,
)

__all__ = [
    "DatasetScope",
    "ProviderModels",
    "QueryConfigurationError",
    "QuerySettings",
    "authorization_unavailable",
    "dataset_not_found",
    "dataset_not_ready",
    "load_query_settings",
    "query_failed",
    "query_unavailable",
    "retrieval_error_to_http_exception",
    "sanitize_query_response",
]
