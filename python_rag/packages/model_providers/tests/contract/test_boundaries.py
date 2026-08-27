"""Static ownership guards for the model-providers package."""

from pathlib import Path


PACKAGE_ROOT = Path(__file__).resolve().parents[2]
SOURCE = PACKAGE_ROOT / "src" / "hawki_model_providers"


def test_model_provider_configuration_has_no_request_or_authorization_objects() -> None:
    assert not (SOURCE / "overrides.py").exists()
    configuration = (SOURCE / "configuration.py").read_text(encoding="utf-8")
    assert "authorized_scope" not in configuration
    assert "workflow_input" not in configuration
