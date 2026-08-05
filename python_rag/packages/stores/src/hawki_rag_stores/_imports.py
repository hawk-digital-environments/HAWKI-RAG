"""Runtime dependency imports with actionable failures."""

from __future__ import annotations

from importlib import import_module
from typing import Any


def import_optional_module(module_name: str) -> Any | None:
    """Import a module, returning ``None`` only when that module is absent."""

    try:
        return import_module(module_name)
    except ModuleNotFoundError as exc:
        missing_name = str(getattr(exc, "name", "") or "")
        root_name = module_name.split(".", 1)[0]
        if missing_name in {module_name, root_name} or module_name.startswith(
            f"{missing_name}."
        ):
            return None
        raise


def import_required_module(module_name: str, *, install_hint: str) -> Any:
    """Import a required runtime module or raise a clear configuration error."""

    module = import_optional_module(module_name)
    if module is None:
        raise RuntimeError(f"{module_name} package is required. {install_hint}")
    return module
