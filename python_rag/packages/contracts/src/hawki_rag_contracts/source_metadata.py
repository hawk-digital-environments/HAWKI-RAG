"""Flat, typed user metadata shared by ingestion, indexing and query clients."""

import hashlib
import json
import math
import re
from typing import Annotated

from pydantic import BeforeValidator, Field

MetadataValue = str | int | float | bool | list[str]
MANAGED_TEXT_MODE = "managed_text"
PUBLIC_METADATA_MODES = ("direct_text", MANAGED_TEXT_MODE)

MAX_KEYS = 32
MAX_STRING = 4096
MAX_LIST = 32
MAX_BYTES = 65_536
_KEY = re.compile(r"[A-Za-z][A-Za-z0-9_]{0,63}", re.ASCII)
# Compare without underscores or case so camelCase aliases cannot bypass policy.
_RESERVED = frozenset(
    """
    collection namespace database scope scopes generation owner ownership principal
    provider storage ingestion graph grant grants permission permissions role roles
    identity claims acl jwt token credentials secret password authorization
    docid documentid externaldocumentid manageddocumentid sourceidentity sourceid
    jobid taskid runid workflowid operationid chunkid pointid traceid eventid
    contenthash fingerprint version idempotencykey embeddingmodel embeddingprovider
    chatmodel visionmodel graphmodel graphenabled contentformat ingestionmode
    sharedroot physicalscope physicalstorage authorizedscope sourceowner
    filepath relativepath markdownpath chunkindex componenttype
    auth authscope authcontext authclaims authid jwtclaims modelconfig ownerid
    userid serviceid rolescope accesscontrol graphnamespace metadata metadatahash
    graphdatabase graphdocid graphdocumentid documentidentity requestid
    pointids operationkey taskqueues writerstate sub iss aud azp kid jti clientid
    sourcedocumentid documentversion sourceidentityhash revisionid generationid
    markdownoutputpath rawoutputpath ingestmanifestpath taskqueue lifecycle
    lifecyclestatus deletionkey fenceid schemaversion textwriterprotocol
""".split()
)
_INTERNAL_PREFIXES = (
    "rawki",
    "hawki",
    "tenant",
    "dataset",
    "qdrant",
    "neo4j",
    "writer",
    "managed",
    "authentication",
    "authorization",
    "authz",
    "grant",
    "principal",
    "workflow",
    "temporal",
    "operation",
    "storage",
    "internal",
    "physical",
    "permission",
    "owner",
    "sourceowner",
    "graphstorage",
    "raganything",
    "lightrag",
    "graphid",
)
_CREDENTIAL_TERMS = (
    "token",
    "secret",
    "password",
    "apikey",
    "privatekey",
    "credential",
)


def _string(value: str) -> None:
    if len(value) > MAX_STRING or "\x00" in value:
        raise ValueError(
            "metadata strings must be NUL-free and at most 4096 characters"
        )
    try:
        value.encode("utf-8")
    except UnicodeEncodeError:
        raise ValueError("metadata strings must contain valid Unicode") from None


def validate_metadata_key(key: object) -> str:
    if not isinstance(key, str) or not _KEY.fullmatch(key):
        raise ValueError("metadata keys must match [A-Za-z][A-Za-z0-9_]{0,63}")
    if is_reserved_metadata_key(key):
        raise ValueError("metadata contains a reserved key")
    return key


def is_reserved_metadata_key(key: str) -> bool:
    name = key.lower().replace("_", "")
    return (
        name in _RESERVED
        or name.startswith(_INTERNAL_PREFIXES)
        or any(term in name for term in _CREDENTIAL_TERMS)
    )


def validate_metadata_scalar(value: object) -> str | int | float | bool:
    """Normalize one wire scalar using the persisted source metadata types."""
    if isinstance(value, str):
        _string(value)
        return value
    if type(value) is bool or (type(value) is int and -(2**63) <= value < 2**63):
        return value
    if type(value) is float and math.isfinite(value):
        # Canonicalize integral numbers so equality can use exact int64 matching.
        if value.is_integer() and -(2**63) <= value < 2**63:
            return int(value)
        return value
    raise ValueError(
        "metadata scalars must be strings, signed 64-bit integers, finite numbers or booleans"
    )


def validate_source_metadata(value: object) -> dict[str, MetadataValue]:
    if not isinstance(value, dict) or len(value) > MAX_KEYS:
        raise ValueError("metadata must be an object with at most 32 keys")
    fields = {}
    for key, item in value.items():
        validate_metadata_key(key)
        if isinstance(item, list):
            if len(item) > MAX_LIST or not all(
                isinstance(entry, str) for entry in item
            ):
                raise ValueError("metadata lists may contain at most 32 strings")
            for entry in item:
                _string(entry)
            fields[key] = item
        else:
            fields[key] = validate_metadata_scalar(item)
    if len(_encoded(fields)) > MAX_BYTES:
        raise ValueError("metadata exceeds 65536 encoded bytes")
    return fields


SourceMetadata = Annotated[
    dict[str, MetadataValue],
    BeforeValidator(validate_source_metadata),
    Field(
        json_schema_extra={
            "maxProperties": MAX_KEYS,
            "propertyNames": {"pattern": "^" + _KEY.pattern + "$"},
        }
    ),
]


def _encoded(value: dict) -> bytes:
    return json.dumps(
        value, ensure_ascii=True, sort_keys=True, separators=(",", ":")
    ).encode()


def metadata_digest(value: dict[str, MetadataValue]) -> str:
    """Bind the workflow to the canonical metadata on its artifact reference."""
    return hashlib.sha256(_encoded(value)).hexdigest()
