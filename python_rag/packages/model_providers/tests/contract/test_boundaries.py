"""Static ownership guards for the model-providers package."""

import ast
from pathlib import Path
import sys


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
SOURCE = PACKAGE_ROOT / "src" / "hawki_model_providers"


def _top_level_imports(module: Path) -> set[str]:
    """Return the top-level module names a source file imports."""

    tree = ast.parse(module.read_text(encoding="utf-8"))
    names: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            names.update(alias.name.split(".")[0] for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module and node.level == 0:
            names.add(node.module.split(".")[0])
    return names


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


def test_gateway_renderer_imports_stay_image_safe() -> None:
    """The gateway renderer runs inside the stock LiteLLM proxy image.

    Compose mounts this package read-only into that image, which installs
    none of our workspace dependencies. The renderer's import closure must
    therefore stay within the standard library, Jinja2 (a base dependency
    of LiteLLM itself) and this package's stdlib-only ``configuration``
    module — never ``litellm`` or ``requests``.
    """

    renderer = _top_level_imports(SOURCE / "gateway_config.py")
    # python-dotenv ships inside the stock proxy image as a base dependency
    # of litellm itself, so the optional --env preview flag stays
    # image-safe.
    allowed = set(sys.stdlib_module_names) | {
        "jinja2",
        "dotenv",
        "hawki_model_providers",
    }
    assert renderer <= allowed, renderer - allowed

    configuration = _top_level_imports(SOURCE / "configuration.py")
    assert configuration <= set(sys.stdlib_module_names), configuration - set(
        sys.stdlib_module_names
    )
    assert (SOURCE / "gateway_config.yaml.j2").is_file()
