"""FastAPI dependencies for the backend's authentication boundary.

The dependency reads a bearer credential, hands it to the authenticator and
returns the principal. Every failure is mapped onto a small set of generic
responses: nothing about the token, the key, the issuer or the client is
ever echoed back to a caller.
"""

from collections.abc import Callable
from typing import Annotated

from fastapi import Depends, HTTPException, Request

from hawki_backend.auth.errors import (
    AuthenticationError,
    AuthenticationUnavailableError,
)
from hawki_backend.auth.models import AuthenticatedPrincipal

_BEARER_CHALLENGE = "Bearer"
_INVALID_TOKEN_CHALLENGE = 'Bearer error="invalid_token"'


def get_authenticated_principal(request: Request) -> AuthenticatedPrincipal:
    """Return the principal the request authenticates as.

    Raises:
        HTTPException: 401 when no usable bearer credential was presented,
            401 when the credential was refused, and 503 when the signing
            keys could not be obtained.
    """
    authenticator = getattr(request.app.state, "authenticator", None)
    if authenticator is None:
        raise _unavailable()
    token = _read_bearer_token(request)
    try:
        return authenticator.authenticate(token)
    except AuthenticationUnavailableError:
        raise _unavailable() from None
    except AuthenticationError:
        raise HTTPException(
            status_code=401,
            detail="invalid_token",
            headers={"WWW-Authenticate": _INVALID_TOKEN_CHALLENGE},
        ) from None


def require_scopes(
    *required_scopes: str,
) -> Callable[..., AuthenticatedPrincipal]:
    """Return a dependency that requires every listed coarse scope.

    The principal is obtained first, so a request without a usable credential
    is refused before the scopes are considered.
    """

    def dependency(
        principal: Annotated[
            AuthenticatedPrincipal, Depends(get_authenticated_principal)
        ],
    ) -> AuthenticatedPrincipal:
        """Return the principal when it holds every required scope."""
        if not principal.has_scopes(*required_scopes):
            raise HTTPException(
                status_code=403,
                detail="insufficient_scope",
                headers={
                    "WWW-Authenticate": (
                        'Bearer error="insufficient_scope", scope="'
                        + " ".join(dict.fromkeys(required_scopes))
                        + '"'
                    )
                },
            )
        return principal

    return dependency


def _read_bearer_token(request: Request) -> str:
    """Return the bearer credential, refusing anything else."""
    headers = request.headers.getlist("authorization")
    if not headers:
        raise HTTPException(
            status_code=401,
            detail="authentication_required",
            headers={"WWW-Authenticate": _BEARER_CHALLENGE},
        )
    if len(headers) > 1:
        raise _invalid_token()
    parts = headers[0].split()
    if len(parts) != 2 or parts[0].lower() != "bearer" or not parts[1]:
        raise _invalid_token()
    return parts[1]


def _invalid_token() -> HTTPException:
    """Return the response for a credential that is not usable."""
    return HTTPException(
        status_code=401,
        detail="invalid_token",
        headers={"WWW-Authenticate": _INVALID_TOKEN_CHALLENGE},
    )


def _unavailable() -> HTTPException:
    """Return the response for an authentication boundary that cannot run."""
    return HTTPException(status_code=503, detail="authentication_unavailable")


__all__ = ["get_authenticated_principal", "require_scopes"]
