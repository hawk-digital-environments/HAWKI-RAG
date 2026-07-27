"""Direct LLM fallback for graph triplets when RAG-Anything exports no edges."""

from __future__ import annotations

import json
import logging
import re
from collections.abc import Iterable
from typing import Any

from infrastructure.raganything.fallback_parser import (
    relation_label_from_text,
)
from infrastructure.raganything.raganything_utils import dedupe_triplets, is_junk_graph_label

Triplet = tuple[str, str, str]

logger = logging.getLogger(__name__)

_MAX_SOURCE_CHARS = 12000
_MAX_TRIPLETS = 16


def extract_triplets_from_text_with_provider(
    provider: Any | None,
    source_text: str,
    *,
    doc_id: str | None = None,
    image_paths: list[str] | None = None,
    max_triplets: int = _MAX_TRIPLETS,
    logger_obj: logging.Logger | None = None,
) -> list[Triplet]:
    """Ask the configured graph model for grounded triplets from converted text."""

    log = logger_obj or logger
    if provider is None or not hasattr(provider, "chat"):
        return []

    text = _trim_source_text(source_text)
    if not text:
        return []

    try:
        response = provider.chat(
            _system_prompt(max_triplets=max_triplets),
            [_user_message(text, doc_id=doc_id, image_paths=image_paths)],
            temperature=0.0,
        )
    except Exception as exc:
        log.warning("graph:extract_triplets llm-fallback failed doc_id=%s error=%s", doc_id or "-", exc)
        return []

    triplets = parse_llm_triplet_response(response, max_triplets=max_triplets)
    if triplets:
        log.info("graph:extract_triplets llm-fallback doc_id=%s triplets=%s", doc_id or "-", len(triplets))
    return triplets


def parse_llm_triplet_response(response: str, *, max_triplets: int = _MAX_TRIPLETS) -> list[Triplet]:
    """Parse the strict JSON shape requested from the fallback prompt."""

    payload = _loads_json_payload(response)
    raw_triplets: object
    if isinstance(payload, dict):
        raw_triplets = payload.get("triplets")
    else:
        raw_triplets = payload

    if not isinstance(raw_triplets, list):
        raw_triplets = list(_iter_triplet_objects_from_text(response))
        if not raw_triplets:
            return []

    parsed: list[Triplet] = []
    for raw in raw_triplets:
        triplet = _triplet_from_raw(raw)
        if triplet is None:
            continue
        parsed.append(triplet)
        if len(parsed) >= max(1, max_triplets):
            break
    return dedupe_triplets(parsed)


def _system_prompt(*, max_triplets: int) -> str:
    return (
        "Extract a knowledge graph from converted OCR or document text. "
        "Return compact valid JSON only, with this exact shape: "
        '{"triplets":[{"subject":"...","relation":"...","object":"..."}]}. '
        f"Return at most {max(1, max_triplets)} triplets. "
        "Use only facts explicitly visible in the source text. "
        "Subject and object must be short labels copied from the source text. "
        "Do not include markdown, prose, examples, or inferred facts."
    )


def _user_message(
    source_text: str,
    *,
    doc_id: str | None,
    image_paths: list[str] | None,
) -> dict[str, str]:
    image_note = ""
    paths = [path for path in image_paths or [] if isinstance(path, str) and path.strip()]
    if paths:
        image_note = "\nOriginal image files for this OCR text:\n" + "\n".join(paths[:5])
    return {
        "role": "user",
        "content": (
            f"Document ID: {doc_id or '-'}\n"
            f"{image_note}\n\n"
            "Source text:\n"
            f"{source_text}"
        ),
    }


def _trim_source_text(source_text: str) -> str:
    text = str(source_text or "").strip()
    if len(text) <= _MAX_SOURCE_CHARS:
        return text
    head_chars = _MAX_SOURCE_CHARS // 2
    tail_chars = _MAX_SOURCE_CHARS - head_chars
    return f"{text[:head_chars]}\n\n[...snipped...]\n\n{text[-tail_chars:]}"


def _loads_json_payload(response: str) -> object:
    text = str(response or "").strip()
    if not text:
        return None

    candidates = [text]
    fenced = re.search(r"```(?:json)?\s*(.*?)```", text, flags=re.IGNORECASE | re.DOTALL)
    if fenced:
        candidates.insert(0, fenced.group(1).strip())

    object_match = re.search(r"\{.*\}", text, flags=re.DOTALL)
    if object_match:
        candidates.append(object_match.group(0))

    list_match = re.search(r"\[.*\]", text, flags=re.DOTALL)
    if list_match:
        candidates.append(list_match.group(0))

    for candidate in candidates:
        try:
            return json.loads(candidate)
        except Exception:
            continue
    return None


def _iter_triplet_objects_from_text(response: str) -> Iterable[dict[str, Any]]:
    text = str(response or "")
    for match in re.finditer(r"\{[^{}]*\}", text, flags=re.DOTALL):
        raw_object = match.group(0)
        if not all(key in raw_object for key in ("subject", "relation", "object")):
            continue
        try:
            payload = json.loads(raw_object)
        except Exception:
            continue
        if isinstance(payload, dict):
            yield payload


def _triplet_from_raw(raw: object) -> Triplet | None:
    if isinstance(raw, dict):
        subj = raw.get("subject") or raw.get("source") or raw.get("head")
        rel = raw.get("relation") or raw.get("predicate") or raw.get("relationship")
        obj = raw.get("object") or raw.get("target") or raw.get("tail")
    elif isinstance(raw, (list, tuple)) and len(raw) >= 3:
        subj, rel, obj = raw[0], raw[1], raw[2]
    else:
        return None

    return _clean_triplet(subj, rel, obj)


def _clean_triplet(subject: object, relation: object, obj: object) -> Triplet | None:
    subj = _short_label(subject)
    rel = relation_label_from_text(str(relation or ""))
    target = _short_label(obj)
    if not subj or not rel or not target:
        return None
    if is_junk_graph_label(subj) or is_junk_graph_label(target):
        return None
    return subj, rel, target


def _short_label(value: object) -> str:
    text = " ".join(str(value or "").split())
    if len(text) > 120:
        text = text[:120].rstrip()
    return text


__all__ = [
    "extract_triplets_from_text_with_provider",
    "parse_llm_triplet_response",
]
