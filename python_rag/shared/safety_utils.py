"""Prompt/output safety helpers and text normalization."""

from __future__ import annotations

import logging
import re
from typing import Any

from shared.text_preprocessor import (
    _analyze_prompt_safety,
    _enforce_output_safety,
    _sanitize_prompt_text,
    _strip_control_chars,
)

logger = logging.getLogger(__name__)


def analyze_prompt(prompt: str) -> dict[str, Any]:
    result = _analyze_prompt_safety(prompt)
    logger.debug("safety:prompt blocked=%s issues=%s", result.get("blocked"), result.get("issues"))
    return result


def sanitize_prompt_text(prompt: str) -> str:
    return _sanitize_prompt_text(prompt)


def enforce_output_safety(answer: str) -> dict[str, Any]:
    result = _enforce_output_safety(answer)
    logger.debug("safety:output blocked=%s issues=%s", result.get("blocked"), result.get("issues"))
    return result


def clean_snippet(snippet: str) -> str:
    return _strip_control_chars(snippet)


def is_multimodal_query(text: str) -> bool:
    lowered = text.lower()
    return bool(re.search(r"\b(image|bild|diagram|table|chart|photo|fig)\b", lowered))
