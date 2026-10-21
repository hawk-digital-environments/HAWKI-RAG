"""Tests that the package imports without depending on import order.

The settings module needs the principal kind from the authentication
package, and the authentication package needs the settings module. These
tests fail if that ever turns into a cycle that only works because of the
order in which other modules happen to be imported.
"""

import os
import subprocess
import sys

import pytest

_MODULES = (
    "hawki_backend.settings",
    "hawki_backend.auth",
    "hawki_backend.auth.models",
    "hawki_backend.auth.errors",
    "hawki_backend.auth.jwks",
    "hawki_backend.auth.jwt",
    "hawki_backend.auth.dependencies",
    "hawki_backend.main",
)


def _environment_without_authentication() -> dict[str, str]:
    """Return the process environment without any authentication settings."""
    return {
        name: value
        for name, value in os.environ.items()
        if not name.startswith("HAWKI_BACKEND_AUTH_")
    }


@pytest.mark.parametrize("module", _MODULES)
def test_a_module_imports_in_a_fresh_interpreter(module: str) -> None:
    result = subprocess.run(
        [sys.executable, "-c", f"import {module}"],
        capture_output=True,
        text=True,
        env=_environment_without_authentication(),
        check=False,
    )

    assert result.returncode == 0, result.stderr


def test_the_settings_module_imports_before_the_authentication_package() -> None:
    result = subprocess.run(
        [sys.executable, "-c", "import hawki_backend.settings"],
        capture_output=True,
        text=True,
        env=_environment_without_authentication(),
        check=False,
    )

    assert result.returncode == 0, result.stderr
    assert "circular import" not in result.stderr
