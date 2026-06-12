"""Shared text preprocessing, query safety, and chunking helpers."""

from __future__ import annotations

import json
import logging
import math
import re
from typing import Any

from shared.text_chunking import split_text_into_chunks
from shared.text_tags import (
    fallback_tags as _fallback_tags_impl,
    flatten_keywords as _flatten_keywords_impl,
    normalize_tags as _normalize_tags_impl,
)
from shared.text_terms import STOPWORDS, TERM_PATTERN, extract_terms as _extract_terms_impl

logger = logging.getLogger(__name__)


def _load_stopwords() -> set[str]:
    return set(STOPWORDS)

_PROMPT_INJECTION_PATTERNS = [
    re.compile(r"(?i)\bignore\b.{0,40}\b(previous|earlier)\b.{0,20}\b(instruction|message|directive)s?\b"),
    re.compile(r"(?i)\boverride\b.{0,40}\b(system|safety|guardrails)\b"),
    re.compile(r"(?i)\bdisable\b.{0,40}\b(filter|safety|security)\b"),
    re.compile(r"(?i)\bbypass\b.{0,40}\b(protection|guard|filter)\b"),
    re.compile(r"(?i)\b(as an ai language model).{0,40}\bforget\b"),
    re.compile(r"(?i)\b(system prompt)\b.{0,60}\bexpose\b"),
    re.compile(r"(?i)\bdo not cite\b"),
]
_PROMPT_DISALLOWED_TOKENS = [
    "<script",
    "<iframe",
    "<svg",
    "BEGIN PROMPT INJECTION",
    "<|im_start|>",
    "<|im_end|>",
    "```bash",
    "```sh",
]
_OUTPUT_BLOCK_PATTERNS = [
    re.compile(r"(?i)\b(ignore|override)\b.{0,40}\b(instructions|system)\b"),
    re.compile(r"(?i)\bBEGIN PROMPT INJECTION\b"),
    re.compile(r"(?i)<script"),
    re.compile(r"(?i)\bthis prompt bypasses\b"),
]
_CONTEXT_STRIP_TOKENS = [
    "<<SYS>>",
    "<<SYSTEM>>",
    "<|im_start|>",
    "<|im_end|>",
    "BEGIN PROMPT INJECTION",
]

_MULTIMODAL_HINT_PATTERN = re.compile(
    r"\b(figure|fig\.|image|photo|diagram|chart|table|equation|grafik|abbildung|tabelle|diagramm|bild|foto|gleichung)\b",
    re.IGNORECASE,
)


def _extract_terms(text: str | None) -> list[str]:
    return _extract_terms_impl(text)


def _is_multimodal_query(text: str | None) -> bool:
    if not text:
        return False
    return bool(_MULTIMODAL_HINT_PATTERN.search(text))


def _strip_control_chars(text: str | None) -> str:
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


