"""Authenticated proxy for the scraper worker's saved task catalog."""

from fastapi import HTTPException, Request

from .api import Principal, router, service
from .errors import UrlIngestionError


@router.get("/scraper/tasks", include_in_schema=False)
async def catalog(request: Request, principal: Principal):
    try:
        return await service(request).crawler.run("catalog")
    except UrlIngestionError:
        raise HTTPException(503, "crawler_catalog_unavailable") from None
