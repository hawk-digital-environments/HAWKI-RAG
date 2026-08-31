"""Pure prompt and generated-output safety rules."""

from __future__ import annotations

import logging
import re
from typing import Any

logger = logging.getLogger(__name__)

_PROMPT_INJECTION_PATTERNS = (
    re.compile(
        r"(?i)\bignore\b.{0,40}\b(previous|earlier)\b.{0,20}\b(instruction|message|directive)s?\b"
    ),
    re.compile(r"(?i)\boverride\b.{0,40}\b(system|safety|guardrails)\b"),
    re.compile(r"(?i)\bdisable\b.{0,40}\b(filter|safety|security)\b"),
    re.compile(r"(?i)\bbypass\b.{0,40}\b(protection|guard|filter)\b"),
    re.compile(r"(?i)\b(as an ai language model).{0,40}\bforget\b"),
    re.compile(r"(?i)\b(system prompt)\b.{0,60}\bexpose\b"),
    re.compile(r"(?i)\bdo not cite\b"),
)
_PROMPT_DISALLOWED_TOKENS = (
    "<script",
    "<iframe",
    "<svg",
    "BEGIN PROMPT INJECTION",
    "<|im_start|>",
    "<|im_end|>",
    "```bash",
    "```sh",
)
_OUTPUT_BLOCK_PATTERNS = (
    re.compile(r"(?i)\b(ignore|override)\b.{0,40}\b(instructions|system)\b"),
    re.compile(r"(?i)\bBEGIN PROMPT INJECTION\b"),
    re.compile(r"(?i)<script"),
    re.compile(r"(?i)\bthis prompt bypasses\b"),
)
_MULTIMODAL_HINT_PATTERN = re.compile(
    r"\b(image|bild|diagram|table|chart|photo|fig)\b",
    re.IGNORECASE,
)


def strip_control_characters(text: str | None) -> str:
    """Remove non-whitespace ASCII control characters from text."""

    if text is None:
        return ""
    return "".join(
        character
        for character in str(text)
        if character in ("\n", "\r", "\t") or ord(character) >= 32
    )


def sanitize_prompt_text(prompt: str) -> str:
    """Remove control characters and collapse horizontal whitespace."""

    cleaned = strip_control_characters(prompt)
    return re.sub(r"[^\S\r\n]+", " ", cleaned).strip()


def analyze_prompt(prompt: str) -> dict[str, Any]:
    """Identify known prompt-injection markers without invoking a model."""

    sanitized = strip_control_characters(prompt)
    lowered = sanitized.lower()
    issues: list[str] = []
    if any(pattern.search(sanitized) for pattern in _PROMPT_INJECTION_PATTERNS):
        issues.append("prompt_injection_pattern")
    issues.extend(
        f"disallowed_token:{token}"
        for token in _PROMPT_DISALLOWED_TOKENS
        if token.lower() in lowered
    )
    if len(sanitized) > 8000:
        issues.append("prompt_too_long")
    blocked = any(
        issue.startswith(("prompt_injection", "disallowed_token")) for issue in issues
    )
    result = {
        "sanitized": sanitize_prompt_text(sanitized),
        "issues": issues,
        "blocked": blocked,
    }
    logger.debug("safety:prompt blocked=%s issues=%s", blocked, issues)
    return result


def enforce_output_safety(answer: str) -> dict[str, Any]:
    """Block generated output containing known instruction-bypass markers."""

    sanitized = strip_control_characters(answer)
    issues = (
        ["unsafe_output_pattern"]
        if any(pattern.search(sanitized) for pattern in _OUTPUT_BLOCK_PATTERNS)
        else []
    )
    blocked = bool(issues)
    result = {
        "blocked": blocked,
        "issues": issues,
        "answer": (
            "The generated answer was blocked by content safety. Please try a different question."
            if blocked
            else sanitized.strip()
        ),
    }
    logger.debug("safety:output blocked=%s issues=%s", blocked, issues)
    return result


def clean_snippet(snippet: str) -> str:
    """Remove non-whitespace control characters from one context snippet."""

    return strip_control_characters(snippet)


def is_multimodal_query(text: str) -> bool:
    """Return whether the query explicitly references visual or tabular content."""

    return bool(_MULTIMODAL_HINT_PATTERN.search(text))


__all__ = [
    "analyze_prompt",
    "clean_snippet",
    "enforce_output_safety",
    "is_multimodal_query",
    "sanitize_prompt_text",
    "strip_control_characters",
]
