"""Transport-independent metadata filter validation and wire representation."""

import pytest
from pydantic import ValidationError

from hawki_rag_contracts.retrieval.metadata_filter import (
    MAX_DEPTH,
    MAX_NODES,
    MetadataFilter,
)


def leaf(op="eq", value="design"):
    return {"field": "course", "op": op, "value": value}


@pytest.mark.parametrize(
    "value", [2025, "2025", True, 1.25, "  Design  ", "", -(2**63), 2**63 - 1]
)
def test_scalar_types_and_spelling_survive_nested_round_trip(value):
    wire = {"not": {"or": [{"and": [leaf(value=value)]}, leaf("exists", False)]}}
    parsed = MetadataFilter.model_validate(wire)
    assert parsed.model_dump() == wire
    actual = parsed.model_dump()["not"]["or"][0]["and"][0]["value"]
    assert type(actual) is type(value)
    assert MetadataFilter.model_validate_json(parsed.model_dump_json()) == parsed


@pytest.mark.parametrize(
    "expr",
    [
        leaf("eq", None),
        leaf("eq", {}),
        leaf("eq", ["design"]),
        leaf("gte", []),
        leaf("gt", True),
        leaf("lt", "2025"),
        leaf("eq", 2**63),
        leaf("eq", -(2**63) - 1),
        leaf("eq", float("inf")),
        leaf("gte", float("nan")),
        leaf("eq", "x" * 4097),
        leaf("eq", "nul\x00"),
        leaf("in", []),
        leaf("in", "ux"),
        leaf("not_in", [None]),
        leaf("in", [["x"]]),
        leaf("in", [1] * 33),
        leaf("exists", 1),
        leaf("prefix", "des"),
        {"op": "eq", "field": "course"},
        {**leaf(), "other": 1},
        {"and": []},
        {"or": {}},
        {"not": [leaf()]},
        {"and": [leaf()], "or": [leaf()]},
        {"must": [leaf()]},
    ],
)
def test_invalid_expression_is_rejected(expr):
    with pytest.raises(ValidationError):
        MetadataFilter.model_validate(expr)


@pytest.mark.parametrize(
    "field",
    [
        "tenant_id",
        "physicalScope",
        "qdrant_collection",
        "writerGeneration",
        "operation_id",
        "grantIds",
        "workflow_id",
        "credentials",
        "source_ownership",
        "metadata.year",
        "tags[]",
        "9year",
        " year",
        "year ",
        "x" * 65,
    ],
)
def test_fields_use_the_public_source_key_policy(field):
    with pytest.raises(ValidationError):
        MetadataFilter.model_validate({**leaf(), "field": field})


def test_limits_count_all_groups_and_leaves():
    deep = leaf()
    for _ in range(MAX_DEPTH - 1):
        deep = {"not": deep}
    MetadataFilter.model_validate(deep)
    with pytest.raises(ValidationError, match="depth"):
        MetadataFilter.model_validate({"not": deep})
    wide = {"and": [leaf()] * (MAX_NODES - 1)}
    MetadataFilter.model_validate(wide)
    with pytest.raises(ValidationError, match="nodes"):
        MetadataFilter.model_validate({"not": wide})
    assert MetadataFilter.model_validate(leaf("in", list(range(32)))).model_dump()[
        "value"
    ] == list(range(32))
    assert (
        MetadataFilter.model_validate({"field": "course", "op": "exists"}).root.value
        is True
    )
    assert type(MetadataFilter.model_validate(leaf(value=3.0)).root.value) is int


def test_encoded_limit_applies_across_groups():
    group = {"and": [leaf(value="ü" * 4000)] * 3}
    with pytest.raises(ValidationError, match="65536"):
        MetadataFilter.model_validate(group)
