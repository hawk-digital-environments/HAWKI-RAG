"""Send crawler operations through the Temporal scraper worker."""

import re

from hawki_workflow_control.temporal_client import (
    TemporalControlClient,
    TemporalUnavailable,
)

from .errors import UrlIngestionError


class ExternalCrawlerBoundary:
    def __init__(self, temporal: TemporalControlClient | None = None) -> None:
        self.temporal = temporal

    async def run(self, action: str, scope: dict | None = None) -> dict:
        if self.temporal is None:
            raise UrlIngestionError(503, "Scraper worker is not configured.")
        try:
            result = await self.temporal.crawler_operation(action, scope)
            if not isinstance(result, dict):
                raise TemporalUnavailable()
            return result
        except TemporalUnavailable:
            raise UrlIngestionError(
                503, "Scraper worker or its external crawler is unavailable."
            ) from None

    async def require_contract(self) -> str:
        result = await self.run("probe")
        endpoint_id = result.get("endpoint_id")
        if not isinstance(endpoint_id, str) or not re.fullmatch(
            r"[0-9a-f]{64}", endpoint_id
        ):
            raise UrlIngestionError(
                503, "Scraper worker returned an invalid endpoint identity."
            )
        return endpoint_id
