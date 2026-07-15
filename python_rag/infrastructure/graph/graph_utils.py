"""
Graph helpers: structural expansion and Neo4j related utilities.
"""
from __future__ import annotations

import logging
import os
import re
import time
import unicodedata
from typing import Any, Dict, List, Iterable, Tuple

from common.text_terms import STOPWORDS
from infrastructure.graph.neo4j_graph import Neo4jGraph

logger = logging.getLogger(__name__)


def _env_bool(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name, "")
    if not raw.strip():
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, str(default)))
    except Exception:
        return default


def _perf_log(msg: str, *args: Any, graph_perf_log: bool | None = None) -> None:
    if graph_perf_log is None:
        graph_perf_log = _env_bool("GRAPH_PERF_LOG")
    if graph_perf_log:
        logger.info(msg, *args)

_IMAGE_EXT_RE = re.compile(r"\.(png|jpe?g|gif|webp|svg)(?:\\?|#|$)", re.IGNORECASE)
_PAGE_MARK_RE = re.compile(r"^(?:p|page)\\s*\\d+$", re.IGNORECASE)
_URL_RE = re.compile(r"^(?:https?://|www\.)", re.IGNORECASE)
_INTERNAL_ID_RE = re.compile(r"^(?:ingest|doc|chunk|task)_[a-z0-9][a-z0-9_-]{8,}$", re.IGNORECASE)
_HASH_RE = re.compile(r"^[a-f0-9]{32,}$", re.IGNORECASE)
_GENERATED_MARKDOWN_RE = re.compile(r"^\d{3,}\.md$", re.IGNORECASE)
_DELIMITER_RESIDUE_MARKERS = ("<|#|", "<|", "|#|", "<|COMPLETE|>", "|COMPLETE|")
_CONVERTER_METADATA_ENTITY_LABELS = {
    "chunk",
    "chunk number",
    "chunks",
    "file",
    "file name",
    "files",
    "next chunk",
    "next file",
    "nextfile",
    "nextchunk",
}
_CONVERTER_METADATA_RELATIONS = {
    "chunk number",
    "chunk number file name",
    "file name",
    "next file",
}
_LOW_VALUE_RELATIONS = {
    "generated",
    "has url",
    "has_url",
    "has title",
    "has_title",
    "is title of",
    "is_title_of",
    "is named as",
    "is_named_as",
    "is equivalent to",
    "is_equivalent_to",
    "is referenced by",
    "is_referenced_by",
    "refers to",
    "refers_to",
}
_KNOWN_PROMPT_EXAMPLE_TERMS = {
    "evolutionary search",
    "gradient based search",
    "gpu hours",
    "nasbench 360",
}


def _normalize_text(value: Any) -> str:
    if value is None:
        return ""
    return " ".join(str(value).split())


def _normalize_match_text(value: Any) -> str:
    if value is None:
        return ""
    text = unicodedata.normalize("NFKD", str(value))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower().replace("ß", "ss")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


_NORMALIZED_STOPWORDS = {
    token
    for word in STOPWORDS
    for token in _normalize_match_text(word).split()
    if token
}


def _label_tokens(label_norm: str) -> list[str]:
    return [
        token
        for token in label_norm.split()
        if len(token) >= 3 and token not in _NORMALIZED_STOPWORDS
    ]


def _is_stopword_only_label(value: str) -> bool:
    tokens = [token for token in _normalize_match_text(value).split() if token]
    return bool(tokens) and all(token in _NORMALIZED_STOPWORDS for token in tokens)


def _source_contains_label(source_norm: str, label: str) -> bool:
    label_norm = _normalize_match_text(label)
    if not label_norm:
        return False
    tokens = _label_tokens(label_norm)
    if not tokens:
        return False
    if label_norm in source_norm:
        return True
    if len(tokens) >= 2:
        return all(re.search(rf"\b{re.escape(token)}\b", source_norm) for token in tokens)
    return bool(tokens and re.search(rf"\b{re.escape(tokens[0])}\b", source_norm))


