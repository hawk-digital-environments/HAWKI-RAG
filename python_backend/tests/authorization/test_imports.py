"""Tests that the authorization domain stands on its own.

The authorization boundary answers a question about a principal and a
dataset. It must not drag in an HTTP framework, a database driver or a
network client to do so, and it must not need an environment variable to be
imported.
"""

import os
import subprocess
import sys

import pytest

_MODULES = (
    "hawki_backend.authorization",
    "hawki_backend.authorization.models",
    "hawki_backend.authorization.ports",
    "hawki_backend.authorization.policy",
    "hawki_backend.authorization.reader",
    "hawki_backend.authorization.errors",
)

_FORBIDDEN_MODULES = ("fastapi", "httpx", "jwt", "sqlalchemy", "pydantic")


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


def test_the_authorization_domain_pulls_in_no_framework() -> None:
    program = (
        "import sys\n"
        "import hawki_backend.authorization\n"
        "import hawki_backend.authorization.errors\n"
        "import hawki_backend.authorization.models\n"
        "import hawki_backend.authorization.policy\n"
        "import hawki_backend.authorization.ports\n"
        "import hawki_backend.authorization.reader\n"
        "print(','.join(sorted(name for name in sys.modules "
        "if name in ('fastapi', 'httpx', 'jwt', 'sqlalchemy', 'pydantic'))))\n"
    )
    result = subprocess.run(
        [sys.executable, "-c", program],
        capture_output=True,
        text=True,
        env=_environment_without_authentication(),
        check=False,
    )

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == ""
