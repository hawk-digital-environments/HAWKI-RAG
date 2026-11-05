"""Render the LiteLLM gateway proxy config from the canonical model keys.

The optional gateway container (Compose profile ``litellm``) runs the stock
LiteLLM proxy image, whose static YAML config only supports scalar
``os.environ/VAR`` substitution — which is why the gateway historically
needed one environment variable per route and parameter. This module derives
the whole ``model_list`` at container startup instead, from keys that already
exist for the rest of the stack:

* ``EMBED_MODEL`` / ``CHAT_MODEL`` / ``VISION_MODEL`` — canonical selection
* ``EMBED_MODELS`` / ``CHAT_MODELS`` / ``VISION_MODELS`` — allowlist plurals
  (they also feed the Settings UI, so the gateway menu and the UI cannot
  drift apart)
* ``OLLAMA_API_URL`` — local Ollama base; the trailing ``/api`` is stripped
* ``LITELLM_API_KEY`` — proxy master key (a shared placeholder applies when
  unset, matching the client's bearer value)
* ``OPENAI_API_KEY`` / ``ANTHROPIC_API_KEY`` — standard upstream credentials
* ``OPENAI_API_BASE`` / ``ANTHROPIC_API_BASE`` — optional standard endpoints
  for routing hosted providers through a corporate proxy

Every configured model gets exactly one passthrough route named by its
canonical provider-prefixed string, with the credential chosen by prefix.
Hosted routes reference the credential through ``os.environ/...`` so secrets
never land in the rendered file; routes without a configured key stay
listed and fail closed.

The module runs inside the unmodified proxy image through a read-only mount
of this package, so its import closure is restricted to the standard
library, Jinja2 (a base dependency of LiteLLM itself) and the stdlib-only
:mod:`hawki_model_providers.configuration` — a contract test pins this.
"""

from __future__ import annotations

import argparse
import os
from collections.abc import Mapping
from pathlib import Path

import jinja2

from hawki_model_providers.configuration import (
    OLLAMA_ROUTING_PREFIXES,
    UNAUTHENTICATED_PROXY_KEY,
    ollama_api_base,
    routing_prefix,
)


CANONICAL_DEFAULTS = {
    "EMBED_MODEL": "ollama/bge-m3",
    "CHAT_MODEL": "ollama_chat/llama3.1:8b",
    "VISION_MODEL": "ollama_chat/qwen2.5vl:7b",
}

# Standard LiteLLM/SDK credential variables, resolved by the proxy at load
# time so the rendered file never contains secret material.
KEY_ENV_BY_PREFIX = {
    "openai": "OPENAI_API_KEY",
    "anthropic": "ANTHROPIC_API_KEY",
    "gemini": "GEMINI_API_KEY",
}

# Optional standard endpoints (corporate proxies); unset means the SDK
# default endpoint applies.
API_BASE_ENV_BY_PREFIX = {
    "openai": "OPENAI_API_BASE",
    "anthropic": "ANTHROPIC_API_BASE",
}

_KIND_SINGULAR = {
    "chat": "CHAT_MODEL",
    "embed": "EMBED_MODEL",
    "vision": "VISION_MODEL",
}

_KIND_PLURAL = {
    "chat": "CHAT_MODELS",
    "embed": "EMBED_MODELS",
    "vision": "VISION_MODELS",
}


def _csv(value: str | None) -> list[str]:
    return [entry.strip() for entry in str(value or "").split(",") if entry.strip()]


def _first(value: str | None, fallback: str) -> str:
    return str(value or "").strip() or fallback


def _kind_models(env: Mapping[str, str], kind: str) -> list[str]:
    """Canonical model first, then the allowlist plural, order preserved."""

    singular = _first(
        env.get(_KIND_SINGULAR[kind]), CANONICAL_DEFAULTS[_KIND_SINGULAR[kind]]
    )
    models = [singular]
    for model in _csv(env.get(_KIND_PLURAL[kind])):
        if model not in models:
            models.append(model)
    return models


