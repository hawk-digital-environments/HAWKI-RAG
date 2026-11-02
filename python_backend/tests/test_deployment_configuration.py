"""Keep the documented deployment environment valid for the runtime loaders."""

from pathlib import Path

from hawki_backend.query.settings import load_query_settings
from hawki_backend.settings import load_auth_settings, load_database_settings


def test_environment_example_matches_runtime_settings() -> None:
    """Operator examples must pass startup validation without contacting services."""
    example = Path(__file__).resolve().parents[1] / ".env.example"
    environ = {}
    for line in example.read_text(encoding="utf-8").splitlines():
        if line.startswith("HAWKI_BACKEND_"):
            name, value = line.split("=", 1)
            environ[name] = value

    assert load_auth_settings(environ) is not None
    assert load_database_settings(environ) is not None
    query = load_query_settings(environ)
    assert query is not None
    assert query.resolve_scope("dataset-example") is not None
    assert query.models_for("ollama") is not None
