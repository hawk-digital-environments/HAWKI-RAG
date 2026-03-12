########################################### libs and bibz #####################################
import json
import logging
import math
import re
from collections import Counter
from pathlib import Path
from typing import Any, Dict, List

logger = logging.getLogger(__name__)

########################################### PREPROCESSING  #####################################

def _load_stopwords() -> set[str]:
    stop_path = Path(__file__).resolve().parent.parent / "config" / "german_stopwords_plain.txt"
    try:
        content = stop_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        return print("Stopwords not found")
    words = {
        line.strip().lower()
        for line in content.splitlines()
        if line.strip() and not line.strip().startswith("#")
    }
    return words


STOPWORDS = _load_stopwords()
TERM_PATTERN = re.compile(r"[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß0-9_-]{3,}")

# Basic content-safety heuristics to stop obvious prompt-injection and jailbreak attempts.
_PROMPT_INJECTION_PATTERNS = [
    re.compile(r"(?i)\bignore\b.{0,40}\b(previous|earlier)\b.{0,20}\b(instruction|message|directive)s?\b"),
    re.compile(r"(?i)\boverride\b.{0,40}\b(system|safety|guardrails)\b"),
    re.compile(r"(?i)\bdisable\b.{0,40}\b(filter|safety|security)\b"),
    re.compile(r"(?i)\bbypass\b.{0,40}\b(protection|guard|filter)\b"),
    re.compile(r"(?i)\b(as an ai language model).{0,40}\bforget\b"),
    re.compile(r"(?i)\b(system prompt)\b.{0,60}\bexpose\b"),
    re.compile(r"(?i)\bdo not cite\b"),
]
_PROMPT_DISALLOWED_TOKENS = [
    "<script",
    "<iframe",
    "<svg",
    "BEGIN PROMPT INJECTION",
    "<|im_start|>",
    "<|im_end|>",
    "```bash",
    "```sh",
]
_OUTPUT_BLOCK_PATTERNS = [
    re.compile(r"(?i)\b(ignore|override)\b.{0,40}\b(instructions|system)\b"),
    re.compile(r"(?i)\bBEGIN PROMPT INJECTION\b"),
    re.compile(r"(?i)<script"),
    re.compile(r"(?i)\bthis prompt bypasses\b"),
]
_CONTEXT_STRIP_TOKENS = [
    "<<SYS>>",
    "<<SYSTEM>>",
    "<|im_start|>",
    "<|im_end|>",
    "BEGIN PROMPT INJECTION",
]

_MULTIMODAL_HINT_PATTERN = re.compile(
    r"\b(figure|fig\.|image|photo|diagram|chart|table|equation|grafik|abbildung|tabelle|diagramm|bild|foto|gleichung)\b",
    re.IGNORECASE,
)

########################################### TOKENIZATION AND TERM EXTRACTION  #####################################

def _extract_terms(text: str | None) -> List[str]:
    if not text:
        return []
    tokens = []
    for match in TERM_PATTERN.findall(str(text)):
        token = match.lower()
        if token not in STOPWORDS and len(token) >= 4:
            tokens.append(token)
    return tokens


def _is_multimodal_query(text: str | None) -> bool:
    if not text:
        return False
    return bool(_MULTIMODAL_HINT_PATTERN.search(text))


def _strip_control_chars(text: str | None) -> str:
    if text is None:
        return ""
    cleaned_chars = []
    for ch in str(text):
        code = ord(ch)
        if ch in ("\n", "\r", "\t"):
            cleaned_chars.append(ch)
        elif code >= 32:
            cleaned_chars.append(ch)
    return "".join(cleaned_chars)


