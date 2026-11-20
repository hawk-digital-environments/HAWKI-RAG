"""Ollama option, endpoint, payload, and embedding helpers."""

from __future__ import annotations

from unittest.mock import patch


def test_ollama_helpers_parse_options_payload_and_fallbacks() -> None:
    from hawki_model_providers.ollama_helpers import (
        build_chat_payload,
        chat_options_from_env,
        clean_embedding_text,
        generate_endpoint_candidates,
        infer_embedding_dim,
    )

    with patch.dict(
        "os.environ",
        {
            "OLLAMA_CHAT_TIMEOUT": "bad",
            "OLLAMA_CHAT_RETRIES": "2",
            "OLLAMA_NUM_PREDICT": "120",
            "OLLAMA_TOP_P": "0.7",
        },
        clear=False,
    ):
        options = chat_options_from_env(None)

    assert options.timeout == 120.0
    assert options.retries == 2
    assert options.num_predict == 120
    assert options.top_p == 0.7
    assert infer_embedding_dim("bge-m3") == 1024
    assert clean_embedding_text("a\x00b\n\n\nc", max_chars=20) == "ab\n\nc"
    assert generate_endpoint_candidates("http://ollama:11434/api") == [
        "http://ollama:11434/api/generate",
        "http://ollama:11434/generate",
        "http://ollama:11434/api/generate",
    ]
    assert (
        build_chat_payload(
            model="m",
            system="s",
            messages=[{"role": "user", "content": "q"}],
            options=options,
        )["options"]["num_predict"]
        == 120
    )