def _is_known_prompt_example_triplet(subj: str, obj: str) -> bool:
    return (
        _normalize_match_text(subj) in _KNOWN_PROMPT_EXAMPLE_TERMS
        or _normalize_match_text(obj) in _KNOWN_PROMPT_EXAMPLE_TERMS
    )


def _looks_like_image_ref(value: str) -> bool:
    lowered = value.lower()
    if "/images" in lowered or "/images_pdf" in lowered:
        return True
    return bool(_IMAGE_EXT_RE.search(lowered))


def _looks_like_page_marker(value: str) -> bool:
    return bool(_PAGE_MARK_RE.match(value.strip()))


def _looks_like_url(value: str) -> bool:
    return bool(_URL_RE.match(value.strip()))


def _looks_like_internal_id(value: str) -> bool:
    return bool(_INTERNAL_ID_RE.match(value.strip()))


def _looks_like_generated_artifact(value: str) -> bool:
    compact = value.strip()
    return bool(_HASH_RE.match(compact) or _GENERATED_MARKDOWN_RE.match(compact))


def _has_delimiter_residue(value: str) -> bool:
    return any(marker in value for marker in _DELIMITER_RESIDUE_MARKERS)


def _is_low_value_relation(value: str) -> bool:
    normalized = _normalize_match_text(value)
    if normalized in _LOW_VALUE_RELATIONS:
        return True
    return value.strip().lower() in _LOW_VALUE_RELATIONS


def _is_converter_metadata_entity(value: str) -> bool:
    return _normalize_match_text(value) in _CONVERTER_METADATA_ENTITY_LABELS


def _is_converter_metadata_relation(value: str) -> bool:
    return _normalize_match_text(value) in _CONVERTER_METADATA_RELATIONS


def _is_noise_entity(value: str) -> bool:
    if not value:
        return True
    compact = value.strip()
    if compact in {"[]", "[ ]"}:
        return True
    if _looks_like_page_marker(compact):
        return True
    if _looks_like_image_ref(compact):
        return True
    if _looks_like_url(compact):
        return True
    if _looks_like_internal_id(compact):
        return True
    if _looks_like_generated_artifact(compact):
        return True
    if _has_delimiter_residue(compact):
        return True
    if _is_converter_metadata_entity(compact):
        return True
    if _is_stopword_only_label(compact):
        return True
    return False


def _is_noise_relation(value: str) -> bool:
    if not value:
        return True
    compact = value.strip()
    if _has_delimiter_residue(compact):
        return True
    if _is_converter_metadata_relation(compact):
        return True
    if _is_low_value_relation(compact):
        return True
    if _is_stopword_only_label(compact):
        return True
    return False


def clean_triplets(
    triplets: Iterable[tuple[str, str, str]],
    *,
    graph_perf_log: bool | None = None,
) -> list[tuple[str, str, str]]:
    start = time.perf_counter()
    try:
        input_count = len(triplets)  # type: ignore[arg-type]
    except Exception:
        input_count = -1
    _perf_log(
        "perf:graph graph.graph_utils.clean_triplets start input=%s",
        input_count if input_count >= 0 else "unknown",
        graph_perf_log=graph_perf_log,
    )
    cleaned: list[tuple[str, str, str]] = []
    seen = set()
    dropped = 0
    for s, r, o in triplets:
        subj = _normalize_text(s)
        rel = _normalize_text(r)
        obj = _normalize_text(o)
        if not subj or not rel or not obj:
            dropped += 1
            continue
        if _is_noise_entity(subj) or _is_noise_entity(obj):
            dropped += 1
            continue
        if _is_noise_relation(rel):
            dropped += 1
            continue
        key = (subj, rel, obj)
        reverse_key = (obj, rel, subj)
        if key in seen or reverse_key in seen:
            dropped += 1
            continue
        seen.add(key)
        cleaned.append((subj, rel, obj))
    if dropped:
        logger.info("graph:triplets cleanup dropped=%s kept=%s", dropped, len(cleaned))
    _perf_log(
        "perf:graph graph.graph_utils.clean_triplets done input=%s kept=%s dropped=%s ms=%.2f",
        input_count if input_count >= 0 else "unknown",
        len(cleaned),
        dropped,
        (time.perf_counter() - start) * 1000,
        graph_perf_log=graph_perf_log,
    )
    return cleaned


