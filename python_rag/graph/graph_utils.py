"""
Graph helpers: structural expansion and Neo4j related utilities.
"""
from __future__ import annotations

import os
from typing import Any, Dict, List

from graph.neo4j_graph import Neo4jGraph


def fetch_related_terms(terms: List[str], limit: int = 30) -> List[Dict[str, str]]:
    if not terms:
        return []
    g = Neo4jGraph()
    try:
        return g.fetch_related(terms, limit=limit)
    except Exception:
        return []
    finally:
        try:
            g.close()
        except Exception:
            pass


def structural_limit(top_k: int) -> int:
    return int(os.environ.get("RAG_STRUCTURAL_LIMIT", max(top_k * 2, 12)))


def structural_hops(default_hops: int = 2) -> int:
    try:
        return int(os.environ.get("RAG_STRUCTURAL_HOPS", str(default_hops)))
    except Exception:
        return default_hops


def build_structural_hits(
    terms: List[str],
    *,
    limit: int,
    hops: int,
    include_rel_match: bool = False,
) -> List[Dict[str, Any]]:
    if not terms:
        return []
    g = Neo4jGraph()
    try:
        rows = g.search_structural(terms, limit=limit, hops=hops, include_rel_match=include_rel_match)
    except Exception:
        rows = []
    finally:
        try:
            g.close()
        except Exception:
            pass

    hits: List[Dict[str, Any]] = []
    for row in rows:
        s = row.get("subject") or ""
        r = row.get("relation") or ""
        o = row.get("object") or ""
        hops_used = int(row.get("hops") or 1)
        doc_id = row.get("doc_id")
        content = f"{s} -{r}-> {o}".strip(" -")
        score = 1.0 / max(1, hops_used)
        hits.append(
            {
                "id": f"neo4j:{s}:{r}:{o}:{doc_id or ''}",
                "score": score,
                "payload": {
                    "component_type": "relation",
                    "subject": s,
                    "relation": r,
                    "object": o,
                    "doc_id": doc_id,
                    "content": content,
                    "title": "Graph relation",
                },
                "source": "neo4j",
            }
        )
    return hits
