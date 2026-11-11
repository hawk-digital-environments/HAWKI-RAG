"""Gateway proxy config rendering from the canonical model keys."""

from __future__ import annotations

from pathlib import Path

import yaml

from hawki_model_providers import gateway_config


TEMPLATE_PATH = Path(gateway_config.__file__).with_name("gateway_config.yaml.j2")

BASE_ENV = {
    "EMBED_MODEL": "ollama/bge-m3",
    "CHAT_MODEL": "ollama_chat/llama3.1:8b",
    "VISION_MODEL": "ollama_chat/qwen2.5vl:7b",
    "OLLAMA_API_URL": "http://hawki_ollama:11434/api",
}


def _routes_by_name(env: dict[str, str]) -> dict[str, dict[str, str | None]]:
    return {route["name"]: route for route in gateway_config.build_routes(env)}


def test_canonical_models_render_as_passthrough_routes() -> None:
    routes = _routes_by_name(dict(BASE_ENV))

    # Exactly one route per canonical model, named by the full prefixed
    # string the client sends as the deployment name.
    assert list(routes) == [
        "ollama_chat/llama3.1:8b",
        "ollama/bge-m3",
        "ollama_chat/qwen2.5vl:7b",
    ]
    assert routes["ollama/bge-m3"]["model"] == "ollama/bge-m3"
    # The trailing /api of OLLAMA_API_URL is stripped for the proxy base and
    # the local routes carry no credential.
    for route in routes.values():
        assert route["api_base"] == "http://hawki_ollama:11434"
        assert route["api_key"] is None


def test_no_legacy_alias_routes_are_emitted() -> None:
    # The retired hawki-* proxy aliases are gone; every deployment name is
    # a canonical provider-prefixed string.
    routes = _routes_by_name(dict(BASE_ENV))

    assert all(not name.startswith("hawki-") for name in routes)


def test_allowlist_plurals_render_hosted_routes_with_env_references() -> None:
    env = dict(BASE_ENV)
    env.update(
        {
            "CHAT_MODELS": (
                "ollama_chat/llama3.1:8b,openai/gpt-4.1-mini,"
                "anthropic/claude-sonnet-4-5-20250929"
            ),
            "EMBED_MODELS": "ollama/bge-m3,openai/text-embedding-3-small",
            "VISION_MODELS": (
                "ollama_chat/qwen2.5vl:7b,openai/gpt-4.1-mini,"
                "anthropic/claude-sonnet-4-5-20250929"
            ),
        }
    )
    routes = _routes_by_name(env)

    assert routes["openai/gpt-4.1-mini"]["api_key"] == "os.environ/OPENAI_API_KEY"
    assert routes["openai/text-embedding-3-small"]["api_key"] == (
        "os.environ/OPENAI_API_KEY"
    )
    assert routes["anthropic/claude-sonnet-4-5-20250929"]["api_key"] == (
        "os.environ/ANTHROPIC_API_KEY"
    )
    # Without an explicit API base the SDK default endpoint applies.
    assert routes["openai/gpt-4.1-mini"]["api_base"] is None


def test_one_passthrough_route_per_configured_model() -> None:
    env = dict(BASE_ENV)
    env.update({"CHAT_MODELS": "openai/gpt-4.1-mini,ollama_chat/llama3.1:8b"})

    routes = _routes_by_name(env)

    # Duplicate allowlist entries collapse into one route.
    assert list(routes).count("ollama_chat/llama3.1:8b") == 1
    # Bare allowlist names pass through without transport parameters.
    env["EMBED_MODELS"] = "nomic-embed-text"
    routes = _routes_by_name(env)
    assert routes["nomic-embed-text"]["api_base"] is None
    assert routes["nomic-embed-text"]["api_key"] is None


def test_canonical_defaults_apply_without_any_environment() -> None:
    routes = _routes_by_name({})

    assert routes["ollama_chat/llama3.1:8b"]["model"] == "ollama_chat/llama3.1:8b"
    assert routes["ollama/bge-m3"]["model"] == "ollama/bge-m3"
    assert routes["ollama_chat/qwen2.5vl:7b"]["model"] == "ollama_chat/qwen2.5vl:7b"
    assert routes["ollama_chat/llama3.1:8b"]["api_base"] == "http://ollama:11434"


