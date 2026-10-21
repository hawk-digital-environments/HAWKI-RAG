"""Authentication boundary for the backend.

This module re-exports the model and error API only. The JWT
authenticator, the key providers and the FastAPI dependencies depend on the
settings module, which in turn needs the principal kind from this package.
Re-exporting them here would make the two modules circular, so they are
imported from their own modules instead:

    from hawki_backend.auth.jwks import JwksKeyProvider
    from hawki_backend.auth.jwt import JwtAuthenticator
    from hawki_backend.auth.dependencies import get_authenticated_principal
"""

from hawki_backend.auth.errors import (
    AuthenticationError,
    AuthenticationUnavailableError,
    AuthFailureReason,
    InsufficientScopeError,
)
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

__all__ = [
    "AuthenticationError",
    "AuthenticationUnavailableError",
    "AuthFailureReason",
    "AuthenticatedPrincipal",
    "InsufficientScopeError",
    "PrincipalKind",
]
