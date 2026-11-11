"""Stable failures of the dataset authority boundary.

Every failure here is a public answer, not an infrastructure detail. The
messages are generic and never contain a driver message, a physical target,
a tenant or a stored token.
"""

from __future__ import annotations


class DatasetError(Exception):
    """A refused dataset operation with a stable public shape."""

    status_code = 500
    error_code = "dataset_operation_failed"
    message = "The dataset operation could not be completed."

    def payload(self) -> dict[str, str]:
        """Return the public error body."""

        return {"message": self.message, "error": self.error_code}


class InvalidDatasetRequestError(DatasetError):
    """The request is not well formed."""

    status_code = 422
    error_code = "invalid_dataset_request"
    message = "The given data was invalid."


class DatasetNotFoundError(DatasetError):
    """The dataset does not exist, belongs to another tenant or is inactive."""

    status_code = 404
    error_code = "dataset_not_found"
    message = "The requested dataset is not available."


class DatasetNotReadyError(DatasetError):
    """The active dataset has no usable physical scope yet."""

    status_code = 409
    error_code = "dataset_not_ready"
    message = "The requested dataset is not ready for querying."


class DatasetCreationConflictError(DatasetError):
    """The identity exists with different authoritative metadata."""

    status_code = 409
    error_code = "dataset_creation_conflict"

    def __init__(self, dataset_id: str, field: str) -> None:
        self.message = (
            f"Dataset [{dataset_id}] already exists with a different [{field}]."
        )
        super().__init__(self.message)


class GrantTokenRequiredError(DatasetError):
    """A first ingest self-grant presented no one-time token."""

    status_code = 403
    error_code = "grant_token_required"
    message = "A one-time dataset grant token is required to self-grant ingest access."


class GrantTokenInvalidError(DatasetError):
    """A token is unknown, expired, consumed or bound to another dataset."""

    status_code = 403
    error_code = "grant_token_invalid"
    message = "The provided dataset grant token is invalid, expired, or already used."


class DatasetUnavailableError(DatasetError):
    """The canonical dataset store could not be reached."""

    status_code = 503
    error_code = "dataset_unavailable"
    message = "The dataset service is not available."


__all__ = [
    "DatasetCreationConflictError",
    "DatasetError",
    "DatasetNotFoundError",
    "DatasetNotReadyError",
    "DatasetUnavailableError",
    "GrantTokenInvalidError",
    "GrantTokenRequiredError",
    "InvalidDatasetRequestError",
]
