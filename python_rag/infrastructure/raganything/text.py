"""Text normalization helpers for graph extraction."""
from __future__ import annotations

import os


def clean_graph_text(text: str) -> str:
    """Normalize text input before graph extraction.

    The behavior intentionally mirrors the historical in-module implementation:
    - strip blank lines
    - truncate by lines, chars, and tokens using env-configured limits
    """
    if not text:
        return ""
    cleaned: list[str] = []
    for line in str(text).splitlines():
        stripped = line.strip()
        if not stripped:
            continue
        cleaned.append(stripped)
    output = "\n".join(cleaned)
    try:
        max_lines = int(os.environ.get("GRAPH_MAX_LINES", "40"))
    except ValueError:
        max_lines = 40
    if max_lines > 0:
        output = "\n".join(output.splitlines()[:max_lines])
    try:
        max_chars = int(os.environ.get("GRAPH_MAX_CHARS", "2000"))
    except ValueError:
        max_chars = 2000
    if max_chars > 0:
        output = output[:max_chars]
    try:
        max_tokens = int(os.environ.get("MAX_EXTRACT_INPUT_TOKENS", "0"))
    except ValueError:
        max_tokens = 0
    if max_tokens > 0:
        tokens = output.split()
        if len(tokens) > max_tokens:
            output = " ".join(tokens[:max_tokens])
    return output
