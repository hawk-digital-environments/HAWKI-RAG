"""Utilities for RAG-Anything graph extraction filtering and text normalization."""

from __future__ import annotations

import hashlib
import re
from functools import lru_cache

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


def normalize_graph_embed_text(text: object) -> str:
    cleaned = strip_control_chars(str(text or ""))
    cleaned = cleaned.encode("utf-8", errors="ignore").decode("utf-8", errors="ignore")
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


@lru_cache(maxsize=64)
def parse_graph_filter_pattern_list(raw: str) -> tuple[tuple[str, str], ...]:
    text = str(raw or "").strip()
    if not text:
        return ()

    parts = [p.strip() for p in (text.split(";") if ";" in text else text.split(","))]
    parsed: list[tuple[str, str]] = []
    for part in parts:
        if not part:
            continue
        lower = part.lower()
        if lower.startswith("exact:"):
            parsed.append(("exact", part[6:].strip()))
        elif lower.startswith("contains:"):
            parsed.append(("contains", part[9:].strip()))
        elif lower.startswith("re:"):
            parsed.append(("regex", part[3:].strip()))
        else:
            parsed.append(("exact", part))

    return tuple((mode, pattern) for mode, pattern in parsed if pattern)


def graph_filter_list_match(text: str, lower: str, raw_list: str) -> bool:
    patterns = parse_graph_filter_pattern_list(raw_list)
    if not patterns:
        return False

    for mode, pattern in patterns:
        if mode == "exact":
            if lower == pattern.lower():
                return True
        elif mode == "contains":
            if pattern.lower() in lower:
                return True
        elif mode == "regex":
            try:
                if re.search(pattern, text, flags=re.IGNORECASE):
                    return True
            except re.error:
                continue

    return False


def graph_embed_junk_reason(
    text: str,
    *,
    allowlist_raw: str | None = None,
    denylist_raw: str | None = None,
    strict: bool | None = None,
) -> str | None:
    if not text:
        return "empty"

    lower = text.lower().strip()
    if not lower:
        return "empty"

    allowlist_value = str(allowlist_raw or "").strip()
    if allowlist_value and graph_filter_list_match(text, lower, allowlist_value):
        return None

    denylist_value = str(denylist_raw or "").strip()
    if denylist_value and graph_filter_list_match(text, lower, denylist_value):
        return "env_denylist"

    placeholder_exact = {
        "n/a",
        "na",
        "none",
        "null",
        "unknown",
        "entity",
        "relation",
        "realtion",
        "target_entity",
        "source_entity",
        "complete",
        "complete|",
        "skip",
    }
    if lower in placeholder_exact:
        return "placeholder"

    if re.fullmatch(r"[\W_]+", text):
        return "punctuation_only"

    alnum = sum(1 for ch in text if ch.isalnum())
    alpha = sum(1 for ch in text if ch.isalpha())
    if alnum <= 1:
        return "too_short"
    if len(text) <= 3 and alpha <= 1:
        return "too_short"

    if any(marker in text for marker in ("<|#|>", "<|#", "<|COMPLETE|>", "|#|")):
        return "delimiter_residue"
    if lower.startswith(("entity<", "relation<", "realtion<")):
        return "record_marker_residue"

    if text.endswith(("<", "|", "~", "`")) and alnum < 24:
        return "truncated_token"

    separator_count = sum(text.count(ch) for ch in ("<", ">", "|", "~", "`"))
    if separator_count >= 2 and alnum < 20:
        return "separator_heavy_fragment"

    if "n/a" in lower and alnum < 20:
        return "na_fragment"

    if strict is None:
        strict = True
    if strict:
        strict_exact = {
            "main content",
            "stage",
            "skip to main content",
            "skip to main content button",
            "skip to stage",
            "main content stage",
            "target entity",
            "source entity",
        }
        if lower in strict_exact:
            return "strict_boilerplate_label"
        if lower.startswith("skip to main content"):
            return "strict_boilerplate_label"
        if lower.startswith("skip to stage"):
            return "strict_boilerplate_label"

        strict_regexes = (
            r"\bnachname\s*,\s*vorname\b",
            r"\bname\s*,\s*vorname\b",
            r"\bstr\.\s*,\s*nr\.\s*,\s*plz(?:\s*,\s*ort)?\b",
            r"\bplz\s*,\s*ort\b",
            r"\btelefon(?:nummer)?\s*[:/]\s*fax\b",
        )
        for pattern in strict_regexes:
            if re.search(pattern, lower):
                return "strict_form_placeholder"

    return None


def is_junk_graph_label(
    value: object,
    *,
    allowlist_raw: str | None = None,
    denylist_raw: str | None = None,
    strict: bool | None = None,
) -> bool:
    text = normalize_graph_embed_text(value)
    return (
        graph_embed_junk_reason(
            text,
            allowlist_raw=allowlist_raw,
            denylist_raw=denylist_raw,
            strict=strict,
        )
        is not None
    )


def junk_embedding_sentinel(text: str, dim: int) -> list[float]:
    safe_dim = max(1, int(dim or 1024))
    vec = [0.0] * safe_dim
    digest = hashlib.sha1(text.encode("utf-8", errors="ignore")).digest()
    slot = int.from_bytes(digest[:4], "big") % safe_dim
    vec[slot] = 1.0
    return vec


def dedupe_triplets(triplets: list[Triplet]) -> list[Triplet]:
    seen = set()
    out: list[Triplet] = []
    for s, r, o in triplets:
        key = (s.strip(), r.strip(), o.strip())
        if not all(key) or key in seen:
            continue
        seen.add(key)
        out.append((key[0], key[1], key[2]))
    return out
