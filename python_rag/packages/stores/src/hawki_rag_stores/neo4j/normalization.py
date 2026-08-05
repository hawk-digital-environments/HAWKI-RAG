"""Pure normalization helpers shared by graph ingestion and retrieval."""

from __future__ import annotations

import re
from collections.abc import Iterable

Triplet = tuple[str, str, str]

_RELATION_ALIASES = {
    "equivalent_to": "equivalent",
}


def normalize_relation_label(value: object) -> str:
    """Return a clean, stable predicate label for storage and API responses."""
    relation = (
        "".join(
            character
            for character in str(value or "")
            if character in {"\n", "\r", "\t"} or ord(character) >= 32
        )
        .replace("\n", " ")
        .replace("\r", " ")
    )
    if "\t" in relation:
        relation = relation.split("\t", 1)[0]
    if "," in relation:
        relation = relation.split(",", 1)[0]
    relation = " ".join(relation.split()).strip(" ;:")
    if not relation:
        return ""

    relation = relation[:120].rstrip()

    alias_key = re.sub(r"[\s-]+", "_", relation.casefold())
    return _RELATION_ALIASES.get(alias_key, relation)


def dedupe_one_way_triplets(triplets: Iterable[Triplet]) -> list[Triplet]:
    """Keep the first orientation of each normalized relationship.

    Graph extraction can emit the same predicate in both directions. Treat
    those as one logical fact while retaining different predicates between
    the same two entities.
    """
    seen: set[tuple[str, str, str]] = set()
    normalized: list[Triplet] = []

    for raw_subject, raw_relation, raw_object in triplets:
        subject = " ".join(str(raw_subject or "").split())
        relation = normalize_relation_label(raw_relation)
        obj = " ".join(str(raw_object or "").split())
        if not subject or not relation or not obj:
            continue

        key = (subject.casefold(), relation.casefold(), obj.casefold())
        reverse_key = (key[2], key[1], key[0])
        if key in seen or reverse_key in seen:
            continue

        seen.add(key)
        normalized.append((subject, relation, obj))

    return normalized


__all__ = ["Triplet", "dedupe_one_way_triplets", "normalize_relation_label"]
