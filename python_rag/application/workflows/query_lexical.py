"""Lexical matching and score boosting for query results."""
from __future__ import annotations

import re
import unicodedata
from typing import Any

from common.text_preprocessor import _extract_terms


def fold_text(value: object) -> str:
    text = str(value or "").lower()
    if not text:
        return ""
    text = text.replace("ß", "ss")
    text = text.replace("ae", "a").replace("oe", "o").replace("ue", "u")
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def extract_query_terms_for_lexical(query: str) -> list[str]:
    terms = _extract_terms(query)
    if not terms:
        parts = [part for part in re.split(r"[\W_]+", query) if len(part) >= 3]
        terms = [part.lower() for part in parts]
    seen: set[str] = set()
    out: list[str] = []
    for term in terms:
        raw = str(term or "").strip().lower()
        if raw and raw not in seen:
            seen.add(raw)
            out.append(raw)
        folded = fold_text(term)
        if folded and folded not in seen:
            seen.add(folded)
            out.append(folded)
    return out


def lexical_boost_hits(hits: list[dict[str, Any]], query: str) -> list[dict[str, Any]]:
    if not hits:
        return hits
    terms = extract_query_terms_for_lexical(query)
    if not terms:
        return hits
    min_required = min_lexical_match_count(terms)
    boosted: list[dict[str, Any]] = []
    for hit in hits:
        payload = hit.get("payload") or {}
        fields = [
            payload.get("content"),
            payload.get("snippet"),
            payload.get("title"),
            payload.get("page_url"),
            payload.get("source_url"),
        ]
        pdfs = payload.get("pdfs")
        if isinstance(pdfs, list):
            fields.extend([str(pdf) for pdf in pdfs if pdf])
        combined = fold_text(" ".join(str(field) for field in fields if field))
        if not combined:
            continue
        words = tokenize_words(combined)
        match_count = 0
        for term in terms:
            if term in combined:
                match_count += 1
            elif fuzzy_term_in_words(term, words):
                match_count += 1
        if match_count < min_required:
            continue
        title = fold_text(payload.get("title") or "")
        url = fold_text(payload.get("page_url") or payload.get("source_url") or "")
        bonus = 0.03 * match_count
        if title and any(term in title for term in terms):
            bonus += 0.06
        if url and any(term in url for term in terms):
            bonus += 0.03
        boosted_hit = dict(hit)
        boosted_hit["score"] = float(hit.get("score") or 0.0) + bonus
        boosted.append(boosted_hit)
    boosted.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
    return boosted


def min_lexical_match_count(terms: list[str]) -> int:
    count = len(terms)
    if count <= 1:
        return 1
    if count == 2:
        return 2
    if count == 3:
        return 2
    return max(2, int((count * 0.6) + 0.999))


def tokenize_words(text: str) -> list[str]:
    if not text:
        return []
    return re.findall(r"[a-z0-9]{2,}", text)


def levenshtein_with_limit(a: str, b: str, limit: int = 1) -> int:
    if a == b:
        return 0
    if abs(len(a) - len(b)) > limit:
        return limit + 1
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, start=1):
        curr = [i]
        min_row = curr[0]
        for j, cb in enumerate(b, start=1):
            cost = 0 if ca == cb else 1
            value = min(
                prev[j] + 1,
                curr[j - 1] + 1,
                prev[j - 1] + cost,
            )
            curr.append(value)
            if value < min_row:
                min_row = value
        if min_row > limit:
            return limit + 1
        prev = curr
    return prev[-1]


def fuzzy_term_in_words(term: str, words: list[str]) -> bool:
    if term in words:
        return True
    if len(term) < 4:
        return False
    for word in words:
        if abs(len(word) - len(term)) > 1:
            continue
        if word[0] != term[0]:
            continue
        if levenshtein_with_limit(term, word, 1) <= 1:
            return True
    return False