def test_standard_api_base_overrides_reach_hosted_routes() -> None:
    env = dict(BASE_ENV)
    env.update(
        {
            "CHAT_MODELS": "openai/gpt-4.1-mini,anthropic/claude-sonnet-4-5-20250929",
            "OPENAI_API_BASE": "https://proxy.corp/openai/v1",
            "ANTHROPIC_API_BASE": "https://proxy.corp/anthropic",
        }
    )
    routes = _routes_by_name(env)

    assert routes["openai/gpt-4.1-mini"]["api_base"] == "https://proxy.corp/openai/v1"
    assert routes["anthropic/claude-sonnet-4-5-20250929"]["api_base"] == (
        "https://proxy.corp/anthropic"
    )


def test_rendered_config_is_valid_yaml_with_expected_routes() -> None:
    env = dict(BASE_ENV)
    env.update({"CHAT_MODELS": "openai/gpt-4.1-mini"})

    rendered = gateway_config.render(
        gateway_config.build_routes(env),
        TEMPLATE_PATH,
        master_key=gateway_config._master_key(env),
    )
    parsed = yaml.safe_load(rendered)

    model_names = [entry["model_name"] for entry in parsed["model_list"]]
    assert model_names == [
        "ollama_chat/llama3.1:8b",
        "openai/gpt-4.1-mini",
        "ollama/bge-m3",
        "ollama_chat/qwen2.5vl:7b",
    ]

    openai_route = next(
        entry
        for entry in parsed["model_list"]
        if entry["model_name"] == "openai/gpt-4.1-mini"
    )
    assert openai_route["litellm_params"]["api_key"] == "os.environ/OPENAI_API_KEY"

    # Proxy behaviour settings carry over from the previous static config.
    assert parsed["litellm_settings"] == {"drop_params": True, "request_timeout": 240}


def test_master_key_matches_the_client_bearer_by_default() -> None:
    env = dict(BASE_ENV)

    assert gateway_config._master_key(env) == "sk-noauth"

    rendered = gateway_config.render(
        gateway_config.build_routes(env),
        TEMPLATE_PATH,
        master_key=gateway_config._master_key(env),
    )
    parsed = yaml.safe_load(rendered)
    # Current LiteLLM images refuse to boot without a master key; the
    # shared placeholder keeps the unauthenticated local gateway bootable
    # and matches the client's bearer value.
    assert parsed["general_settings"]["master_key"] == "sk-noauth"


def test_env_file_preview_renders_to_stdout_and_overrides_ambient(
    tmp_path, monkeypatch
) -> None:
    """The --env/--output - preview renders the file's values, not ambient.

    Runs the module entrypoint in a subprocess so the dotenv mutation of
    os.environ cannot leak into the test process, and proves the env file
    wins over a contradicting ambient variable (override=True), mirroring
    how the container receives its env_file.
    """

    import os
    import subprocess
    import sys

    env_file = tmp_path / "preview.env"
    env_file.write_text(
        "EMBED_MODEL=ollama/bge-m3\n"
        "CHAT_MODEL=ollama_chat/llama3.1:8b\n"
        "VISION_MODEL=ollama_chat/qwen2.5vl:7b\n"
        "VISION_MODELS=ollama_chat/qwen2.5vl:7b,openai/gpt-4.1-mini\n"
        "OLLAMA_API_URL=http://hawki_ollama:11434/api\n",
        encoding="utf-8",
    )
    process = subprocess.run(
        [
            sys.executable,
            "-m",
            "hawki_model_providers.gateway_config",
            "--env",
            str(env_file),
            "--output",
            "-",
        ],
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, "EMBED_MODEL": "ambient-should-lose"},
    )

    parsed = yaml.safe_load(process.stdout)
    model_names = [entry["model_name"] for entry in parsed["model_list"]]
    assert "ollama/bge-m3" in model_names
    assert "openai/gpt-4.1-mini" in model_names
    assert "ambient-should-lose" not in process.stdout


def test_master_key_references_the_configured_proxy_key() -> None:
    env = dict(BASE_ENV)
    env.update({"LITELLM_API_KEY": "sk-proxy-secret"})

    assert gateway_config._master_key(env) == "os.environ/LITELLM_API_KEY"

    rendered = gateway_config.render(
        gateway_config.build_routes(env),
        TEMPLATE_PATH,
        master_key=gateway_config._master_key(env),
    )
    assert "sk-proxy-secret" not in rendered
    assert (
        yaml.safe_load(rendered)["general_settings"]["master_key"]
        == "os.environ/LITELLM_API_KEY"
    )
