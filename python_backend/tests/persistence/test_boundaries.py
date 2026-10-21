"""Tests that the persistence layer stays on its side of the boundary.

The authorization domain is pure. It must not reach a database driver, and
the persistence layer must not reach the HTTP layer. The dependency runs one
way: the adapter knows the domain, the domain never knows the adapter.
"""

import os
import subprocess
import sys


def _environment_without_authentication() -> dict[str, str]:
    """Return the process environment without any authentication settings."""
    return {
        name: value
        for name, value in os.environ.items()
        if not name.startswith("HAWKI_BACKEND_AUTH_")
    }


def _run(program: str) -> subprocess.CompletedProcess[str]:
    """Run a program in a fresh interpreter."""
    return subprocess.run(
        [sys.executable, "-c", program],
        capture_output=True,
        text=True,
        env=_environment_without_authentication(),
        check=False,
    )


def test_the_authorization_domain_does_not_pull_in_a_database() -> None:
    program = (
        "import sys\n"
        "import hawki_backend.authorization\n"
        "print(','.join(sorted(name for name in sys.modules "
        "if name.split('.')[0] in ('sqlalchemy', 'alembic', 'psycopg'))))\n"
    )

    result = _run(program)

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == ""


def test_the_authorization_domain_does_not_import_the_persistence_layer() -> None:
    program = (
        "import sys\n"
        "import hawki_backend.authorization\n"
        "print('hawki_backend.persistence' in sys.modules)\n"
    )

    result = _run(program)

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == "False"


def test_the_persistence_layer_does_not_pull_in_the_http_layer() -> None:
    program = (
        "import sys\n"
        "import hawki_backend.persistence\n"
        "print(','.join(sorted(name for name in sys.modules "
        "if name.split('.')[0] in ('fastapi', 'starlette'))))\n"
    )

    result = _run(program)

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == ""


def test_the_persistence_layer_imports_the_authorization_boundary() -> None:
    program = (
        "import sys\n"
        "import hawki_backend.persistence\n"
        "print('hawki_backend.authorization.ports' in sys.modules)\n"
    )

    result = _run(program)

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == "True"
