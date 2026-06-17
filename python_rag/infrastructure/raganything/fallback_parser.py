from __future__ import annotations

import json
import logging
import re
from pathlib import Path
from typing import Iterable

logger = logging.getLogger(__name__)

Triplet = tuple[str, str, str]


def strip_control_chars(text: str | None) -> str:
    if text is None:
        return ""
    cleaned_chars: list[str] = []
    for ch in str(text):
        code = ord(ch)
        if ch in ("\n", "\r", "\t"):
            cleaned_chars.append(ch)
        elif code >= 32:
            cleaned_chars.append(ch)
    return "".join(cleaned_chars)


def relation_label_from_text(raw: str) -> str:
    rel = strip_control_chars(str(raw or "")).replace("\n", " ").strip()
    rel = re.sub(r"\s+", " ", rel)
    if len(rel) > 120:
        rel = rel[:120].rstrip()
    return rel or "RELATED_TO"


def parse_raganything_llm_cache(
    cache_path: Path,
    *,
    chunk_id_prefix: str | None = None,
    created_at_floor: int | None = None,
) -> list[Triplet]:
    """Recover relations from LightRAG extraction outputs it failed to parse.

    Smaller local models sometimes answer the LightRAG extraction prompt with a
    markdown table instead of the expected delimiter format. LightRAG then logs
    relations in the LLM cache but does not persist them into graph edges.

    When a chunk prefix or timestamp floor is supplied, only records from the
    current extraction are considered. LightRAG keeps this cache as a shared KV
    file, so parsing every record can leak old document triplets into a new doc.
    """
    if not cache_path.is_file():
        return []

    try:
        payload = json.loads(cache_path.read_text(encoding="utf-8"))
    except Exception as exc:
        logger.debug("RAG-Anything LLM cache fallback skipped: %s", exc)
        return []

    if not isinstance(payload, dict):
        return []

    triplets: list[Triplet] = []
    for rec in payload.values():
        if not isinstance(rec, dict):
            continue
        if not _cache_record_matches(
            rec,
            chunk_id_prefix=chunk_id_prefix,
            created_at_floor=created_at_floor,
        ):
            continue
        text = str(rec.get("return") or "")
        if not text.strip():
            continue

        triplets.extend(_parse_delimited_relations(text))
        triplets.extend(_parse_markdown_relation_table(text))

    recovered = dedupe_triplets(triplets)
    if recovered:
        logger.info("RAG-Anything LLM cache fallback recovered triplets=%s", len(recovered))
    return recovered


def _cache_record_matches(
    rec: dict[str, object],
    *,
    chunk_id_prefix: str | None,
    created_at_floor: int | None,
) -> bool:
    if chunk_id_prefix:
        chunk_id = str(rec.get("chunk_id") or "")
        if not chunk_id.startswith(chunk_id_prefix):
            return False

    if created_at_floor is None:
        return True

    timestamp = _cache_record_timestamp(rec)
    if timestamp is None:
        return chunk_id_prefix is not None

    return timestamp >= created_at_floor


def _cache_record_timestamp(rec: dict[str, object]) -> int | None:
    for key in ("create_time", "update_time"):
        value = rec.get(key)
        if isinstance(value, bool):
            continue
        if isinstance(value, (int, float)):
            return int(value)
        if isinstance(value, str):
            try:
                return int(float(value.strip()))
            except ValueError:
                continue
    return None


def _parse_delimited_relations(text: str) -> Iterable[Triplet]:
    for match in re.finditer(
        r"relation<\|#\|>(.*?)<\|#\|>(.*?)<\|#\|>(.*?)(?:<\|#\|>(.*?))?(?:\n|$)",
        text,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        src = match.group(1).strip()
        tgt = match.group(2).strip()
        rel = relation_label_from_text(match.group(3).strip())
        if src and tgt and not is_junk_graph_label(src) and not is_junk_graph_label(tgt):
            yield (src, rel, tgt)


def _parse_markdown_relation_table(text: str) -> Iterable[Triplet]:
    in_relationship_table = False
    for line in text.splitlines():
        row = line.strip()
        if not row:
            continue
        lowered = row.lower()
        if lowered.startswith("relationship|") or lowered.startswith("relationship |"):
            in_relationship_table = True
            continue
        if not in_relationship_table:
            continue
        if set(row.replace("|", "").strip()) <= {"-"}:
            continue
        if "|" not in row:
            continue
        parts = [part.strip() for part in row.strip("|").split("|")]
        if len(parts) < 3:
            continue
        rel, src, tgt = parts[0], parts[1], parts[2]
        if not src or not tgt:
            continue
        if is_junk_graph_label(src) or is_junk_graph_label(tgt):
            continue
        yield (src, relation_label_from_text(rel), tgt)


def is_junk_graph_label(value: object) -> bool:
    text = relation_label_from_text(str(value or ""))
    if not text:
        return True
    lowered = text.lower()
    if lowered in {"n/a", "none", "null", "unknown"}:
        return True
    if lowered.startswith("entity `") or lowered.startswith("relationship `"):
        return True
    return False


def dedupe_triplets(triplets: Iterable[Triplet]) -> list[Triplet]:
    seen: set[tuple[str, str, str]] = set()
    out: list[Triplet] = []
    for s, r, o in triplets:
        key = (s.strip().lower(), r.strip().lower(), o.strip().lower())
        if key in seen:
            continue
        seen.add(key)
        out.append((s, r, o))
    return out
