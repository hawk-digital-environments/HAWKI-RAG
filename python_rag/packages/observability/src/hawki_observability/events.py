"""Stable observability event names used across service boundaries."""

API_REQUEST_START = "api.request_start"
API_REQUEST_END = "api.request_end"
API_REQUEST_ERROR = "api.request_error"
STARTUP_CHECK = "startup.check"
STARTUP_CHECK_RETRY = "startup.check_retry"
QDRANT_ADAPTER_REQUEST = "adapter.qdrant.request"
NEO4J_ADAPTER_QUERY = "adapter.neo4j.query"

__all__ = [
    "API_REQUEST_END",
    "API_REQUEST_ERROR",
    "API_REQUEST_START",
    "NEO4J_ADAPTER_QUERY",
    "QDRANT_ADAPTER_REQUEST",
    "STARTUP_CHECK",
    "STARTUP_CHECK_RETRY",
]
