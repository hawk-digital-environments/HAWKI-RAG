from __future__ import annotations

import asyncio
from pathlib import Path
from types import SimpleNamespace
from typing import Any, Dict

from common.job_schema import JobSchema
from core.rag_service import RAGService
from pipeline.ingest_logic import ingest_documents

from .failure_classifier import PermanentJobError


class IngestionProcessor:
    """
    Runs one validated job through the existing ingest pipeline.
    """

    def __init__(self, *, rag_service: RAGService | None = None, public_dir: Path | None = None):
        self.rag_service = rag_service or RAGService()
        self.public_dir = public_dir or (Path(__file__).resolve().parents[2] / "public")

    def _get_provider(self, name: str):
        try:
            return self.rag_service.get_provider(name)
        except ValueError as exc:
            raise PermanentJobError(str(exc)) from exc

    @staticmethod
    def _to_ingest_body(job: JobSchema) -> Any:
        docs = [
            SimpleNamespace(
                id=doc.id,
                text=doc.text,
                payload=dict(doc.payload),
            )
            for doc in job.docs
        ]
        return SimpleNamespace(
            docs=docs,
            provider=job.provider,
            embedding_model=job.embedding_model,
            collection=job.collection,
            neo4j_database=job.neo4j_database,
            distance=job.distance,
            chunk_chars=job.chunk_chars,
            chunk_overlap=job.chunk_overlap,
            batch_size=job.batch_size,
            graph=job.graph,
            graph_engine=job.graph_engine,
            graph_only=job.graph_only,
            dry_run=job.dry_run,
            dry_include_graph=job.dry_include_graph,
        )

    async def process(self, job: JobSchema) -> Dict[str, Any]:
        body = self._to_ingest_body(job)
        return await asyncio.to_thread(
            ingest_documents,
            body,
            rag_service=self.rag_service,
            get_provider=self._get_provider,
            public_dir=self.public_dir,
        )

