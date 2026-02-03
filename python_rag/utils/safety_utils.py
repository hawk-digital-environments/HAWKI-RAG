"""
Prompt/output safety helpers and text normalization.
"""
from __future__ import annotations

import re
from typing import Dict

from .text_preprocessor import (
    _analyze_prompt_safety,
    _enforce_output_safety,
    _sanitize_prompt_text,
    _strip_control_chars,
)


def analyze_prompt(prompt: str) -> Dict[str, str | bool | list]:
    return _analyze_prompt_safety(prompt)


def sanitize_prompt_text(prompt: str) -> str:
    return _sanitize_prompt_text(prompt)


def enforce_output_safety(answer: str) -> Dict[str, str | bool | list]:
    return _enforce_output_safety(answer)


def clean_snippet(snippet: str) -> str:
    return _strip_control_chars(snippet)


def is_multimodal_query(text: str) -> bool:
    lowered = text.lower()
    return bool(re.search(r"\b(image|bild|diagram|table|chart|photo|fig)\b", lowered))
