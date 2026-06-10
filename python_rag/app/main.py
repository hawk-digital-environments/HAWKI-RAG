########################################### libs and bibz #####################################
import os
from pathlib import Path

from fastapi import FastAPI

from core.rag_service import RAGService
from vectorstore.qdrant_http import QdrantHTTP

from .config_response import build_config_response
from .documents import build_replacement_ingest_request
from .ingest import ingest_documents, delete_document
from .dependencies import get_provider_or_400
from .logging_config import configure_app_logging
from .runtime import log_gpu_status
from .schemas import DocumentUpsertRequest, GraphRequest, IngestRequest, QueryRequest
from pipeline.query_logic import query_documents
from graph.graph_text import graph_from_text

########################################### CONFIG #####################################

logger, GRAPH_DEBUG, GRAPH_DEBUG_LOG = configure_app_logging(os.environ, logger_name=__name__)
BASE_DIR = Path(__file__).resolve().parent
PYTHON_RAG_ROOT = BASE_DIR.parent
PROJECT_ROOT = PYTHON_RAG_ROOT.parent
PUBLIC_DIR = Path(os.environ.get("HAWKI_RAG_PUBLIC_DIR", str(PROJECT_ROOT / "public")))
app = FastAPI(title="LightRAG Service", version="0.2.0")
rag_service = RAGService()

###################################### PROVIDER CONFIG ###############################

def get_provider(name: str):
    return get_provider_or_400(rag_service, name)


def _log_gpu_status(context: str) -> None:
    log_gpu_status(logger, context)

################################## ENDPOINTS #################################

@app.get("/health")
def health():
    logger.debug("health:ok")
    runtime = {}
    try:
        runtime = rag_service.graph_runtime_summary()
    except Exception as exc:
        runtime = {"error": str(exc)}
    return {"ok": True, "runtime": runtime}


@app.get("/config")
def config():
    response = build_config_response(get_provider=get_provider, qdrant_factory=QdrantHTTP)
    logger.info("config:provider=%s qdrant_collection=%s", response["provider"], response["qdrant_collection"])
    return response


@app.post("/ingest")
def ingest(body: IngestRequest):
    logger.info("api:ingest docs=%s graph=%s", len(body.docs), body.graph)
    if body.graph:
        _log_gpu_status("ingest_graph")
    return ingest_documents(
        body,
        rag_service=rag_service,
        get_provider=get_provider,
        public_dir=PUBLIC_DIR,
    )


@app.delete("/documents/{doc_id}")
def delete_document_endpoint(doc_id: str):
    logger.info("api:delete doc_id=%s", doc_id)
    result = delete_document(doc_id)
    return {"ok": True, "doc_id": str(doc_id), "qdrant": result["qdrant"], "neo4j": result["neo4j"]}


@app.put("/documents/{doc_id}")
def replace_document(doc_id: str, body: DocumentUpsertRequest):
    deletion = delete_document(doc_id)
    ingest_request = build_replacement_ingest_request(doc_id, body)

    ingest_response = ingest_documents(
        ingest_request,
        rag_service=rag_service,
        get_provider=get_provider,
        public_dir=PUBLIC_DIR,
    )
    ingest_response["replaced_doc_id"] = str(doc_id)
    ingest_response["deleted"] = deletion
    logger.info("api:replace doc_id=%s", doc_id)
    return ingest_response


@app.post("/query")
def query(body: QueryRequest):
    logger.info("api:query top_k=%s fast=%s smart=%s", body.top_k, body.fast_mode, body.smart_lookup)
    return query_documents(body, rag_service=rag_service, get_provider=get_provider)


@app.post("/graph/from-text")
def graph_from_text_endpoint(body: GraphRequest):
    logger.info("api:graph_from_text chars=%s", len(body.text or ""))
    _log_gpu_status("graph_from_text")
    return graph_from_text(body, rag_service=rag_service)


@app.post("/graph/cache/clear")
def clear_graph_cache_endpoint():
    logger.info("api:graph_cache_clear")
    return rag_service.clear_graph_cache()
