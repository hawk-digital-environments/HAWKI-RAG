"""Document extraction preserves candidate order and reranker text behavior."""

from copy import deepcopy
from typing import Any

from hawki_bridge.adapters.reranker_documents import extract_rerank_documents


def test_extract_rerank_documents_preserves_order_and_bounds_text() -> None:
    candidates: list[dict[str, Any]] = [
        {
            "payload": {
                "snippet": "snippet\x00\n",
                "content": "content",
                "title": "title",
            }
        },
        {"payload": {"snippet": "", "content": "content", "title": "title"}},
        {"payload": {"snippet": "", "content": "", "title": "title"}},
        {},
        {"payload": None},
        {"payload": {}},
        {"payload": {"snippet": "", "content": "", "title": ""}},
        {"payload": {"content": "x" * 2001}},
        {"payload": {"content": "\x00" + "y" * 2000}},
    ]
    original = deepcopy(candidates)

    documents = extract_rerank_documents(candidates)

    assert documents == [
        "snippet\n",
        "content",
        "title",
        "",
        "",
        "",
        "",
        "x" * 2000,
        "y" * 1999,
    ]
    assert candidates == original