def _sanitize_prompt_text(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    cleaned = re.sub(r"[^\S\r\n]+", " ", cleaned)
    return cleaned.strip()


def _parse_json_from_text(text: str) -> dict[str, Any]:
    if not text:
        return {}
    try:
        return json.loads(text)
    except Exception:
        pass
    match = re.search(r"\{.*\}", text, flags=re.DOTALL)
    if not match:
        return {}
    try:
        return json.loads(match.group(0))
    except Exception:
        return {}


def _rewrite_query(provider: Any, query: str) -> dict[str, Any]:
    system = (
        "You are a RAG-Anything query interpreter. "
        "Return JSON only with keys: "
        "rewritten_query (string), high_level_keys (array of strings), "
        "low_level_keys (array of strings), modality_hints (array of strings), "
        "entity_terms (array of strings). "
        "Use modality_hints like: text, table, figure, chart, equation, image. "
        "Keep rewritten_query concise and faithful."
    )
    try:
        raw = provider.chat(system, [{"role": "user", "content": query}])
    except Exception as exc:
        logger.warning("Query rewrite failed: %s", exc)
        return {}
    data = _parse_json_from_text(raw)
    return data if isinstance(data, dict) else {}


def _normalize_list(value: Any) -> list[str]:
    if not value:
        return []
    if isinstance(value, str):
        return [value.strip()] if value.strip() else []
    if isinstance(value, list):
        return [item.strip() for item in value if isinstance(item, str) and item.strip()]
    return []


def _normalize_scores(scores: list[float]) -> list[float]:
    if not scores:
        return []
    lo = min(scores)
    hi = max(scores)
    if math.isclose(lo, hi):
        return [0.0 for _ in scores]
    return [(s - lo) / (hi - lo) for s in scores]


def _sanitize_context_snippet(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    for token in _CONTEXT_STRIP_TOKENS:
        cleaned = cleaned.replace(token, "")
    cleaned = re.sub(r"(?i)prompt injection:", "", cleaned)
    return cleaned.strip()


def _analyze_prompt_safety(text: str) -> dict[str, Any]:
    sanitized = _strip_control_chars(text)
    lowered = sanitized.lower()
    issues: list[str] = []

    for pattern in _PROMPT_INJECTION_PATTERNS:
        if pattern.search(sanitized):
            issues.append("prompt_injection_pattern")
            break

    for token in _PROMPT_DISALLOWED_TOKENS:
        if token.lower() in lowered:
            issues.append(f"disallowed_token:{token}")

    if len(sanitized) > 8000:
        issues.append("prompt_too_long")

    blocked = any(issue.startswith("prompt_injection") or issue.startswith("disallowed_token") for issue in issues)
    return {
        "sanitized": _sanitize_prompt_text(sanitized),
        "issues": issues,
        "blocked": blocked,
    }


def _enforce_output_safety(answer: str) -> dict[str, Any]:
    sanitized = _strip_control_chars(answer)
    issues: list[str] = []
    for pattern in _OUTPUT_BLOCK_PATTERNS:
        if pattern.search(sanitized):
            issues.append("unsafe_output_pattern")
            break
    blocked = bool(issues)
    final_answer = (
        "The generated answer was blocked by content safety. Please try a different question."
        if blocked
        else sanitized.strip()
    )
    return {
        "blocked": blocked,
        "issues": issues,
        "answer": final_answer,
    }


def _estimate_tokens(text: str) -> int:
    """Cheap heuristic: assume roughly four characters per token."""
    if not text:
        return 0
    return max(1, len(text) // 4)


def _truncate_to_tokens(text: str, token_budget: int) -> str:
    if token_budget <= 0 or not text:
        return ""
    approx_chars = token_budget * 4
    return text[: approx_chars + 32].strip()


def _terms_from_payload(payload: dict[str, Any]) -> list[str]:
    terms: list[str] = []
    tags = payload.get("tags")
    if isinstance(tags, str):
        terms.extend(_extract_terms(tags))
    elif isinstance(tags, list):
        for tag in tags:
            terms.extend(_extract_terms(str(tag)))
    for key in ("title", "page_url", "source_url"):
        terms.extend(_extract_terms(payload.get(key)))
    return terms


def _flatten_keywords(raw: Any) -> list[str]:
    return _flatten_keywords_impl(raw)


def _normalize_tags(candidates: list[str], limit: int = 10) -> list[str]:
    return _normalize_tags_impl(candidates, limit=limit)


def _fallback_tags(text: str, limit: int = 10) -> list[str]:
    return _fallback_tags_impl(text, limit=limit)


def ensure_tags(payload: dict[str, Any], text: str) -> None:
    raw = payload.get("tags")
    candidates = _flatten_keywords(raw)
    for key in ("keywords", "labels"):
        if key in payload and payload[key]:
            candidates.extend(_flatten_keywords(payload[key]))
    tags = _normalize_tags(candidates)
    if not tags:
        tags = _fallback_tags(text)
    payload["tags"] = tags or None
    logger.debug("tags:ensure count=%s", len(payload["tags"] or []))


def split_text(txt: str, target: int, overlap: int) -> list[str]:
    chunks = split_text_into_chunks(txt, target, overlap)
    logger.debug("split_text chunks=%s target=%s overlap=%s", len(chunks), target, overlap)
    return chunks
