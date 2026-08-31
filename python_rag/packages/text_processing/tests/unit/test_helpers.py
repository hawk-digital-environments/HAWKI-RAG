"""Pure text term, tag, and chunk behavior."""

from __future__ import annotations


def test_text_helper_modules_preserve_term_tag_and_chunk_rules() -> None:
    from hawki_rag_text.chunking import split_text_into_chunks
    from hawki_rag_text.tags import fallback_tags, flatten_keywords, normalize_tags
    from hawki_rag_text.terms import STOPWORDS, extract_terms

    assert len(STOPWORDS) > 1800
    assert "die" in STOPWORDS
    assert "; german stopwords" not in STOPWORDS
    assert extract_terms("Wooden trains and Teddy-Bears")[:2] == ["wooden", "trains"]
    assert extract_terms("diese Universität") == ["universität"]
    assert flatten_keywords("Keywords: 1. Wooden toys; 2. Teddy bears") == [
        "Wooden toys",
        "Teddy bears",
    ]
    assert normalize_tags(["Wooden-Toys", "wooden toys", "A"], limit=3) == [
        "wooden toys"
    ]
    assert fallback_tags("train train bear bear table", limit=2) == ["train", "bear"]
    assert split_text_into_chunks(
        "para one\n\npara two\n\npara three", target=13, overlap=0
    ) == [
        "para one",
        "para two",
        "para three",
    ]


def test_strip_control_characters_preserves_text_whitespace_only() -> None:
    from hawki_rag_text.safety import strip_control_characters

    assert strip_control_characters(None) == ""
    assert strip_control_characters("a\x00b\tc\nd\re\x1f") == "ab\tc\nd\re"
