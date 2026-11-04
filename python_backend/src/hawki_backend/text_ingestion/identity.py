"""Deterministic identities, matching the existing Laravel persisted values.

A replay must converge on exactly the same task, job, source, document and
workflow identities that the legacy implementation produced, so an operator
can compare the two sides and a retry cannot create a second logical
operation. The fingerprint is the legacy version-2 canonical hash.
"""

from __future__ import annotations

import hashlib
import json
import math
from decimal import Decimal
from typing import Any

from .settings import FINGERPRINT_VERSION


def source_id(dataset_id: str, external_document_id: str) -> str:
    """Return the logical source identity of one external document."""
    return "source_" + _digest(f"{dataset_id}|{external_document_id}")[:32]


def task_id(dataset_id: str, idempotency_key: str) -> str:
    """Return the deterministic task identity of one idempotency key."""
    return "task_text_" + _digest(f"{dataset_id}|{idempotency_key}")[:32]


def job_id(task: str, source: str) -> str:
    return "ingest_" + _digest(f"{task}|{source}")[:24]


def workflow_id(task: str) -> str:
    return "ingest-text-" + _digest(task)[:32]


def document_id(source: str) -> str:
    return "doc_" + _digest(f"{source}|document.md")[:40]


def content_hash(text: str) -> str:
    """Return the SHA-256 of the raw UTF-8 bytes of the text."""
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def fingerprint(values: dict[str, Any]) -> str:
    """Return the canonical request fingerprint of the direct-text input.

    The canonical form is the legacy PHP one: object keys sorted, no
    insignificant whitespace, slashes escaped, and an absent or empty metadata
    object represented as a JSON list (PHP's ``[]``).
    """
    normalized = dict(values)
    metadata = normalized.get("metadata")
    if metadata is None or metadata == {}:
        normalized["metadata"] = []
    return _digest(_canonical_json(normalized))


def canonical_metadata(metadata: dict[str, Any]) -> str:
    """Return the canonical JSON form used for the metadata size check.

    The legacy check encodes the submitted array with ``JSON_UNESCAPED_SLASHES``
    and compares the UTF-8 byte length. Key order does not change the length,
    but escaping a slash would, so this form must not escape slashes.
    """
    return legacy_json(metadata)


def metadata_bytes(metadata: dict[str, Any]) -> int:
    """Return the legacy byte size of the canonical metadata JSON."""
    return len(canonical_metadata(metadata).encode("utf-8"))


def _digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _canonical_json(value: Any) -> str:
    return legacy_json(value, sort_keys=True, escape_slashes=True)


def legacy_json(
    value: Any,
    *,
    sort_keys: bool = False,
    escape_slashes: bool = False,
    pretty: bool = False,
) -> str:
    """Encode PHP json_decode(associative=true)/json_encode compatible bytes.

    PHP arrays collapse empty objects and sequential integer-keyed objects to
    lists. Float spelling also differs from Python (1.0, exponent zeroes and
    scientific-notation thresholds); these details are persisted identities.
    """

    def string(item: str) -> str:
        item.encode("utf-8")  # PHP refuses isolated surrogate code points.
        # PHP escapes non-ASCII, but leaves DEL (ASCII 0x7f) literal. Python's
        # ensure_ascii=True also escapes DEL. Replace only the encoded character
        # inside this string token, never a caller's literal "\\u007f" sequence.
        if "\x7f" not in item:
            return json.dumps(item, ensure_ascii=True)
        return (
            '"'
            + "\x7f".join(
                json.dumps(part, ensure_ascii=True)[1:-1] for part in item.split("\x7f")
            )
            + '"'
        )

    def encode(item: Any, depth: int) -> str:
        if isinstance(item, dict):
            for key in item:
                key.encode("utf-8")
            if list(item) == [str(index) for index in range(len(item))]:
                item = list(item.values())
            elif sort_keys:
                item = dict(sorted(item.items()))
                # PHP ksort can turn a numeric-keyed array into a list.
                if list(item) == [str(index) for index in range(len(item))]:
                    item = list(item.values())
        if isinstance(item, (list, dict)):
            is_object = isinstance(item, dict)
            opening, closing = ("{", "}") if is_object else ("[", "]")
            entries = [
                (string(str(key)) + (": " if pretty else ":") if is_object else "")
                + encode(child, depth + 1)
                for key, child in (item.items() if is_object else enumerate(item))
            ]
            if pretty and entries:
                pad = "    " * (depth + 1)
                return (
                    opening
                    + "\n"
                    + pad
                    + (",\n" + pad).join(entries)
                    + "\n"
                    + "    " * depth
                    + closing
                )
            return opening + ",".join(entries) + closing
        if (
            isinstance(item, int)
            and not isinstance(item, bool)
            and not -(2**63) <= item < 2**63
        ):
            item = float(item)
        if isinstance(item, float):
            if not math.isfinite(item):
                raise ValueError("metadata numbers must be finite")
            raw = repr(item).lower()
            if "e" in raw:
                mantissa, exponent = raw.split("e")
                power = int(exponent)
                if -4 <= power < 17:
                    return format(Decimal(raw), "f")
                if "." not in mantissa:
                    mantissa += ".0"
                return mantissa + "e" + ("+" if power >= 0 else "-") + str(abs(power))
            return raw[:-2] if raw.endswith(".0") else raw
        if isinstance(item, str):
            return string(item)
        return json.dumps(item, ensure_ascii=True, allow_nan=False)

    encoded = encode(value, 0)
    return encoded.replace("/", "\\/") if escape_slashes else encoded


__all__ = [
    "FINGERPRINT_VERSION",
    "canonical_metadata",
    "content_hash",
    "document_id",
    "fingerprint",
    "job_id",
    "metadata_bytes",
    "source_id",
    "task_id",
    "workflow_id",
]
