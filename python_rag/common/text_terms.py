"""Term extraction and stopword helpers."""

from __future__ import annotations

import logging
import re
from pathlib import Path

logger = logging.getLogger(__name__)

TERM_PATTERN = re.compile(r"[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß0-9_-]{3,}")


def load_stopwords() -> set[str]:
    stop_path = Path(__file__).resolve().parent.parent / "german_stopwords_full.txt"
    try:
        content = stop_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        logger.warning("Stopwords not found: %s", stop_path)
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
