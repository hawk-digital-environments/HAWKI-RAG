from __future__ import annotations

import os
import sys
import logging
from contextlib import contextmanager
from dataclasses import dataclass, field
from pathlib import Path
from types import SimpleNamespace
from typing import Any, Iterator
from urllib.parse import urlparse


ROOT = Path(__file__).resolve().parents[2]
PYTHON_RAG_ROOT = ROOT / "python_rag"
for candidate in (ROOT, PYTHON_RAG_ROOT):
    if str(candidate) not in sys.path:
        sys.path.insert(0, str(candidate))

from core.rag_service import RAGService  # type: ignore
from pipeline.ingest_logic import ingest_documents  # type: ignore
from pipeline.query_logic import query_documents  # type: ignore

logger = logging.getLogger(__name__)

@dataclass(slots=True)
class BackendRuntime:
    """Thin adapter that drives the existing python_rag backend from rag_test scripts."""
    config: dict[str, Any]
    rag_service: RAGService = field(default_factory=RAGService)

    def __post_init__(self) -> None:
        """Apply benchmark connection settings to the backend environment on startup."""
        logger.info("backend_runtime.__post_init__ start")
        try:
            self._apply_global_env()
            logger.info("backend_runtime.__post_init__ success")
        except Exception as exc:
            logger.exception("backend_runtime.__post_init__ failed error=%s", exc)
            raise

    def _apply_global_env(self) -> None:
        """Project benchmark config into env vars expected by the live backend modules."""
        logger.info("backend_runtime._apply_global_env start")
        try:
            parsed = urlparse(self.config["qdrant"]["url"])
            os.environ["QDRANT_SCHEME"] = parsed.scheme or "http"
            os.environ["QDRANT_HOST"] = parsed.hostname or "localhost"
            os.environ["QDRANT_PORT"] = str(parsed.port or 6333)
            if self.config["qdrant"].get("api_key"):
                os.environ["QDRANT_API_KEY"] = self.config["qdrant"]["api_key"]
            os.environ["QDRANT_TIMEOUT"] = str(self.config["qdrant"]["timeout_seconds"])

            neo4j = self.config.get("neo4j", {})
            os.environ["NEO4J_URI"] = neo4j.get("uri", "bolt://localhost:7687")
            os.environ["NEO4J_USER"] = neo4j.get("user", "neo4j")
            os.environ["NEO4J_PASSWORD"] = neo4j.get("password", "")
            os.environ["NEO4J_DATABASE"] = neo4j.get("database", "neo4j")

            reranker = self.config.get("reranker", {})
            os.environ["RERANKER_MODE"] = str(reranker.get("mode", "cosine"))
            os.environ["RERANKER_MIX_MODE"] = "true"
            os.environ["RERANKER_MIX_WEIGHT"] = "0.5"
            logger.info("backend_runtime._apply_global_env success qdrant=%s neo4j_db=%s", self.config["qdrant"]["url"], neo4j.get("database", "neo4j"))
        except Exception as exc:
            logger.exception("backend_runtime._apply_global_env failed error=%s", exc)
            raise

    @contextmanager
    def model_context(self, model_key: str) -> Iterator[dict[str, Any]]:
        """Temporarily switch backend env vars to one model-specific collection and embed model."""
        logger.info("backend_runtime.model_context enter model_key=%s", model_key)
        model = self.config["models"][model_key]
        previous = {key: os.environ.get(key) for key in ("OLLAMA_API_URL", "OLLAMA_EMBED_MODEL", "QDRANT_COLLECTION")}
        api_base = str(model.get("api_base", "")).rstrip("/")
        if api_base:
            if not api_base.endswith("/api"):
                api_base = api_base + "/api"
            os.environ["OLLAMA_API_URL"] = api_base
        os.environ["OLLAMA_EMBED_MODEL"] = str(model["model_name"])
        os.environ["QDRANT_COLLECTION"] = f"{self.config['collections']['prefix']}_{model['collection_suffix']}"
        try:
            yield model
            logger.info("backend_runtime.model_context success model_key=%s collection=%s", model_key, os.environ.get("QDRANT_COLLECTION"))
        except Exception as exc:
            logger.exception("backend_runtime.model_context failed model_key=%s error=%s", model_key, exc)
            raise
        finally:
            for key, value in previous.items():
                if value is None:
                    os.environ.pop(key, None)
                else:
                    os.environ[key] = value
            logger.info("backend_runtime.model_context exit model_key=%s", model_key)

    def get_provider(self, provider_name: str = "ollama") -> Any:
        """Resolve the backend embedding/chat provider through the existing RAGService."""
        logger.info("backend_runtime.get_provider start provider=%s", provider_name)
        try:
            provider = self.rag_service.get_provider(provider_name)
            logger.info("backend_runtime.get_provider success provider=%s", provider_name)
            return provider
        except Exception as exc:
            logger.exception("backend_runtime.get_provider failed provider=%s error=%s", provider_name, exc)
            raise

    def ingest_docs(
        self,
        *,
        docs: list[Any],
        model_key: str,
        collection_name: str,
        distance: str,
        graph: bool = False,
        graph_only: bool = False,
    ) -> dict[str, Any]:
        """Send benchmark documents through the current backend ingest pipeline."""
        logger.info(
            "backend_runtime.ingest_docs start model_key=%s collection=%s docs=%s graph=%s graph_only=%s",
            model_key,
            collection_name,
            len(docs),
            graph,
            graph_only,
        )
        try:
            with self.model_context(model_key) as model:
                body = SimpleNamespace(
                    docs=docs,
                    provider=str(model["provider"]),
                    embedding_model=str(model["model_name"]),
                    collection=collection_name,
                    neo4j_database=self.config["neo4j"]["database"],
                    distance=distance,
                    chunk_chars=int(self.config["benchmark"]["chunk_size"]),
                    chunk_overlap=int(self.config["benchmark"]["chunk_overlap"]),
                    batch_size=int(self.config["benchmark"]["embed_batch_size"]),
                    graph=graph,
                    graph_engine="raganything",
                    graph_only=graph_only,
                    dry_run=False,
                    dry_include_graph=False,
                )
                public_dir = ROOT / "public"
                public_dir.mkdir(parents=True, exist_ok=True)
                result = ingest_documents(
                    body,
                    rag_service=self.rag_service,
                    get_provider=self.rag_service.get_provider,
                    public_dir=public_dir,
                )
                logger.info(
                    "backend_runtime.ingest_docs success model_key=%s collection=%s chunks=%s",
                    model_key,
                    collection_name,
                    result.get("summary", {}).get("total_chunks"),
                )
                return result
        except Exception as exc:
            logger.exception(
                "backend_runtime.ingest_docs failed model_key=%s collection=%s error=%s",
                model_key,
                collection_name,
                exc,
            )
            raise

    def run_query(
        self,
        *,
        model_key: str,
        query_text: str,
        top_k: int,
        fast_mode: bool,
        smart_lookup: bool,
    ) -> dict[str, Any]:
        """Execute one benchmark query through the current backend query pipeline."""
        logger.info(
            "backend_runtime.run_query start model_key=%s top_k=%s fast_mode=%s smart_lookup=%s query=%s",
            model_key,
            top_k,
            fast_mode,
            smart_lookup,
            query_text[:120],
        )
        try:
            with self.model_context(model_key) as model:
                body = SimpleNamespace(
                    query=query_text,
                    top_k=top_k,
                    provider=str(model["provider"]),
                    filters={},
                    generate=False,
                    is_optimized=False,
                    fast_mode=fast_mode,
                    smart_lookup=smart_lookup,
                    structural_hops=None if not fast_mode else 0,
                    preferred_tags=None,
                    reranker=str(self.config["reranker"]["mode"]),
                    rerank_top_n=max(20, top_k),
                    mix_mode=True,
                    mix_weight=0.5,
                )
                result = query_documents(
                    body,
                    rag_service=self.rag_service,
                    get_provider=self.rag_service.get_provider,
                )
                logger.info(
                    "backend_runtime.run_query success model_key=%s hits=%s",
                    model_key,
                    result.get("count"),
                )
                return result
        except Exception as exc:
            logger.exception("backend_runtime.run_query failed model_key=%s error=%s", model_key, exc)
            raise
