"""Model warmup activity behavior against faked providers and HTTP."""

from __future__ import annotations

from typing import Any

import pytest

from hawki_workflow_worker.activities import model_warmup as activities


class _FakeProvider:
    def __init__(self) -> None:
        self.embed_calls: list[str] = []
        self.chat_calls: list[tuple[str, list[dict[str, str]]]] = []
        self.vision_calls: list[str] = []
        self.embed_result: list[float] = [0.1, 0.2, 0.3]
        self.chat_result: str = "OK"
        self.vision_result: str = "OK"
        self.embed_model: str | None = None
        self.rag_model: str | None = None
        self.vision_model: str | None = None

    def embed(self, text: str) -> list[float]:
        self.embed_calls.append(text)
        return self.embed_result

    def chat(self, system: str, messages: list[dict[str, str]]) -> str:
        self.chat_calls.append((system, messages))
        return self.chat_result

    def vision_chat(self, system: str, prompt: str) -> str:
        self.vision_calls.append(prompt)
        return self.vision_result


class _ProviderFactory:
    def __init__(self) -> None:
        self.provider = _FakeProvider()
        self.requested_names: list[str] = []

    def __call__(self, name: str) -> _FakeProvider:
        self.requested_names.append(name)
        return self.provider


@pytest.fixture(autouse=True)
def _clean_model_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in ("EMBED_MODEL", "CHAT_MODEL", "VISION_MODEL", "RAG_DEFAULT_PROVIDER"):
        monkeypatch.delenv(name, raising=False)


@pytest.fixture
def provider_factory(monkeypatch: pytest.MonkeyPatch) -> _ProviderFactory:
    factory = _ProviderFactory()
    monkeypatch.setattr(activities, "create_model_provider", factory)
    return factory


class _FakeResponse:
    def __init__(self, payload: dict[str, Any], status_code: int = 200) -> None:
        self._payload = payload
        self.status_code = status_code

    def json(self) -> dict[str, Any]:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


class _FakeHttp:
    def __init__(self) -> None:
        self.posts: list[tuple[str, dict[str, Any]]] = []
        self.gets: list[str] = []
        self._next_post_response: list[_FakeResponse] = []
        self._next_get_response: list[_FakeResponse] = []

    def post(self, url: str, *, json: dict[str, Any], timeout: float):
        self.posts.append((url, json))
        return self._next_post_response.pop(0)

    def get(self, url: str, *, timeout: float):
        self.gets.append(url)
        return self._next_get_response.pop(0)


@pytest.fixture
def fake_http(monkeypatch: pytest.MonkeyPatch) -> _FakeHttp:
    http = _FakeHttp()
    monkeypatch.setattr(activities.requests, "post", http.post)
    monkeypatch.setattr(activities.requests, "get", http.get)
    return http


def test_warm_model_embeds_through_the_configured_provider(
    provider_factory: _ProviderFactory,
) -> None:
    result = activities.warm_model(
        {"provider": "ollama", "model": "bge-m3", "kind": "embed", "query": "warm it"}
    )

    assert result["ok"] is True
    assert result["provider"] == "ollama"
    assert result["detail"] == "embedding dim=3"
    assert provider_factory.requested_names == ["ollama"]
    assert provider_factory.provider.embed_calls == ["warm it"]
    assert provider_factory.provider.embed_model == "bge-m3"


def test_warm_model_chat_validates_reply(provider_factory: _ProviderFactory) -> None:
    provider_factory.provider.chat_result = "  "

    with pytest.raises(RuntimeError, match="empty chat reply"):
        activities.warm_model(
            {"provider": "litellm", "model": "llama3.1:8b", "kind": "chat"}
        )


def test_warm_model_vision_uses_vision_chat(provider_factory: _ProviderFactory) -> None:
    result = activities.warm_model(
        {"provider": "ollama", "model": "qwen2.5vl:7b", "kind": "vision"}
    )

    assert result["ok"] is True
    provider = provider_factory.provider
    assert provider.vision_calls and provider.vision_model == "qwen2.5vl:7b"


def test_warm_model_requires_a_model_name(provider_factory: _ProviderFactory) -> None:
    with pytest.raises(RuntimeError, match="requires a model name"):
        activities.warm_model({"provider": "ollama", "kind": "embed"})


