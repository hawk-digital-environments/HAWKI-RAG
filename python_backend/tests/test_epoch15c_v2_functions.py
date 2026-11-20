"""Advisory requirements follow actual consumers, independently of URL flags."""

from types import SimpleNamespace
import pytest
from hawki_backend import readiness


@pytest.mark.parametrize(
    "managed,url,text,expected",
    [
        (True, False, False, 3),
        (False, True, False, 3),
        (False, False, False, 0),
        (False, False, True, 1),
    ],
)
def test_exact_function_requirements(managed, url, text, expected):
    seen = set()

    class Catalog:
        def execute(self, statement, parameters=None):
            assert str(statement).startswith("SELECT")
            if parameters and "function" in parameters:
                seen.add(parameters["function"])
            return SimpleNamespace(scalar_one=lambda: "'CREATE'" not in str(statement))

    readiness.verify_privileges(
        Catalog(),
        {
            "HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED": str(managed).lower(),
            "HAWKI_BACKEND_URL_INGESTION_ENABLED": str(url).lower(),
            "HAWKI_BACKEND_TEXT_INGESTION_ENABLED": str(text).lower(),
        },
    )
    assert len(seen) == expected
    assert all(name.endswith("(bigint)") for name in seen)
    if managed or url:
        assert "pg_catalog.pg_try_advisory_lock(bigint)" in seen
        assert "pg_catalog.pg_advisory_unlock(bigint)" in seen
