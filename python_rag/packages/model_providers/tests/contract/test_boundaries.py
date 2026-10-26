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


def test_native_clients_are_removed_and_only_sdk_exports_remain() -> None:
    assert not (SOURCE / "ollama.py").exists()
    assert not (SOURCE / "litellm.py").exists()
    assert not (SOURCE / "ollama_helpers.py").exists()

    from hawki_model_providers import LiteLLMSDKProvider, create_model_provider
    from hawki_model_providers.factory import create_model_provider as canonical

    assert create_model_provider is canonical
    assert LiteLLMSDKProvider is not None
