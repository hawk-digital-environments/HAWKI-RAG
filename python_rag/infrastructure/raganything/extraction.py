from __future__ import annotations

import logging
import time
from typing import Any, List, Tuple

from infrastructure.raganything.raganything_client import RagAnythingGraphService
from infrastructure.raganything.text import clean_graph_text

logger = logging.getLogger(__name__)


def _perf_log(msg: str, graph_perf_log: bool, *args: Any) -> None:
    if graph_perf_log:
        logger.info(msg, *args)


def extract_triplets_with_graph_service(
    graph_service: RagAnythingGraphService,
    text: str,
    engine: str | None,
    *,
    provider: Any | None,
    chunks: list[str] | None,
    doc_id: str | None,
    file_path: str | None,
    neo4j_database: str | None,
    graph_perf_log: bool,
) -> list[tuple[str, str, str]]:
    fn_start = time.perf_counter()
    _perf_log(
        "perf:graph core.rag_service.extract_triplets start engine=%s doc_id=%s chunks=%s text_chars=%s",
        graph_perf_log,
        engine,
        doc_id or "-",
        0 if chunks is None else len(chunks),
        len(text or ""),
    )

    mode = (engine or "raganything").strip().lower()
    if mode != "raganything":
        logger.warning("Graph engine '%s' requested; enforcing raganything.", mode)

    # Keep a lightweight cleanup pass for noisy markdown/table rows before handing text
    # to the official RAG-Anything content ingestion path.
    clean_input_start = time.perf_counter()
    cleaned_text = clean_graph_text(text)
    cleaned_chunks = None
    if chunks is not None:
        cleaned_chunks = []
        for ch in chunks:
            cleaned = clean_graph_text(ch)
            cleaned_chunks.append(cleaned if cleaned.strip() else ch)
    _perf_log(
        "perf:graph core.rag_service.extract_triplets step=clean_input chunks=%s ms=%.2f",
        graph_perf_log,
        0 if chunks is None else len(chunks),
        (time.perf_counter() - clean_input_start) * 1000,
    )

    rag_start = time.perf_counter()
    trips = graph_service.extract_triplets(
        cleaned_text if cleaned_text.strip() else text,
        provider=provider,
        chunks=cleaned_chunks if cleaned_chunks is not None else chunks,
        doc_id=doc_id,
        file_path=file_path,
        neo4j_database=neo4j_database,
    )
    _perf_log(
        "perf:graph core.rag_service.extract_triplets step=raganything_insert_export triplets=%s ms=%.2f",
        graph_perf_log,
        len(trips),
        (time.perf_counter() - rag_start) * 1000,
    )
    logger.info("graph:extract_triplets raganything-kg count=%s", len(trips))
    _perf_log(
        "perf:graph core.rag_service.extract_triplets done path=%s triplets=%s ms=%.2f",
        graph_perf_log,
        "raganything_official_kg",
        len(trips),
        (time.perf_counter() - fn_start) * 1000,
    )
    return trips
