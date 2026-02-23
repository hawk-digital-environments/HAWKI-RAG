"""
Prompt/output safety helpers and text normalization.
"""
from __future__ import annotations
import re
import logging
from typing import Dict
from .text_preprocessor import (
    _analyze_prompt_safety,
    _enforce_output_safety,
    _sanitize_prompt_text,
    _strip_control_chars,
)

logger = logging.getLogger(__name__)

def analyze_prompt(prompt: str) -> Dict[str, str | bool | list]:
    # Run prompt-level safety analysis using the shared preprocessor helper.
    result = _analyze_prompt_safety(prompt)
    # Emit debug details so blocked prompts and matched issues are traceable.
    logger.debug("safety:prompt blocked=%s issues=%s", result.get("blocked"), result.get("issues"))
    return result

def sanitize_prompt_text(prompt: str) -> str:
    # Normalize and sanitize raw prompt text before downstream processing.
    return _sanitize_prompt_text(prompt)

def enforce_output_safety(answer: str) -> Dict[str, str | bool | list]:
    # Validate model output against safety rules using the shared helper.
    result = _enforce_output_safety(answer)
    logger.debug("safety:output blocked=%s issues=%s", result.get("blocked"), result.get("issues"))
    return result

def clean_snippet(snippet: str) -> str:
    # Remove control characters from snippets before display/storage.
    return _strip_control_chars(snippet)

def is_multimodal_query(text: str) -> bool:
    # Normalize to lowercase so keyword matching is case-insensitive.
    lowered = text.lower()
    # Detect simple image/table/chart-related keywords as a multimodal hint.
    return bool(re.search(r"\b(image|bild|diagram|table|chart|photo|fig)\b", lowered))