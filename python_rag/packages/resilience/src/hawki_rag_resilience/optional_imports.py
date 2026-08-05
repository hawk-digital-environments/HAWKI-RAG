"""Small helpers for runtime-only optional dependency imports."""

from __future__ import annotations

from importlib import import_module
from typing import Any


def import_optional_module(module_name: str) -> Any | None:
    """Return an imported module, or ``None`` when that module is not installed."""

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
    """Import a runtime dependency and raise a clear install error when missing."""

    module = import_optional_module(module_name)
    if module is None:
        raise RuntimeError(f"{module_name} package is required. {install_hint}")
    return module
