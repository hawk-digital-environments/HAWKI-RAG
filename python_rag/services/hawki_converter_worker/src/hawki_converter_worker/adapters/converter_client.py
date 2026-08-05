"""External converter job client adapter."""

from hawki_worker_runtime.external_jobs import ExternalJobClient


class ConverterClient(ExternalJobClient):
    """Type-named adapter for the converter start/status API."""


__all__ = ["ConverterClient"]
