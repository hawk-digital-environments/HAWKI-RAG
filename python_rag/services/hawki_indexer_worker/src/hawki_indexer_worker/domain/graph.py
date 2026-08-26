"""Indexer-owned graph triplet normalization and grounding rules."""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Iterable

from hawki_rag_text.terms import STOPWORDS

Triplet = tuple[str, str, str]

_RELATION_ALIASES = {"equivalent_to": "equivalent"}
_IMAGE_EXT_RE = re.compile(r"\.(png|jpe?g|gif|webp|svg)(?:\\?|#|$)", re.IGNORECASE)
_PAGE_MARK_RE = re.compile(r"^(?:p|page)\\s*\\d+$", re.IGNORECASE)
_URL_RE = re.compile(r"^(?:https?://|www\.)", re.IGNORECASE)
_INTERNAL_ID_RE = re.compile(
    r"^(?:ingest|doc|chunk|task)_[a-z0-9][a-z0-9_-]{8,}$", re.IGNORECASE
)
_HASH_RE = re.compile(r"^[a-f0-9]{32,}$", re.IGNORECASE)
_GENERATED_MARKDOWN_RE = re.compile(r"^\d{3,}\.md$", re.IGNORECASE)
_DELIMITER_RESIDUE_MARKERS = ("<|#|", "<|", "|#|", "<|COMPLETE|>", "|COMPLETE|")
_CONVERTER_METADATA_ENTITY_LABELS = {
    "chunk",
    "chunk number",
    "chunks",
    "file",
    "file name",
    "files",
    "next chunk",
    "next file",
    "nextfile",
    "nextchunk",
}
_CONVERTER_METADATA_RELATIONS = {
    "chunk number",
    "chunk number file name",
    "file name",
    "next file",
}
_LOW_VALUE_RELATIONS = {
    "generated",
    "has url",
    "has_url",
    "has title",
    "has_title",
    "is title of",
    "is_title_of",
    "is named as",
    "is_named_as",
    "is equivalent to",
    "is_equivalent_to",
    "is referenced by",
    "is_referenced_by",
    "refers to",
    "refers_to",
}
_KNOWN_PROMPT_EXAMPLE_TERMS = {
    "evolutionary search",
    "gradient based search",
    "gpu hours",
    "nasbench 360",
}


def normalize_graph_write_scope(
    dataset_id: str | None, neo4j_namespace: str | None
) -> tuple[str, str] | None:
    """Return a complete logical graph scope or disable the graph write."""

    normalized_dataset_id = str(dataset_id or "").strip()
    normalized_namespace = str(neo4j_namespace or "").strip()
    if not normalized_dataset_id or not normalized_namespace:
        return None
    return normalized_dataset_id, normalized_namespace


def normalize_relation_label(value: object) -> str:
    """Return a clean and stable predicate label."""

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
    """Keep the first orientation of each normalized relationship."""

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


def _normalize_match_text(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or ""))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower().replace("ß", "ss")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


_NORMALIZED_STOPWORDS = {
    token
    for word in STOPWORDS
    for token in _normalize_match_text(word).split()
    if token
}


def _is_stopword_only(value: str) -> bool:
    tokens = _normalize_match_text(value).split()
    return bool(tokens) and all(token in _NORMALIZED_STOPWORDS for token in tokens)


def _source_contains_label(source: str, label: str) -> bool:
    normalized = _normalize_match_text(label)
    tokens = [
        token
        for token in normalized.split()
        if len(token) >= 3 and token not in _NORMALIZED_STOPWORDS
    ]
    if not tokens:
        return False
    if normalized in source:
        return True
    if len(tokens) >= 2:
        return all(re.search(rf"\b{re.escape(token)}\b", source) for token in tokens)
    return bool(re.search(rf"\b{re.escape(tokens[0])}\b", source))


def _is_noise_entity(value: str) -> bool:
    compact = value.strip()
    normalized = _normalize_match_text(compact)
    return (
        not compact
        or compact in {"[]", "[ ]"}
        or bool(_PAGE_MARK_RE.match(compact))
        or "/images" in compact.lower()
        or "/images_pdf" in compact.lower()
        or bool(_IMAGE_EXT_RE.search(compact.lower()))
        or bool(_URL_RE.match(compact))
        or bool(_INTERNAL_ID_RE.match(compact))
        or bool(_HASH_RE.match(compact))
        or bool(_GENERATED_MARKDOWN_RE.match(compact))
        or any(marker in compact for marker in _DELIMITER_RESIDUE_MARKERS)
        or normalized in _CONVERTER_METADATA_ENTITY_LABELS
        or _is_stopword_only(compact)
    )


def _is_noise_relation(value: str) -> bool:
    compact = value.strip()
    normalized = _normalize_match_text(compact)
    return (
        not compact
        or any(marker in compact for marker in _DELIMITER_RESIDUE_MARKERS)
        or normalized in _CONVERTER_METADATA_RELATIONS
        or normalized in _LOW_VALUE_RELATIONS
        or compact.lower() in _LOW_VALUE_RELATIONS
        or _is_stopword_only(compact)
    )


def clean_triplets(triplets: Iterable[Triplet], **_kwargs: object) -> list[Triplet]:
    """Normalize triplets and remove extraction or converter noise."""

    candidates: list[Triplet] = []
    for subject, relation, obj in triplets:
        normalized_subject = " ".join(str(subject or "").split())
        normalized_relation = normalize_relation_label(relation)
        normalized_object = " ".join(str(obj or "").split())
        if (
            _is_noise_entity(normalized_subject)
            or _is_noise_relation(normalized_relation)
            or _is_noise_entity(normalized_object)
        ):
            continue
        candidates.append((normalized_subject, normalized_relation, normalized_object))
    return dedupe_one_way_triplets(candidates)


def filter_triplets_to_source(
    triplets: Iterable[Triplet], source_text: str, **kwargs: object
) -> list[Triplet]:
    """Keep normalized triplets grounded by at least one source entity."""

    source = _normalize_match_text(source_text)
    cleaned = clean_triplets(triplets, **kwargs)
    if not source:
        return cleaned
    return [
        (subject, relation, obj)
        for subject, relation, obj in cleaned
        if _normalize_match_text(subject) not in _KNOWN_PROMPT_EXAMPLE_TERMS
        and _normalize_match_text(obj) not in _KNOWN_PROMPT_EXAMPLE_TERMS
        and (
            _source_contains_label(source, subject)
            or _source_contains_label(source, obj)
        )
    ]


__all__ = [
    "Triplet",
    "clean_triplets",
    "dedupe_one_way_triplets",
    "filter_triplets_to_source",
    "normalize_graph_write_scope",
    "normalize_relation_label",
]
