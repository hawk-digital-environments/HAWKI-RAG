"""HTTP adapter for the synchronous converter extract endpoint."""

from __future__ import annotations

from pathlib import Path
import time
from urllib.parse import urljoin

import requests

from hawki_converter_worker.domain.errors import (
    DirectExtractUnsupportedFileError,
    NonRetryableConverterResponseError,
    RetryableConverterRequestError,
)
from hawki_converter_worker.domain.models import ConverterEndpointConfig


class RequestsDirectExtractClient:
    """Upload one file with bounded retries and return its ZIP response."""

    def __init__(self, config: ConverterEndpointConfig) -> None:
        self.url = urljoin(
            config.base_url.rstrip("/") + "/",
            config.start_path.lstrip("/"),
        )
        self.headers = (
            {"Authorization": f"Bearer {config.token}"} if config.token else {}
        )
        self.timeout_seconds = config.timeout_seconds
        self.retry_attempts = max(1, config.retry_attempts)

    def extract(self, raw_file: Path) -> bytes:
        """Return a successful converter archive or raise a retry-classified error."""

        last_error: Exception | None = None
        for attempt in range(1, self.retry_attempts + 1):
            try:
                with raw_file.open("rb") as handle:
                    response = requests.post(
                        self.url,
                        headers=self.headers,
                        files={"file": (raw_file.name, handle)},
                        timeout=self.timeout_seconds,
                    )

                if not response.ok:
                    self._raise_response_error(response, raw_file.name)
                return response.content
            except (
                DirectExtractUnsupportedFileError,
                NonRetryableConverterResponseError,
            ):
                raise
            except (
                requests.Timeout,
                requests.ConnectionError,
                RetryableConverterRequestError,
            ) as exc:
                last_error = exc
                if attempt >= self.retry_attempts:
                    break
                time.sleep(min(2 ** (attempt - 1), 10))

        raise RetryableConverterRequestError(
            f"Converter extract request failed for {raw_file.name}: {last_error}"
        ) from last_error

    @staticmethod
    def _raise_response_error(response: requests.Response, filename: str) -> None:
        error = _response_error(response)
        if _is_unsupported_response(response.status_code, error):
            raise DirectExtractUnsupportedFileError(
                f"Direct converter does not support {filename}: {error}"
            )

        message = f"Converter request failed [{response.status_code}]: {error}"
        if _is_retryable_status(response.status_code):
            raise RetryableConverterRequestError(message)
        raise NonRetryableConverterResponseError(message)


def _response_error(response: requests.Response) -> str:
    try:
        payload = response.json()
    except ValueError:
        return response.text[:500]
    return str(payload)[:500]


def _is_unsupported_response(status_code: int, error: str) -> bool:
    return status_code == 400 and "unsupported file type" in error.lower()


def _is_retryable_status(status_code: int) -> bool:
    return status_code >= 500 or status_code in {408, 429}


__all__ = ["RequestsDirectExtractClient"]
