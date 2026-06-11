"""Application entrypoint for the Python RAG FastAPI service."""

from __future__ import annotations

from api.settings import load_app_settings
from .factory import build_app


app = build_app(
    app_settings=load_app_settings(),
    logger_name=__name__,
)