def filter_triplets_to_source(
    triplets: Iterable[tuple[str, str, str]],
    source_text: str,
    *,
    graph_perf_log: bool | None = None,
) -> list[tuple[str, str, str]]:
    source_norm = _normalize_match_text(source_text)
    if not source_norm:
        return clean_triplets(triplets, graph_perf_log=graph_perf_log)

    filtered: list[tuple[str, str, str]] = []
    dropped_prompt_examples = 0
    dropped_ungrounded = 0

    for s, r, o in clean_triplets(triplets, graph_perf_log=graph_perf_log):
        if _is_known_prompt_example_triplet(s, o):
            dropped_prompt_examples += 1
            continue
        if not (_source_contains_label(source_norm, s) or _source_contains_label(source_norm, o)):
            dropped_ungrounded += 1
            continue
        filtered.append((s, r, o))

    if dropped_prompt_examples or dropped_ungrounded:
        logger.info(
            "graph:triplets source filter dropped_prompt_examples=%s dropped_ungrounded=%s kept=%s",
            dropped_prompt_examples,
            dropped_ungrounded,
            len(filtered),
        )

    return filtered

def _required_graph_scope(dataset_id: str, neo4j_namespace: str) -> tuple[str, str]:
    normalized_dataset_id = str(dataset_id or "").strip()
    normalized_namespace = str(neo4j_namespace or "").strip()
    if not normalized_dataset_id or not normalized_namespace:
        raise ValueError("Dataset-scoped graph retrieval requires dataset_id and neo4j_namespace.")
    return normalized_dataset_id, normalized_namespace


def fetch_related_terms(
    terms: list[str],
    *,
    dataset_id: str,
    neo4j_namespace: str,
    limit: int = 30,
) -> list[dict[str, str]]:
    dataset_id, neo4j_namespace = _required_graph_scope(dataset_id, neo4j_namespace)
    if not terms:
        return []
    g = Neo4jGraph(allow_database_fallback=False)
    try:
        results = g.fetch_related(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
        )
        logger.debug("graph:fetch_related terms=%s results=%s", len(terms), len(results))
        return results
    except Exception:
        return []
    finally:
        try:
            g.close()
        except Exception:
            pass


def structural_limit(top_k: int) -> int:
    return _env_int("RAG_STRUCTURAL_LIMIT", max(top_k * 2, 12))


def structural_hops(default_hops: int = 2) -> int:
    return _env_int("RAG_STRUCTURAL_HOPS", default_hops)


def build_structural_hits(
    terms: list[str],
    *,
    dataset_id: str,
    neo4j_namespace: str,
    limit: int,
    hops: int,
    include_rel_match: bool = False,
    graph_perf_log: bool | None = None,
) -> list[dict[str, Any]]:
    dataset_id, neo4j_namespace = _required_graph_scope(dataset_id, neo4j_namespace)
    if not terms:
        return []
    g = Neo4jGraph(allow_database_fallback=False)
    try:
        rows = g.search_structural(
            terms,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            limit=limit,
            hops=hops,
            include_rel_match=include_rel_match,
        )
    except Exception:
        rows = []
    finally:
        try:
            g.close()
        except Exception:
            pass

    hits: list[dict[str, Any]] = []
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
                    "dataset_id": dataset_id,
                    "neo4j_namespace": neo4j_namespace,
                    "content": content,
                    "title": "Graph relation",
                },
                "source": "neo4j",
            }
        )
    logger.debug("graph:structural_hits terms=%s hits=%s", len(terms), len(hits))
    return hits
