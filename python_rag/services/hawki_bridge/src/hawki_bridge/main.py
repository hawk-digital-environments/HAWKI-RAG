"""ASGI entrypoint for the HAWKI RAG bridge."""

from hawki_bridge.factory import build_app
from hawki_bridge.settings import load_settings

app = build_app(settings=load_settings(), logger_name=__name__)


def run() -> None:
    import uvicorn

    uvicorn.run("hawki_bridge.main:app", host="0.0.0.0", port=8000)


__all__ = ["app", "run"]
