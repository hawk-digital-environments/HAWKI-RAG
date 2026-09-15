"""application entry point for the backend."""

from fastapi import FastAPI

from hawki_backend.api.v1.health import router as health_router


def create_app() -> FastAPI:
    """Create an application with a health endpoint."""
    application = FastAPI(
        title="HAWKI Backend",
        version="0.1.0",
        openapi_url=None,
        docs_url=None,
        redoc_url=None,
    )
    application.include_router(health_router, prefix="/v1")
    return application


app = create_app()
