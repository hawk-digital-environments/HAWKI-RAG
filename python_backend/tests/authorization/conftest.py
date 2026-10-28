"""Shared fixtures for the authorization tests.

No key material is involved: the authorization boundary starts from an
already authenticated principal, so the tests only need to build one.
"""

from collections.abc import Callable
from datetime import datetime, timezone

import pytest

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

TENANT = "tenant-a"
OTHER_TENANT = "tenant-b"
DATASET = "dataset-1"
OTHER_DATASET = "dataset-2"


@pytest.fixture
def make_principal() -> Callable[..., AuthenticatedPrincipal]:
    """Return a factory that builds an authenticated principal."""

    def factory(
        *,
        kind: PrincipalKind = PrincipalKind.USER,
        subject: str = "user-1",
        tenant_id: str = TENANT,
        client_id: str = "hawki-console",
        scopes: frozenset[str] = frozenset({"rag:query", "rag:ingest"}),
    ) -> AuthenticatedPrincipal:
        """Return a principal with the given attributes."""
        return AuthenticatedPrincipal(
            kind=kind,
            subject=subject,
            tenant_id=tenant_id,
            client_id=client_id,
            scopes=scopes,
            issuer="https://issuer.example",
            expires_at=datetime(2026, 1, 1, tzinfo=timezone.utc),
        )

    return factory