def _hosted_params(env: Mapping[str, str], prefix: str) -> tuple[str | None, str]:
    """API base override and credential reference for a hosted prefix."""

    api_base = env.get(API_BASE_ENV_BY_PREFIX[prefix], "").strip() or None
    return api_base, f"os.environ/{KEY_ENV_BY_PREFIX[prefix]}"


def _master_key(env: Mapping[str, str]) -> str:
    """Proxy master key: the configured credential or the shared placeholder.

    Current LiteLLM images refuse to boot without a master key. With a
    configured ``LITELLM_API_KEY`` the proxy resolves it from the
    environment (keeping secrets out of the rendered file); otherwise the
    shared placeholder keeps the default local gateway bootable and matches
    the client's unauthenticated bearer value.
    """

    configured = env.get("LITELLM_API_KEY", "").strip()
    if configured:
        return "os.environ/LITELLM_API_KEY"
    return UNAUTHENTICATED_PROXY_KEY


def build_routes(env: Mapping[str, str]) -> list[dict[str, str | None]]:
    """Build the gateway ``model_list`` routes from the environment."""

    routes: list[dict[str, str | None]] = []
    names_seen: set[str] = set()

    def add(
        name: str,
        model: str,
        api_base: str | None = None,
        api_key: str | None = None,
    ) -> None:
        if name in names_seen:
            return
        names_seen.add(name)
        routes.append(
            {"name": name, "model": model, "api_base": api_base, "api_key": api_key}
        )

    ollama_base = ollama_api_base(env.get("OLLAMA_API_URL", ""))
    models_by_kind = {
        kind: _kind_models(env, kind) for kind in ("chat", "embed", "vision")
    }

    # One passthrough route per configured model, named by the canonical
    # provider-prefixed string the client sends as the deployment name.
    for kind in ("chat", "embed", "vision"):
        for model in models_by_kind[kind]:
            prefix = routing_prefix(model)
            if prefix in OLLAMA_ROUTING_PREFIXES:
                add(model, model, api_base=ollama_base)
            elif prefix in KEY_ENV_BY_PREFIX:
                api_base, api_key = _hosted_params(env, prefix)
                add(model, model, api_base=api_base, api_key=api_key)
            else:
                # Bare proxy aliases are covered by the legacy routes above;
                # unknown prefixes pass through untouched.
                add(model, model)

    return routes


def render(
    routes: list[dict[str, str | None]], template_path: Path, *, master_key: str = ""
) -> str:
    """Render the route list through the Jinja2 template."""

    environment = jinja2.Environment(
        loader=jinja2.FileSystemLoader(str(template_path.parent)),
        trim_blocks=True,
        lstrip_blocks=True,
        keep_trailing_newline=True,
        autoescape=False,
    )
    template = environment.get_template(template_path.name)
    return template.render(routes=routes, master_key=master_key)


def main(argv: list[str] | None = None) -> int:
    default_template = Path(__file__).with_name("gateway_config.yaml.j2")
    parser = argparse.ArgumentParser(description="Render the LiteLLM gateway config.")
    parser.add_argument(
        "--template",
        type=Path,
        default=default_template,
        help="Jinja2 template to render (default: %(default)s)",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=Path("/tmp/config.yaml"),
        help="Rendered proxy config path, or '-' for stdout (default: %(default)s)",
    )
    parser.add_argument(
        "--env",
        type=Path,
        default=None,
        help=(
            "Optional env file to load before rendering (host-side preview; "
            "values override the ambient environment, mirroring how the "
            "container receives its env_file)"
        ),
    )
    args = parser.parse_args(argv)

    if args.env is not None:
        from dotenv import load_dotenv

        load_dotenv(args.env, override=True)

    routes = build_routes(os.environ)
    rendered = render(routes, args.template, master_key=_master_key(os.environ))
    if str(args.output) == "-":
        import sys

        sys.stdout.write(rendered)
    else:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered, encoding="utf-8")
        print(f"rendered {len(routes)} gateway routes -> {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
