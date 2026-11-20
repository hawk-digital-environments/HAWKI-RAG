"""Failures with stable retry meaning for source conversion."""


class DirectExtractUnsupportedFileError(RuntimeError):
    """The direct converter rejected a file the indexer may parse natively."""


class NonRetryableConverterResponseError(RuntimeError):
    """The converter permanently rejected a valid HTTP request."""


class RetryableConverterRequestError(RuntimeError):
    """A transient converter request failed after its bounded retries."""


__all__ = [
    "DirectExtractUnsupportedFileError",
    "NonRetryableConverterResponseError",
    "RetryableConverterRequestError",
]
