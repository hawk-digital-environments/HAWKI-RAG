"""Reviewed OpenAPI profile: prove domain inclusion, never sample a regex.

Unknown predicates refuse. The accepted patterns below are reviewed supersets
of the constructors in URL service._candidate, source_identity and digest.
Task IDs can also be caller-supplied strings (UrlStartInput, max 191).
Generation is stored in PostgreSQL INTEGER, hence the finite supported bound.
"""

import math

JOB_PATTERN = r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$"
GENERATION_MAX = 2**31 - 1
DOMAINS = {
    "job_id": (31, 31, {JOB_PATTERN, r"^ingest_[a-f0-9]{24}$"}),
    "external_job_id": (31, 31, {JOB_PATTERN, r"^ingest_[a-f0-9]{24}$"}),
    "source_id": (39, 39, {JOB_PATTERN, r"^source_[a-f0-9]{32}$"}),
    "task_id": (1, 191, set()),
    "dataset_id": (1, 191, {r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$"}),
    "url_ingestion_operation_id": (64, 64, {JOB_PATTERN, r"^[a-f0-9]{64}$"}),
    "source_url": (1, 4104, set()),
    "url": (1, 4104, set()),
    "sitemap_base": (1, 4104, set()),
    "output_dir": (1, None, set()),
    "output_directory": (1, None, set()),
}

ANNOTATIONS = {"title", "description", "default", "example", "examples", "deprecated"}


def require_values(schema: dict, values: tuple | frozenset) -> None:
    """Prove every value in a finite domain, including exact combinator semantics.

    Other scalar predicates are deliberately unsupported for these finite
    lifecycle domains. Unknown predicates in any branch refuse, even when a
    different branch matches. Infinite domains still use require_domain.
    """

    def matches(rule, value):
        if not isinstance(rule, dict) or set(rule) - ANNOTATIONS - {
            "type",
            "nullable",
            "enum",
            "const",
            "allOf",
            "anyOf",
            "oneOf",
            "not",
        }:
            raise ValueError("Unsupported crawler finite-domain predicate")
        kind = rule.get("type")
        if rule.get("nullable"):
            raise ValueError("Required crawler value cannot be nullable")
        if kind is not None and kind not in {"boolean", "string", "integer", "null"}:
            raise ValueError("Unsupported crawler finite-domain type")
        compatible = (
            kind is None
            or {
                "boolean": type(value) is bool,
                "string": type(value) is str,
                "integer": type(value) is int,
                "null": value is None,
            }[kind]
        )
        if "enum" in rule:
            compatible &= any(
                type(item) is type(value) and item == value for item in rule["enum"]
            )
        if "const" in rule:
            compatible &= type(rule["const"]) is type(value) and rule["const"] == value
        for combinator in ("allOf", "anyOf", "oneOf"):
            if combinator in rule:
                branches = rule[combinator]
                if not isinstance(branches, list) or not branches:
                    raise ValueError("Invalid crawler combinator")
                outcomes = [matches(branch, value) for branch in branches]
                compatible &= (
                    all(outcomes)
                    if combinator == "allOf"
                    else any(outcomes)
                    if combinator == "anyOf"
                    else sum(outcomes) == 1
                )
        if "not" in rule:
            compatible &= not matches(rule["not"], value)
        return compatible

    if not all(matches(schema, value) for value in values):
        raise ValueError("Crawler predicate excludes a required value")


def require_domain(schema: dict, name: str, kind: str) -> None:
    if kind == "boolean":
        require_values(schema, (False, True))
        return
    allowed = ANNOTATIONS | {"type", "nullable", "enum", "format"}
    allowed |= (
        {"pattern", "minLength", "maxLength"}
        if kind == "string"
        else {"minimum", "maximum"}
        if kind == "integer"
        else set()
    )
    if set(schema) - allowed or schema.get("type") != kind or schema.get("nullable"):
        raise ValueError("Unsupported crawler constraint")
    for key in ("minLength", "maxLength"):
        if key in schema and (type(schema[key]) is not int or schema[key] < 0):
            raise ValueError("Invalid crawler string bound")
    for key in ("minimum", "maximum"):
        if key in schema and (
            type(schema[key]) not in {int, float} or not math.isfinite(schema[key])
        ):
            raise ValueError("Invalid crawler generation bound")
    if "enum" in schema:
        if (
            kind != "boolean"
            or any(type(value) is not bool for value in schema["enum"])
            or schema["enum"] not in ([True, False], [False, True])
        ):
            raise ValueError("Crawler enum restricts RAWKI values")
    if kind == "string":
        minimum, maximum, patterns = DOMAINS[name]
        pattern = schema.get("pattern")
        if pattern is not None and pattern not in patterns | {r"^[\s\S]*$"}:
            raise ValueError("Unreviewed crawler identity pattern")
        if schema.get("minLength", 0) > minimum or (
            "maxLength" in schema and (maximum is None or schema["maxLength"] < maximum)
        ):
            raise ValueError("Crawler string bounds restrict RAWKI values")
        formats = (
            {None, "iri", "iri-reference"}
            if name in {"url", "source_url", "sitemap_base"}
            else {None}
        )
        if schema.get("format") not in formats:
            raise ValueError("Crawler string format incompatible")
    elif kind == "integer":
        if name == "pages_crawled":
            if (
                schema.get("minimum", 0) > 0
                or "maximum" in schema
                or schema.get("format") is not None
            ):
                raise ValueError("Crawler page counter domain incompatible")
            return
        if (
            schema.get("minimum", 0) > 1
            or schema.get("maximum", GENERATION_MAX) < GENERATION_MAX
            or schema.get("format") not in {None, "int32", "int64"}
        ):
            raise ValueError("Crawler generation bounds incompatible")