def test_warm_retrieval_posts_real_query_to_bridge(fake_http, monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "ollama")
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("CHAT_MODEL", "ollama_chat/llama3.1:8b")
    monkeypatch.setenv("VISION_MODEL", "ollama_chat/qwen2.5vl:7b")
    monkeypatch.setenv("HAWKI_RAG_BRIDGE_URL", "http://hawki_rag_bridge")
    monkeypatch.setenv("QDRANT_HTTP_URL", "http://qdrant:6333")
    monkeypatch.delenv("MODEL_WARMUP_QDRANT_COLLECTION", raising=False)
    monkeypatch.setenv("MODEL_WARMUP_DATASET_ID", "model-warmup")
    fake_http._next_get_response.append(
        _FakeResponse({"result": {"collections": [{"name": "hawki_assistant_1"}]}})
    )
    fake_http._next_post_response.append(
        _FakeResponse({"ok": True, "count": 1, "hits": [{"score": 0.5}]}),
    )

    result = activities.warm_retrieval({"query": "warm it"})

    assert result["ok"] is True
    assert result["skipped"] is False
    assert result["hits"] == 1
    assert result["provider"] == "ollama"

    url, payload = fake_http.posts[0]
    assert url == "http://hawki_rag_bridge/query"
    scope = payload["authorized_scope"]
    assert scope["qdrant_collection"] == "hawki_assistant_1"
    assert scope["embedding_provider"] == "ollama"
    assert scope["embedding_model"] == "bge-m3"
    assert payload["provider"] == "ollama"
    assert payload["chat_model"] == "llama3.1:8b"
    assert payload["vision_model"] == "qwen2.5vl:7b"
    assert payload["generate"] is False
    assert payload["fast_mode"] is True


def test_warm_retrieval_follows_litellm_configuration(fake_http, monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "litellm")
    monkeypatch.setenv("EMBED_MODEL", "hawki-ollama-embedding")
    monkeypatch.setenv("CHAT_MODEL", "hawki-gpt-chat")
    monkeypatch.setenv("HAWKI_RAG_BRIDGE_URL", "http://hawki_rag_bridge")
    monkeypatch.setenv("MODEL_WARMUP_QDRANT_COLLECTION", "hawki_assistant_9")
    fake_http._next_post_response.append(_FakeResponse({"ok": True, "count": 0}))

    result = activities.warm_retrieval({"query": "warm it"})

    assert result["collection"] == "hawki_assistant_9"
    assert fake_http.gets == []
    payload = fake_http.posts[0][1]
    assert payload["provider"] == "litellm"
    assert payload["authorized_scope"]["embedding_provider"] == "litellm"
    assert payload["authorized_scope"]["embedding_model"] == "hawki-ollama-embedding"
    assert payload["chat_model"] == "hawki-gpt-chat"


def test_warm_retrieval_skips_when_no_collection_exists(fake_http, monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "ollama")
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("QDRANT_HTTP_URL", "http://qdrant:6333")
    monkeypatch.delenv("MODEL_WARMUP_QDRANT_COLLECTION", raising=False)
    fake_http._next_get_response.append(
        _FakeResponse({"result": {"collections": []}})
    )

    result = activities.warm_retrieval({"query": "warm it"})

    assert result["ok"] is True
    assert result["skipped"] is True
    assert fake_http.posts == []


def test_warm_retrieval_requires_an_embedding_model(fake_http, monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "ollama")
    monkeypatch.delenv("EMBED_MODEL", raising=False)

    with pytest.raises(RuntimeError, match="EMBED_MODEL"):
        activities.warm_retrieval({"query": "warm it"})


def test_warm_retrieval_raises_when_bridge_reports_not_ok(fake_http, monkeypatch) -> None:
    monkeypatch.setenv("RAG_DEFAULT_PROVIDER", "ollama")
    monkeypatch.setenv("EMBED_MODEL", "ollama/bge-m3")
    monkeypatch.setenv("HAWKI_RAG_BRIDGE_URL", "http://hawki_rag_bridge")
    monkeypatch.setenv("MODEL_WARMUP_QDRANT_COLLECTION", "hawki_assistant_1")
    fake_http._next_post_response.append(_FakeResponse({"ok": False}))

    with pytest.raises(RuntimeError, match="ok=false"):
        activities.warm_retrieval({"query": "warm it"})
