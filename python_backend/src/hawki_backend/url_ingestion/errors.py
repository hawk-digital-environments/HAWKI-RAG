"""Safe errors at the URL ingestion boundary."""


class UrlIngestionError(Exception):
    def __init__(self, status: int, message: str) -> None:
        self.status = status
        self.message = message
        super().__init__(message)
