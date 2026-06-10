"""RAG-Anything extraction orchestration helpers."""

from __future__ import annotations

import hashlib
import logging
import re
import time
from pathlib import Path
from typing import Any, Dict, List

from core.graph.edge_parser import (
    triplets_from_raganything_edges as _triplets_from_raganything_edges,
)
from core.graph.fallback_parser import (
    parse_raganything_llm_cache,
)
from core.graph.raganything_runtime import clear_lightrag_temp_graph
from core.graph.raganything_utils import dedupe_triplets

logger = logging.getLogger(__name__)


def graph_content_list_from_input(text: str, chunks: List[str] | None) -> List[Dict[str, Any]]:
    parts = chunks if chunks is not None else [text]
    out: List[Dict[str, Any]] = []
    for idx, part in enumerate(parts):
        if not isinstance(part, str):
            continue
        value = part.strip()
        if not value:
            continue
        out.append({"type": "text", "text": value, "page_idx": idx})
    return out


def stable_raganything_doc_id(doc_id: str | None, file_path: str | None, content_list: List[Dict[str, Any]]) -> str:
    content_text = "\n".join(str(item.get("text") or "") for item in content_list if isinstance(item, dict))
    digest = hashlib.sha1(content_text.encode("utf-8", errors="ignore")).hexdigest()[:16]
    prefix = str(doc_id or file_path or "graph_doc")
    return f"{prefix}:{digest}"


def raganything_extraction_doc_id(doc_id: str | None, file_path: str | None, content_list: List[Dict[str, Any]]) -> str:
    stable_id = stable_raganything_doc_id(doc_id, file_path, content_list)
    return f"{stable_id}:extract:{time.time_ns()}"


def raganything_file_ref(doc_id: str | None, file_path: str | None) -> str:
    if not file_path:
        return f"inline://{doc_id or 'graph_text'}"

    raw_path = str(file_path)
    source = Path(raw_path)
    safe_doc_id = re.sub(r"[^A-Za-z0-9_.-]+", "_", str(doc_id or "graph_doc")).strip("._-") or "graph_doc"
    unique_name = f"{safe_doc_id}__{source.name or 'content.md'}"

    if source.parent and str(source.parent) not in ("", "."):
        return str(source.with_name(unique_name))
    return unique_name


def _clear_temp_graph(
    neo4j_database: str | None,
    neo4j_uri: str | None = None,
    neo4j_user: str | None = None,
    neo4j_password: str | None = None,
) -> None:
    clear_lightrag_temp_graph(
        neo4j_database,
        neo4j_uri=neo4j_uri or "",
        neo4j_user=neo4j_user or "",
        neo4j_password=neo4j_password or "",
    )


async def extract_triplets_from_graph_client(
    *,
    client: Any,
    content_list: List[Dict[str, Any]],
    doc_id: str | None,
    file_path: str | None,
    file_ref: str,
    working_dir: Path,
    settings: Any,
    debug: bool,
    logger_obj: logging.Logger | None = None,
    neo4j_database: str | None = None,
    neo4j_uri: str | None = None,
    neo4j_user: str | None = None,
    neo4j_password: str | None = None,
    scrub_raganything_kv_graph_junk: Any,
) -> List[tuple[str, str, str]]:
    log = logger_obj or logger
    source_file_ref = str(file_path or f"inline://{doc_id or 'graph_text'}")
    rag_doc_id = raganything_extraction_doc_id(doc_id, file_ref, content_list)
    log.info(
        "RAG-Anything graph insert requested doc_id=%s rag_doc_id=%s file=%s source_file=%s blocks=%s chars=%s",
        doc_id or "-",
        rag_doc_id,
        file_ref,
        source_file_ref,
        len(content_list),
        sum(len(str(item.get('text') or '')) for item in content_list),
    )
    created_floor = int(time.time())

    async def _insert_and_export() -> List[tuple[str, str, str]]:
        _clear_temp_graph(
            neo4j_database,
            neo4j_uri=neo4j_uri,
            neo4j_user=neo4j_user,
            neo4j_password=neo4j_password,
        )
        text_only = bool(content_list) and all(
            isinstance(item, dict) and str(item.get("type") or "").lower() == "text"
            for item in content_list
        )
        if text_only:
            try:
                if hasattr(client, "_parser_installation_checked"):
                    setattr(client, "_parser_installation_checked", True)
            except Exception:
                pass

        try:
            if text_only:
                init_result = await client._ensure_lightrag_initialized()
                if not init_result or not init_result.get("success"):
                    raise RuntimeError(
                        f"LightRAG initialization failed: {(init_result or {}).get('error', 'unknown error')}"
                    )
                text_content = "\n\n".join(
                    str(item.get("text") or "").strip()
                    for item in content_list
                    if isinstance(item, dict) and str(item.get("text") or "").strip()
                )
                await client.lightrag.ainsert(
                    input=text_content,
                    ids=rag_doc_id,
                    file_paths=file_ref,
                )
            else:
                await client.insert_content_list(
                    content_list,
                    file_path=file_ref,
                    doc_id=rag_doc_id,
                    display_stats=debug,
                )
        except Exception as exc:
            log.warning("RAG-Anything graph insert failed: %s", exc)
            return []

        scrub_raganything_kv_graph_junk(rag_doc_id=rag_doc_id)

        lightrag_obj = getattr(client, "lightrag", None)
        graph_obj = getattr(lightrag_obj, "chunk_entity_relation_graph", None)
        if graph_obj is None:
            log.warning("RAG-Anything graph storage is unavailable after insert.")
            _clear_temp_graph(
                neo4j_database,
                neo4j_uri=neo4j_uri,
                neo4j_user=neo4j_user,
                neo4j_password=neo4j_password,
            )
            return []

        try:
            edges = await graph_obj.get_all_edges()
        except Exception as exc:
            log.warning("RAG-Anything graph edge export failed: %s", exc)
            _clear_temp_graph(
                neo4j_database,
                neo4j_uri=neo4j_uri,
                neo4j_user=neo4j_user,
                neo4j_password=neo4j_password,
            )
            return []

        if not isinstance(edges, list):
            log.warning("RAG-Anything graph edge export returned unexpected type=%s", type(edges).__name__)
            return []

        triplets = _triplets_from_raganything_edges(
            edges=edges,
            file_ref=file_ref,
            created_at_floor=created_floor,
            graph_debug=False,
        )
        fallback_triplets = parse_raganything_llm_cache(working_dir / "kv_store_llm_response_cache.json")
        if fallback_triplets:
            triplets = dedupe_triplets([*triplets, *fallback_triplets])
            _clear_temp_graph(
                neo4j_database,
                neo4j_uri=neo4j_uri,
                neo4j_user=neo4j_user,
                neo4j_password=neo4j_password,
            )

        log.info(
            "RAG-Anything graph export doc_id=%s file=%s edges_total=%s triplets=%s",
            doc_id or "-",
            file_ref,
            len(edges),
            len(triplets),
        )
        return triplets

    return await _insert_and_export()
