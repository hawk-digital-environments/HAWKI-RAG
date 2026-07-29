"""Cache helpers for RAG-Anything graph extraction."""

from __future__ import annotations

import json
import logging
from pathlib import Path
from typing import Any, Callable, Dict

logger = logging.getLogger(__name__)


def _load_json_dict(path: Path) -> dict[str, Any]:
    """Load a JSON dictionary from disk and fall back to {} on failures."""
    try:
        with path.open("r", encoding="utf-8") as fh:
            data = json.load(fh)
        return data if isinstance(data, dict) else {}
    except FileNotFoundError:
        return {}
    except Exception as exc:
        logger.warning("graph:kv-junk-scrub failed to read %s: %s", path.name, exc)
        return {}


def _save_json_dict(path: Path, data: dict[str, Any]) -> None:
    """Persist a JSON dictionary using a .tmp write/rename pattern."""
    try:
        tmp_path = path.with_suffix(path.suffix + ".tmp")
        with tmp_path.open("w", encoding="utf-8") as fh:
            json.dump(data, fh, ensure_ascii=False, indent=2)
            fh.write("\n")
        tmp_path.replace(path)
    except Exception as exc:
        logger.warning("graph:kv-junk-scrub failed to write %s: %s", path.name, exc)


def scrub_raganything_kv_graph_junk(
    *,
    working_dir: Path,
    is_junk_graph_label: Callable[[Any], bool],
    rag_doc_id: str | None = None,
    full_scan: bool = False,
    logger_obj: logging.Logger | None = None,
) -> dict[str, int]:
    """Remove graph junk entries from RAG-Anything KV caches.

    Kept as a dedicated helper to isolate filesystem-heavy cleanup logic from
    graph service orchestration.
    """
    log = logger_obj or logger
    stats: dict[str, int] = {
        "full_entities_docs": 0,
        "full_entities_names": 0,
        "full_relations_docs": 0,
        "full_relations_pairs": 0,
        "entity_chunks": 0,
        "relation_chunks": 0,
    }
    if not full_scan and not rag_doc_id:
        return stats

    full_entities_path = working_dir / "kv_store_full_entities.json"
    full_relations_path = working_dir / "kv_store_full_relations.json"
    entity_chunks_path = working_dir / "kv_store_entity_chunks.json"
    relation_chunks_path = working_dir / "kv_store_relation_chunks.json"

    removed_entity_names: set[str] = set()
    removed_relation_pairs: set[tuple[str, str]] = set()

    full_entities = _load_json_dict(full_entities_path)
    full_entities_changed = False
    if full_entities:
        target_keys = list(full_entities.keys()) if full_scan else ([rag_doc_id] if rag_doc_id in full_entities else [])
        for key in target_keys:
            rec = full_entities.get(key)
            if not isinstance(rec, dict):
                continue
            names = rec.get("entity_names")
            if not isinstance(names, list):
                continue
            kept: list[Any] = []
            removed_here = 0
            for name in names:
                if is_junk_graph_label(name):
                    removed_entity_names.add(str(name))
                    removed_here += 1
                else:
                    kept.append(name)
            if not removed_here:
                continue
            full_entities_changed = True
            stats["full_entities_docs"] += 1
            stats["full_entities_names"] += removed_here
            if kept:
                rec["entity_names"] = kept
                rec["count"] = len(kept)
            else:
                full_entities.pop(key, None)
    if full_entities_changed:
        _save_json_dict(full_entities_path, full_entities)

    full_relations = _load_json_dict(full_relations_path)
    full_relations_changed = False
    if full_relations:
        target_keys = list(full_relations.keys()) if full_scan else ([rag_doc_id] if rag_doc_id in full_relations else [])
        for key in target_keys:
            rec = full_relations.get(key)
            if not isinstance(rec, dict):
                continue
            pairs = rec.get("relation_pairs")
            if not isinstance(pairs, list):
                continue
            kept_pairs: list[Any] = []
            removed_here = 0
            for pair in pairs:
                if not (isinstance(pair, (list, tuple)) and len(pair) >= 2):
                    kept_pairs.append(pair)
                    continue
                src = str(pair[0] or "")
                tgt = str(pair[1] or "")
                if is_junk_graph_label(src) or is_junk_graph_label(tgt):
                    removed_relation_pairs.add((src, tgt))
                    removed_here += 1
                    continue
                kept_pairs.append(pair)
            if not removed_here:
                continue
            full_relations_changed = True
            stats["full_relations_docs"] += 1
            stats["full_relations_pairs"] += removed_here
            if kept_pairs:
                rec["relation_pairs"] = kept_pairs
                rec["count"] = len(kept_pairs)
            else:
                full_relations.pop(key, None)
    if full_relations_changed:
        _save_json_dict(full_relations_path, full_relations)

    if not full_scan and not removed_entity_names and not removed_relation_pairs:
        return stats

    entity_chunks = _load_json_dict(entity_chunks_path)
    entity_chunks_changed = False
    if entity_chunks:
        if full_scan:
            keys_to_drop = [k for k in list(entity_chunks.keys()) if is_junk_graph_label(k)]
        else:
            keys_to_drop = [k for k in removed_entity_names if k in entity_chunks]
        if keys_to_drop:
            entity_chunks_changed = True
            stats["entity_chunks"] += len(keys_to_drop)
            for key in keys_to_drop:
                entity_chunks.pop(key, None)
    if entity_chunks_changed:
        _save_json_dict(entity_chunks_path, entity_chunks)

    relation_chunks = _load_json_dict(relation_chunks_path)
    relation_chunks_changed = False
    if relation_chunks:
        keys_to_drop: list[str] = []
        if full_scan:
            for key in list(relation_chunks.keys()):
                if not isinstance(key, str) or "<SEP>" not in key:
                    continue
                src, tgt = key.split("<SEP>", 1)
                if is_junk_graph_label(src) or is_junk_graph_label(tgt):
                    keys_to_drop.append(key)
        else:
            for src, tgt in removed_relation_pairs:
                key = f"{src}<SEP>{tgt}"
                if key in relation_chunks:
                    keys_to_drop.append(key)
            if removed_entity_names:
                removed_lower = {x.lower() for x in removed_entity_names}
                for key in list(relation_chunks.keys()):
                    if not isinstance(key, str) or "<SEP>" not in key:
                        continue
                    src, tgt = key.split("<SEP>", 1)
                    if src.lower() in removed_lower or tgt.lower() in removed_lower:
                        if key not in keys_to_drop:
                            keys_to_drop.append(key)
        if keys_to_drop:
            relation_chunks_changed = True
            stats["relation_chunks"] += len(keys_to_drop)
            for key in keys_to_drop:
                relation_chunks.pop(key, None)
    if relation_chunks_changed:
        _save_json_dict(relation_chunks_path, relation_chunks)

    if sum(stats.values()):
        log.info(
            "graph:kv-junk-scrub stats=%s rag_doc_id=%s full_scan=%s",
            stats,
            rag_doc_id or "-",
            full_scan,
        )
    return stats
