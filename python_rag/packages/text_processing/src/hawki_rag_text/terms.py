"""Term extraction and stopword helpers."""

from __future__ import annotations

import logging
import re
from importlib.resources import files

logger = logging.getLogger(__name__)

TERM_PATTERN = re.compile(r"[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß0-9_-]{3,}")


def load_stopwords() -> set[str]:
    try:
        resource = files("hawki_rag_text.resources").joinpath(
            "german_stopwords_full.txt"
        )
        content = resource.read_text(encoding="utf-8")
    except (FileNotFoundError, ModuleNotFoundError):
        logger.warning("Packaged German stopword resource is unavailable")
        return set()
    return {
        line.strip().lower()
        for line in content.splitlines()
        if line.strip() and not line.strip().startswith(("#", ";"))
    }


STOPWORDS = load_stopwords()


def extract_terms(text: str | None, *, stopwords: set[str] | None = None) -> list[str]:
    """Extract normalized non-stopword terms from free text."""

    if not text:
        return []
    active_stopwords = STOPWORDS if stopwords is None else stopwords
    tokens: list[str] = []
    for match in TERM_PATTERN.findall(str(text)):
        token = match.lower()
        if token not in active_stopwords and len(token) >= 4:
            tokens.append(token)
    return tokens


__all__ = ["STOPWORDS", "TERM_PATTERN", "extract_terms", "load_stopwords"]
