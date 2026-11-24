"""Storage-independent expressions over public source metadata."""

from typing import Annotated, Literal

from pydantic import (
    AfterValidator,
    BaseModel,
    BeforeValidator,
    ConfigDict,
    Discriminator,
    Field,
    RootModel,
    Tag,
    model_validator,
)

from hawki_rag_contracts.source_metadata import (
    MAX_BYTES,
    MAX_LIST,
    validate_metadata_key,
    validate_metadata_scalar,
)

MAX_DEPTH = 6
MAX_NODES = 32


Scalar = Annotated[str | int | float | bool, BeforeValidator(validate_metadata_scalar)]
Number = Annotated[int | float, BeforeValidator(validate_metadata_scalar)]
Key = Annotated[str, AfterValidator(validate_metadata_key)]


class _Node(BaseModel):
    model_config = ConfigDict(
        extra="forbid", strict=True, frozen=True, serialize_by_alias=True
    )


class Comparison(_Node):
    field: Key
    op: Literal["eq", "neq"]
    value: Scalar


class Membership(_Node):
    field: Key
    op: Literal["in", "not_in"]
    value: list[Scalar] = Field(min_length=1, max_length=MAX_LIST)


class NumericRange(_Node):
    field: Key
    op: Literal["gt", "gte", "lt", "lte"]
    value: Number


class Existence(_Node):
    field: Key
    op: Literal["exists"]
    value: bool = True


class AllOf(_Node):
    children: list["Expression"] = Field(
        alias="and", min_length=1, max_length=MAX_NODES
    )


class AnyOf(_Node):
    children: list["Expression"] = Field(alias="or", min_length=1, max_length=MAX_NODES)


class Negation(_Node):
    child: "Expression" = Field(alias="not")


def _node_kind(node: object) -> str:
    if isinstance(node, dict):
        for key in ("and", "or", "not"):
            if key in node:
                return key
        return "predicate"
    if isinstance(node, AllOf):
        return "and"
    if isinstance(node, AnyOf):
        return "or"
    if isinstance(node, Negation):
        return "not"
    return "predicate"


type Expression = Annotated[
    Annotated[AllOf, Tag("and")]
    | Annotated[AnyOf, Tag("or")]
    | Annotated[Negation, Tag("not")]
    | Annotated[
        Annotated[
            Comparison | Membership | NumericRange | Existence,
            Field(discriminator="op"),
        ],
        Tag("predicate"),
    ],
    Discriminator(_node_kind),
]


class MetadataFilter(RootModel[Expression]):
    """Validate the work budget before recursive schema validation."""

    @model_validator(mode="before")
    @classmethod
    def bound_tree(cls, raw: object) -> object:
        pending = [(raw, 1)]
        seen = 0
        while pending:
            node, depth = pending.pop()
            seen += 1
            if depth > MAX_DEPTH:
                raise ValueError(f"metadata filter depth exceeds {MAX_DEPTH}")
            if isinstance(node, dict):
                if len(node) > 3:
                    raise ValueError("unexpected metadata filter properties")
                values = node.get("value")
                if isinstance(values, list) and len(values) > MAX_LIST:
                    raise ValueError(
                        f"metadata filter lists may contain at most {MAX_LIST} values"
                    )
            if isinstance(node, (AllOf, AnyOf)):
                children = node.children
            elif isinstance(node, Negation):
                children = [node.child]
            elif isinstance(node, dict) and "and" in node:
                children = node["and"]
            elif isinstance(node, dict) and "or" in node:
                children = node["or"]
            elif isinstance(node, dict) and "not" in node:
                children = [node["not"]]
            else:
                children = []
            if isinstance(children, list):
                if seen + len(pending) + len(children) > MAX_NODES:
                    raise ValueError(f"metadata filter exceeds {MAX_NODES} nodes")
                pending.extend((child, depth + 1) for child in children)
        return raw

    @model_validator(mode="after")
    def bound_encoded_size(self) -> "MetadataFilter":
        if len(self.model_dump_json(ensure_ascii=True)) > MAX_BYTES:
            raise ValueError(f"metadata filter exceeds {MAX_BYTES} encoded bytes")
        return self
