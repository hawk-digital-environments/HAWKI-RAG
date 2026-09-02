"""Lexical term extraction, matching, and score boosting.

The lexical signal lives here end to end: query and document term
extraction, character folding, fuzzy matching, and the post-rerank hit
boost. Normalization policy is declared once in this module, the
application layer's only importer of the shared text-processing term
primitives.
"""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Iterator
from dataclasses import dataclass
from typing import Any

from hawki_rag_text.terms import extract_terms


_QUERY_SCOPE_INSTRUCTION_PATTERNS = (
    re.compile(
        r"\b(?:in|aus|innerhalb)\s+"
        r"(?:(?:mein(?:e|em|en|er|es)?|unser(?:e|em|en|er|es)?|"
        r"dem|dies(?:e|em|en|er|es)?)\s+)?"
        r"(?:dataset|datensatz|datenbestand|dokument(?:e|en)?|unterlagen)\b",
        re.IGNORECASE,
    ),
    re.compile(
        r"\b(?:in|from|within)\s+"
        r"(?:(?:my|our|the|this|these)\s+)?"
        r"(?:dataset|data\s+set|documents?|files?|sources?)\b",
        re.IGNORECASE,
    ),
)
_GERMAN_ORDINAL_PATTERN = re.compile(
    r"\b(?:erst|zweit|dritt|viert|fünft|sechst|siebt|acht|neunt|zehnt|"
    r"elft|zwölft|dreizehnt|vierzehnt|fünfzehnt|sechzehnt|siebzehnt|"
    r"achtzehnt|neunzehnt|zwanzigst)(?:e|er|es|en|em)?\b",
    re.IGNORECASE,
)


@dataclass(frozen=True, slots=True)
class BaseTerms:
    """Ordered, unique, lowercased terms shared by the extraction policies."""

    terms: tuple[str, ...]

    def __iter__(self) -> Iterator[str]:
        return iter(self.terms)

    def __len__(self) -> int:
        return len(self.terms)

    def __contains__(self, item: object) -> bool:
        return item in self.terms


@dataclass(frozen=True, slots=True)
class QueryTerms(BaseTerms):
    """Query terms plus folded variants for lexical matching.

    The retrieval paths normalize text asymmetrically:
     - qdrant text search matches stored text, where raw terms hit umlaut spellings
     - lexical boosting matches folded text, where folded variants hit transliterated ones.

    Emitting both forms lets one term match either spelling —
    ``query_terms("Bauklötze").folded == ("bauklötze", "bauklotze")``.
    """

    folded: tuple[str, ...]


@dataclass(frozen=True, slots=True)
class DocumentTerms(BaseTerms):
    """Terms extracted from stored content."""


def fold_text(value: object) -> str:
    """Lowercase and fold text for accent-insensitive comparisons.

    German sharp s becomes ``ss``, the digraphs ae/oe/ue collapse to their
    base vowels, and remaining diacritics are stripped after NFKD
    normalization.
    """
    text = str(value or "").lower()
    if not text:
        return ""
    text = text.replace("ß", "ss")
    text = text.replace("ae", "a").replace("oe", "o").replace("ue", "u")
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def strip_query_scope_instructions(query: str) -> str:
    """Remove corpus-location phrases that should not constrain document text."""
    cleaned = query
    for pattern in _QUERY_SCOPE_INSTRUCTION_PATTERNS:
        cleaned = pattern.sub(" ", cleaned)
    return re.sub(r"\s+", " ", cleaned).strip()


def query_terms(text: str) -> QueryTerms:
    """Extract normalized query terms with folded variants for lexical retrieval.

    Dataset-location instructions are removed before term extraction. Lowercase
    and folded variants, including German ordinals, remain ordered and unique.
    """
    lexical_query = strip_query_scope_instructions(text)
    base = extract_terms(lexical_query)
    base.extend(
        match.group(0).lower()
        for match in _GERMAN_ORDINAL_PATTERN.finditer(lexical_query)
    )
    if not base:
        parts = [part for part in re.split(r"[\W_]+", lexical_query) if len(part) >= 3]
        base = [part.lower() for part in parts]
    seen: set[str] = set()
    raw_terms: list[str] = []
    folded_terms: list[str] = []
    for term in base:
        raw = str(term or "").strip().lower()
        if raw and raw not in seen:
            seen.add(raw)
            raw_terms.append(raw)
            folded_terms.append(raw)
        folded = fold_text(term)
        if folded and folded not in seen:
            seen.add(folded)
            folded_terms.append(folded)
    return QueryTerms(terms=tuple(raw_terms), folded=tuple(folded_terms))


def document_terms(text: str | None) -> DocumentTerms:
    """Extract stopword-filtered terms from stored content.

    Content text carries no user intent, so query heuristics such as
    scope-instruction stripping and ordinal retention do not apply.
    """
    seen: set[str] = set()
    unique: list[str] = []
    for term in extract_terms(text):
        if term not in seen:
            seen.add(term)
            unique.append(term)
    return DocumentTerms(terms=tuple(unique))


# TODO/Needs research: the flat 0.03/0.06/0.03 bonus constants
# are unvalidated heuristics. Once a eval set exists, evaluate
# local BM25-style "IDF term weighting + RRF rank fusion"
# https://qdrant.tech/documentation/search/hybrid-queries/
def boost_lexical_hits(
    hits: list[dict[str, Any]], query: str
) -> list[dict[str, Any]]:
    """Boost lexically matching hits and discard insufficient matches.

    Bonuses reflect term count plus title and URL matches. Returned copies are
    sorted by adjusted score; nonmatching hits are omitted.
    """
    if not hits:
        return hits
    terms = list(query_terms(query).folded)
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
    """Return how many query terms a hit must match to survive lexical boosting.

    One or two terms require a full match, three require two, and larger
    sets require roughly sixty percent rounded up. Terms that cannot match
    a given path still count toward the minimum, so inert variants can
    raise the bar.
    """
    count = len(terms)
    if count <= 1:
        return 1
    if count == 2:
        return 2
    if count == 3:
        return 2
    return max(2, int((count * 0.6) + 0.999))


def tokenize_words(text: str) -> list[str]:
    """Split folded text into lowercase alphanumeric words of two or more characters."""
    if not text:
        return []
    return re.findall(r"[a-z0-9]{2,}", text)


def levenshtein_with_limit(a: str, b: str, limit: int = 1) -> int:
    """Return the Levenshtein distance, capped early at ``limit + 1``.

    Distances provably above the limit are abandoned without full computation.
    """
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
    """Return whether the term matches a word exactly or within one edit.

    Only terms of at least four characters are fuzzed; candidates must share
    the first character and differ in length by at most one.
    """
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


__all__ = [
    "BaseTerms",
    "DocumentTerms",
    "QueryTerms",
    "boost_lexical_hits",
    "document_terms",
    "fold_text",
    "fuzzy_term_in_words",
    "levenshtein_with_limit",
    "min_lexical_match_count",
    "query_terms",
    "strip_query_scope_instructions",
    "tokenize_words",
]
