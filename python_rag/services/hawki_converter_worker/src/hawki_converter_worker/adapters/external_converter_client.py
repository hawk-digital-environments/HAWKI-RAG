"""External converter job client adapter."""

from hawki_external_jobs import ExternalJobClient

from hawki_converter_worker.domain.models import ConverterEndpointConfig


class ExternalConverterJobClient(ExternalJobClient):
    """Asynchronous converter adapter built from the typed endpoint config."""

    def __init__(self, config: ConverterEndpointConfig) -> None:
        super().__init__(**config.external_job_options())


__all__ = ["ExternalConverterJobClient"]
