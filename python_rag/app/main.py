from __future__ import annotations

import os
from pathlib import Path

from fastapi import FastAPI

from core.rag_service import RAGService
from vectorstore.qdrant_http import QdrantHTTP

from .logging_config import configure_app_logging
from .runtime import log_gpu_status
from .routers import build_config_router, build_graph_router, build_health_router, build_ingest_router, build_query_router
from .dependencies import get_provider_or_400


logger, GRAPH_DEBUG, GRAPH_DEBUG_LOG = configure_app_logging(os.environ, logger_name=__name__)
BASE_DIR = Path(__file__).resolve().parent
PYTHON_RAG_ROOT = BASE_DIR.parent
PROJECT_ROOT = PYTHON_RAG_ROOT.parent
PUBLIC_DIR = Path(os.environ.get("HAWKI_RAG_PUBLIC_DIR", str(PROJECT_ROOT / "public")))

app = FastAPI(title="LightRAG Service", version="0.2.0")
rag_service = RAGService()


def _log_graph_status(context: str) -> None:
    log_gpu_status(logger, context)


def _runtime_summary() -> dict[str, object]:
    return rag_service.graph_runtime_summary()


def _get_provider(name: str):
    return get_provider_or_400(rag_service, name)


app.include_router(
    build_health_router(
        logger=logger,
        runtime_summary=_runtime_summary,
    )
)
app.include_router(
    build_config_router(
        logger=logger,
        get_provider=_get_provider,
        qdrant_factory=QdrantHTTP,
    )
)
app.include_router(
    build_ingest_router(
        logger=logger,
        rag_service=rag_service,
        public_dir=PUBLIC_DIR,
        log_graph_status=_log_graph_status,
    )
)
app.include_router(
    build_query_router(
        logger=logger,
        rag_service=rag_service,
    )
)
app.include_router(
    build_graph_router(
        logger=logger,
        rag_service=rag_service,
        log_graph_status=_log_graph_status,
    )
)
