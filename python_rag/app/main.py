########################################### libs and bibz #####################################
import logging
import os
from pathlib import Path
from typing import List, Dict, Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from core.rag_service import RAGService
from vectorstore.qdrant_http import QdrantHTTP

from .ingest import ingest_documents, delete_document
from pipeline.query_logic import query_documents
from graph.graph_text import graph_from_text

########################################### CONFIG #####################################

logger = logging.getLogger(__name__)
BASE_DIR = Path(__file__).resolve().parent
PYTHON_RAG_ROOT = BASE_DIR.parent
PROJECT_ROOT = PYTHON_RAG_ROOT.parent
PUBLIC_DIR = PROJECT_ROOT / "public"
app = FastAPI(title="LightRAG Service", version="0.2.0")
rag_service = RAGService()

###################################### PROVIDER CONFIG ###############################

def get_provider(name: str):
    try:
        return rag_service.get_provider(name)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


###################################### REQUEST MODELS ###############################

class IngestDoc(BaseModel):
    id: str | int
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)


class IngestRequest(BaseModel):
    docs: List[IngestDoc]
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    embedding_model: str | None = None
    collection: str | None = None
    distance: str = Field(default=os.environ.get("QDRANT_DISTANCE", "Cosine"))
    chunk_chars: int = 3200
    chunk_overlap: int = 250
    batch_size: int = Field(default=int(os.environ.get("INGEST_BATCH_SIZE", "64")))
    graph: bool = False
    graph_engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))
    graph_only: bool = False
    dry_run: bool = False
    dry_include_graph: bool = False


class QueryRequest(BaseModel):
    query: str
    top_k: int = 5
    provider: str = Field(default=os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
    filters: Dict[str, Any] = Field(default_factory=dict)
    generate: bool = True
    is_optimized: bool = False
    fast_mode: bool = False
    smart_lookup: bool = False
    structural_hops: int | None = None
    preferred_tags: List[str] | None = None
    # Reranker options: none | cosine | external | jina
    reranker: str = Field(default=os.environ.get("RERANKER_MODE", "none"))
    rerank_top_n: int = 20
    # Mix mode: blend original vector score with reranker score
    mix_mode: bool = Field(default=bool(os.environ.get("RERANKER_MIX_MODE", "true").lower() in ("1", "true", "yes")))
    mix_weight: float = Field(default=float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5)))  # 0..1, weight on original score


class GraphRequest(BaseModel):
    text: str
    engine: str = Field(default=os.environ.get("GRAPH_ENGINE", "raganything"))


class DocumentUpsertRequest(BaseModel):
    text: str
    payload: Dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    collection: str | None = None
    distance: str | None = None
    chunk_chars: int | None = None
    chunk_overlap: int | None = None
    graph: bool = False
    graph_engine: str | None = None


################################## ENDPOINTS #################################

@app.get("/health")
def health():
    logger.debug("health:ok")
    return {"ok": True}


@app.get("/config")
def config():
    # Active provider and embedding model
    provider_name = os.environ.get("RAG_DEFAULT_PROVIDER", "ollama").strip()
    try:
        provider = get_provider(provider_name)
        embed_model = getattr(provider, "embed_model", None)
    except Exception:
        embed_model = None

    # Reranker settings
    reranker_mode = os.environ.get("RERANKER_MODE", "none")
    mix_mode = str(os.environ.get("RERANKER_MIX_MODE", "true")).lower() in ("1", "true", "yes")
    try:
        mix_weight = float(os.environ.get("RERANKER_MIX_WEIGHT", 0.5))
    except Exception:
        mix_weight = 0.5
    jina_model = os.environ.get("JINA_RERANKER_MODEL", "jina-reranker-v2-base-multilingual")
    external_url = os.environ.get("RERANKER_API_URL", "")

    # Qdrant collection and vector size
    q = QdrantHTTP()
    qdrant_collection = q.collection
    vector_size = q.get_vector_size()

    logger.info("config:provider=%s qdrant_collection=%s", provider_name, qdrant_collection)
    return {
        "provider": provider_name,
        "embedding_model": embed_model,
        "qdrant_collection": qdrant_collection,
        "qdrant_vector_size": vector_size,
        "reranker": {
            "mode": reranker_mode,
            "mix_mode": mix_mode,
            "mix_weight": mix_weight,
            "jina_model": jina_model,
            "external_url": external_url,
        },
    }


@app.post("/ingest")
def ingest(body: IngestRequest):
    logger.info("api:ingest docs=%s graph=%s", len(body.docs), body.graph)
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
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    if not (body.text and body.text.strip()):
        raise HTTPException(status_code=400, detail="text is required to replace a document")

    deletion = delete_document(doc_id)

    ingest_doc = IngestDoc(id=doc_id, text=body.text, payload=body.payload)
    ingest_request = IngestRequest(
        docs=[ingest_doc],
        provider=body.provider or os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"),
        collection=body.collection,
        distance=body.distance or os.environ.get("QDRANT_DISTANCE", "Cosine"),
        chunk_chars=body.chunk_chars or 3200,
        chunk_overlap=body.chunk_overlap or 250,
        graph=body.graph,
        graph_engine=body.graph_engine or os.environ.get("GRAPH_ENGINE", "fallback"),
    )

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
    return graph_from_text(body, rag_service=rag_service)
