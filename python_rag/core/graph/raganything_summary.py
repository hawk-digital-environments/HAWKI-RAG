"""Summary helpers for RAG-Anything graph runtime diagnostics."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict

from core.graph.raganything_client_config import graph_runtime_summary_limits
from core.graph.raganything_settings import RagAnythingGraphSettings


def build_graph_runtime_summary(
    *,
    working_dir: Path,
    settings: RagAnythingGraphSettings,
    runtime_meta: Dict[str, Any],
    graph_client_initialized: bool,
) -> Dict[str, Any]:
    """Build a diagnostic snapshot for graph runtime state."""
    chunk_files = sorted(working_dir.glob("kv_store_doc_status_chunk_*.json"))
    limits = graph_runtime_summary_limits(settings)
    return {
        "working_dir": str(working_dir),
        "graph_client_initialized": graph_client_initialized,
        "doc_status_storage": runtime_meta.get("doc_status_storage", "JsonDocStatusStorage"),
        "graph_storage": runtime_meta.get("graph_storage", "NetworkXStorage(default)"),
        "neo4j": {
            "uri": str(settings.neo4j_uri).strip() or str(settings.neo4j_bolt_url).strip() or "",
            "database": str(settings.neo4j_database or "").strip() or "neo4j (default)",
            "user": str(settings.neo4j_username).strip() or str(settings.neo4j_user).strip() or "",
        },
        "doc_status_chunks": {
            "pattern": "kv_store_doc_status_chunk_*.json",
            "count": len(chunk_files),
            "files": [p.name for p in chunk_files[:5]],
        },
        "models": {
            "graph_model": str(settings.graph_model).strip(),
            "embed_model": str(settings.embed_model).strip(),
        },
        "limits": limits,
        "resilience": {
            "embed_nan_zero_fallback": settings.ollama_embed_nan_zero_fallback,
            "graph_embed_junk_filter": True,
            "graph_embed_junk_strict": settings.graph_embed_junk_strict,
            "graph_embed_junk_denylist_configured": bool(settings.graph_embed_junk_denylist.strip()),
            "graph_embed_junk_allowlist_configured": bool(settings.graph_embed_junk_allowlist.strip()),
        },
    }
