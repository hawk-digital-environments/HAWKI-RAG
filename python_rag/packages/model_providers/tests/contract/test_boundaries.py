"""Static ownership guards for the model-providers package."""

import ast
from pathlib import Path


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
SOURCE = PACKAGE_ROOT / "src" / "hawki_model_providers"


def test_model_provider_configuration_has_no_request_or_authorization_objects() -> None:
    assert not (SOURCE / "overrides.py").exists()
    configuration = (SOURCE / "configuration.py").read_text(encoding="utf-8")
    assert "authorized_scope" not in configuration
    assert "workflow_input" not in configuration


def test_model_provider_package_exposes_clients_not_consumer_owned_ports() -> None:
    assert not (SOURCE / "ports.py").exists()
    tree = ast.parse((SOURCE / "__init__.py").read_text(encoding="utf-8"))
    eager_imports = {
        node.module
        for node in tree.body
        if isinstance(node, ast.ImportFrom) and node.module
    }
    assert "hawki_model_providers.litellm" not in eager_imports
    assert "hawki_model_providers.ollama" not in eager_imports


def test_legacy_root_exports_resolve_to_the_concrete_clients() -> None:
    from hawki_model_providers import (
        LiteLLMProvider,
        OllamaProvider,
        create_model_provider,
        get_provider,
    )
    from hawki_model_providers.factory import (
        create_model_provider as canonical_factory,
    )
    from hawki_model_providers.factory import get_provider as legacy_factory
    from hawki_model_providers.litellm import LiteLLMProvider as CanonicalLiteLLM
    from hawki_model_providers.ollama import OllamaProvider as CanonicalOllama

    assert LiteLLMProvider is CanonicalLiteLLM
    assert OllamaProvider is CanonicalOllama
    assert create_model_provider is canonical_factory
    assert get_provider is legacy_factory