def _sanitize_prompt_text(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    # Collapse excessive whitespace but preserve newlines for readability
    cleaned = re.sub(r"[^\S\r\n]+", " ", cleaned)
    return cleaned.strip()


def _parse_json_from_text(text: str) -> Dict[str, Any]:
    if not text:
        return {}
    try:
        return json.loads(text)
    except Exception:
        pass
    match = re.search(r"\{.*\}", text, flags=re.DOTALL)
    if not match:
        return {}
    try:
        return json.loads(match.group(0))
    except Exception:
        return {}


def _rewrite_query(provider: Any, query: str) -> Dict[str, Any]:
    system = (
        "You are a RAG-Anything query interpreter. "
        "Return JSON only with keys: "
        "rewritten_query (string), high_level_keys (array of strings), "
        "low_level_keys (array of strings), modality_hints (array of strings), "
        "entity_terms (array of strings). "
        "Use modality_hints like: text, table, figure, chart, equation, image. "
        "Keep rewritten_query concise and faithful."
    )
    try:
        raw = provider.chat(system, [{"role": "user", "content": query}])
    except Exception as exc:
        logger.warning("Query rewrite failed: %s", exc)
        return {}
    data = _parse_json_from_text(raw)
    if not isinstance(data, dict):
        return {}
    return data


def _normalize_list(value: Any) -> List[str]:
    if not value:
        return []
    if isinstance(value, str):
        return [value.strip()] if value.strip() else []
    if isinstance(value, list):
        out = []
        for item in value:
            if isinstance(item, str) and item.strip():
                out.append(item.strip())
        return out
    return []


def _normalize_scores(scores: List[float]) -> List[float]:
    if not scores:
        return []
    lo = min(scores)
    hi = max(scores)
    if math.isclose(lo, hi):
        return [0.0 for _ in scores]
    return [(s - lo) / (hi - lo) for s in scores]


def _sanitize_context_snippet(text: str | None) -> str:
    cleaned = _strip_control_chars(text)
    for token in _CONTEXT_STRIP_TOKENS:
        cleaned = cleaned.replace(token, "")
    cleaned = re.sub(r"(?i)prompt injection:", "", cleaned)
    return cleaned.strip()


def _analyze_prompt_safety(text: str) -> Dict[str, Any]:
    sanitized = _strip_control_chars(text)
    lowered = sanitized.lower()
    issues: List[str] = []

    for pattern in _PROMPT_INJECTION_PATTERNS:
        if pattern.search(sanitized):
            issues.append("prompt_injection_pattern")
            break

    for token in _PROMPT_DISALLOWED_TOKENS:
        if token.lower() in lowered:
            issues.append(f"disallowed_token:{token}")

    if len(sanitized) > 8000:
        issues.append("prompt_too_long")

    blocked = any(issue.startswith("prompt_injection") or issue.startswith("disallowed_token") for issue in issues)
    sanitized_trimmed = _sanitize_prompt_text(sanitized)
    return {
        "sanitized": sanitized_trimmed,
        "issues": issues,
        "blocked": blocked,
    }


def _enforce_output_safety(answer: str) -> Dict[str, Any]:
    sanitized = _strip_control_chars(answer)
    issues: List[str] = []
    for pattern in _OUTPUT_BLOCK_PATTERNS:
        if pattern.search(sanitized):
            issues.append("unsafe_output_pattern")
            break
    blocked = bool(issues)
    final_answer = (
        "The generated answer was blocked by content safety. Please try a different question."
        if blocked
        else sanitized.strip()
    )
    return {
        "blocked": blocked,
        "issues": issues,
        "answer": final_answer,
    }


def _estimate_tokens(text: str) -> int:
    """Cheap heuristic: assume ~4 characters per token."""
    if not text:
        return 0
    return max(1, len(text) // 4)


def _truncate_to_tokens(text: str, token_budget: int) -> str:
    if token_budget <= 0 or not text:
        return ""
    approx_chars = token_budget * 4
    truncated = text[: approx_chars + 32]
    return truncated.strip()


def _terms_from_payload(payload: Dict[str, Any]) -> List[str]:
    terms: List[str] = []
    tags = payload.get("tags")
    if isinstance(tags, str):
        terms.extend(_extract_terms(tags))
    elif isinstance(tags, list):
        for tag in tags:
            terms.extend(_extract_terms(str(tag)))
    for key in ("title", "page_url", "source_url"):
        terms.extend(_extract_terms(payload.get(key)))
    return terms


def _flatten_keywords(raw) -> List[str]:
    if raw is None:
        return []
    if isinstance(raw, (list, tuple, set)):
        out: List[str] = []
        for item in raw:
            out.extend(_flatten_keywords(item))
        return out
    s = str(raw)
    cleaned = re.sub(r"^[^\n:]{0,200}:\s*", "", s)
    cleaned = re.sub(r"-\s*\d+\s*[\.-]?\s*", "\n", cleaned)
    cleaned = re.sub(r"\s*\d+\s*[\.\)\:\-]\s*", "\n", cleaned)
    parts: List[str] = []
    for line in re.split(r"[\r\n]+", cleaned):
        line = re.sub(r"^\s*[\-\*\•\u2022]?\s*", "", line)
        parts.extend(re.split(r"[,;]+", line))
    return [p.strip() for p in parts if p.strip()]


def _normalize_tags(candidates: List[str], limit: int = 10) -> List[str]:
    out: List[str] = []
    seen = set()
    for cand in candidates:
        cand = cand.replace("-", " ")
        cand = re.sub(r"[^\w\s]", " ", cand, flags=re.UNICODE)
        cand = re.sub(r"\s+", " ", cand).strip().lower()
        if not cand or len(cand) < 2:
            continue
        if cand not in seen:
            seen.add(cand)
            out.append(cand)
        if len(out) >= limit:
            break
    return out


def _fallback_tags(text: str, limit: int = 10) -> List[str]:
    if not text:
        return []
    words = re.findall(r"[a-z\u00c0-\u024f]+", text.lower())
    counts: Counter[str] = Counter()
    for w in words:
        if len(w) < 4 or w in STOPWORDS:
            continue
        counts[w] += 1
    tags: List[str] = []
    for word, _ in counts.most_common(limit * 2):
        if word not in tags:
            tags.append(word)
        if len(tags) >= limit:
            break
    return tags


def ensure_tags(payload: Dict[str, Any], text: str) -> None:
    raw = payload.get("tags")
    candidates = _flatten_keywords(raw)
    for key in ("keywords", "labels"):
        if key in payload and payload[key]:
            candidates.extend(_flatten_keywords(payload[key]))
    tags = _normalize_tags(candidates)
    if not tags:
        tags = _fallback_tags(text)
    payload["tags"] = tags or None
    logger.debug("tags:ensure count=%s", len(payload["tags"] or []))

##################################. DOC CHUNKING//PDF SLICING #################################

def split_text(txt: str, target: int, overlap: int) -> List[str]:
    txt = (txt or "").strip()
    if not txt:
        return []
    if len(txt) <= target:
        return [txt]
    out: List[str] = []
    start = 0
    L = len(txt)
    while start < L:
        end = min(L, start + target)
        slice_ = txt[start:end]
        cut = slice_.rfind("\n\n")
        if cut != -1 and cut > int(target * 0.6):
            end = start + cut
        chunk = txt[start:end].strip()
        if chunk:
            out.append(chunk)
        if end >= L:
            break
        start = max(0, end - overlap)
    logger.debug("split_text chunks=%s target=%s overlap=%s", len(out), target, overlap)
    return out
