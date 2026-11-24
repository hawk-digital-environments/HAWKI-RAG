"""Compile validated public metadata expressions for scoped Qdrant retrieval."""

import operator
from typing import Any

from hawki_rag_contracts.retrieval.metadata_filter import (
    AllOf,
    AnyOf,
    Existence,
    Expression,
    Membership,
    Negation,
    NumericRange,
)

_DIRECT_TEXT = {"key": "ingestion_mode", "match": {"value": "direct_text"}}
_COMPARE = {
    "gt": operator.gt,
    "gte": operator.ge,
    "lt": operator.lt,
    "lte": operator.le,
    "eq": operator.eq,
}


def _numeric(key: str, op: str, bound: float | int) -> dict[str, Any]:
    interval = {"gte": bound, "lte": bound} if op == "eq" else {op: bound}
    condition = {"key": key, "range": interval}
    # Qdrant range bounds and payload comparisons use binary64. Near the
    # int64 extremes adjacent stored integers can round to the same float.
    # Correct just that rounding bucket with a native, exact integer match.
    rounded = float(bound)
    if abs(rounded) < 2**53 or abs(rounded) > 2**63:
        return condition
    compare = _COMPARE[op]
    includes_bucket = compare(rounded, bound)
    if op != "eq":
        direction = "lt" if op in ("lt", "lte") else "gt"
        range_op = direction + "e" if includes_bucket else direction
        condition["range"] = {range_op: rounded}
    center = int(rounded)
    corrections = []
    for first, step in ((center, 1), (center - 1, -1)):
        candidate = first
        while -(2**63) <= candidate < 2**63 and float(candidate) == rounded:
            if compare(candidate, bound) != includes_bucket:
                corrections.append(candidate)
            candidate += step
    if not corrections:
        return condition
    exact = {"key": key, "match": {"any": corrections}}
    if includes_bucket:
        return {"must": [condition], "must_not": [exact]}
    return {"should": [condition, exact]}


def _equals(key: str, value: str | int | float | bool) -> dict[str, Any]:
    if type(value) is float:
        return _numeric(key, "eq", value)
    return {"key": key, "match": {"value": value}}


def compile_metadata_filter(expr: Expression) -> dict[str, Any]:
    if isinstance(expr, AllOf):
        return {"must": [compile_metadata_filter(child) for child in expr.children]}
    if isinstance(expr, AnyOf):
        return {"should": [compile_metadata_filter(child) for child in expr.children]}
    if isinstance(expr, Negation):
        return {"must_not": [compile_metadata_filter(expr.child)]}

    key = f"metadata.{expr.field}"
    # Only direct text owns public metadata. Other adapters can store private
    # processing data at the same path and have an empty public metadata view.
    present = {"key": key, "values_count": {"gte": 0}}
    if isinstance(expr, Existence):
        exists = {"must": [_DIRECT_TEXT, present]}
        return exists if expr.value else {"must_not": [exists]}
    if isinstance(expr, NumericRange):
        condition = _numeric(key, expr.op, expr.value)
    elif isinstance(expr, Membership):
        condition = {"should": [_equals(key, value) for value in expr.value]}
    else:
        condition = _equals(key, expr.value)
    if expr.op in ("neq", "not_in"):
        return {"must": [_DIRECT_TEXT, present], "must_not": [condition]}
    return {"must": [_DIRECT_TEXT, condition]}
