"""Bridge HTTP error handling."""

from hawki_bridge.http.errors.handlers import install_exception_handlers
from hawki_bridge.http.errors.query import query_error_to_http_exception

__all__ = ["install_exception_handlers", "query_error_to_http_exception"]
