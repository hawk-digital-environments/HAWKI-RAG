"""Environment-backed configuration for the backend's authentication boundary.

The authentication boundary is configured entirely from ``HAWKI_BACKEND_AUTH_*``
environment variables. Two states are possible and only two:

* No ``HAWKI_BACKEND_AUTH_*`` variable is present. Authentication is *not
  configured*, and `load_auth_settings` returns ``None``. This never means
  anonymous access is permitted; it means the service has no authentication
  boundary at all and must not expose authenticated routes.
* At least one is present. The configuration is then either complete and
  valid, or loading fails with `AuthConfigurationError`. A partially
  understood configuration is never constructed.

This module performs no I/O beyond reading the mapping it is given, and it
reads no environment variables at import time.
"""

import json
import os
import re
from collections.abc import Mapping
from dataclasses import dataclass, field
from types import MappingProxyType
from urllib.parse import urlsplit

from hawki_backend.auth.models import PrincipalKind

_PREFIX = "HAWKI_BACKEND_AUTH_"
_DB_PREFIX = "HAWKI_BACKEND_DB_"

_ALLOWED_ALGORITHMS = frozenset({"RS256", "PS256", "ES256"})
_TENANT_CLAIM_PATTERN = re.compile(r"^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$")
_INTEGER_PATTERN = re.compile(r"^-?[0-9]+$")

_DEFAULT_ALGORITHMS = ("RS256", "ES256")
_DEFAULT_TENANT_CLAIM = "tenant_id"
_DEFAULT_LEEWAY_SECONDS = 30
_DEFAULT_MAX_TOKEN_TTL_SECONDS = 3600
_DEFAULT_JWKS_CACHE_TTL_SECONDS = 600
_DEFAULT_JWKS_MIN_REFRESH_SECONDS = 60
_DEFAULT_JWKS_MAX_STALE_SECONDS = 3600
_DEFAULT_JWKS_TIMEOUT_SECONDS = 3
_DEFAULT_JWKS_MAX_BYTES = 512 * 1024

_DEFAULT_DB_POOL_SIZE = 5
_DEFAULT_DB_POOL_TIMEOUT_SECONDS = 3
_DEFAULT_DB_POOL_RECYCLE_SECONDS = 300
_DEFAULT_DB_CONNECT_TIMEOUT_SECONDS = 3
_DEFAULT_DB_STATEMENT_TIMEOUT_MS = 2000

_MIN_LEEWAY_SECONDS = 0
_MAX_LEEWAY_SECONDS = 300
_MIN_MAX_TOKEN_TTL_SECONDS = 1
_MAX_MAX_TOKEN_TTL_SECONDS = 86400
_MIN_JWKS_CACHE_TTL_SECONDS = 1
_MIN_JWKS_MIN_REFRESH_SECONDS = 1
_MIN_JWKS_MAX_STALE_SECONDS = 0
_MIN_JWKS_TIMEOUT_SECONDS = 1
_MAX_JWKS_TIMEOUT_SECONDS = 30
_MIN_JWKS_MAX_BYTES = 1024
_MAX_JWKS_MAX_BYTES = 10 * 1024 * 1024

_KNOWN_SUFFIXES = frozenset(
    {
        "ISSUER",
        "AUDIENCE",
        "JWKS_URL",
        "CLIENTS",
        "ALGORITHMS",
        "TENANT_CLAIM",
        "LEEWAY_SECONDS",
        "MAX_TOKEN_TTL_SECONDS",
        "JWKS_CACHE_TTL_SECONDS",
        "JWKS_MIN_REFRESH_SECONDS",
        "JWKS_MAX_STALE_SECONDS",
        "JWKS_TIMEOUT_SECONDS",
        "JWKS_MAX_BYTES",
    }
)

_DB_KNOWN_SUFFIXES = frozenset(
    {
        "DSN",
        "POOL_SIZE",
        "POOL_TIMEOUT_SECONDS",
        "POOL_RECYCLE_SECONDS",
        "CONNECT_TIMEOUT_SECONDS",
        "STATEMENT_TIMEOUT_MS",
        # A marker the persistence tests read. It is not a setting of the
        # service, but it shares the prefix, so it has to be known here or
        # the loader would refuse it as a typo.
        "TESTING",
    }
)

_DATABASE_SCHEME = "postgresql+psycopg"


class AuthConfigurationError(Exception):
    """Raised when the authentication configuration is missing or invalid.

    This is a startup failure, not a refused credential. The service must not
    boot with a partially understood authentication configuration.
    """


class DatabaseConfigurationError(Exception):
    """Raised when the database configuration is missing or invalid.

    This is a startup failure. The service must not boot with a partially
    understood database configuration, and it must not boot with a
    configuration that points at a schema the backend does not own.
    """


@dataclass(frozen=True, slots=True)
class AuthSettings:
    """Immutable configuration for the backend's authentication boundary.

    Every field is server-side configuration. The client registry maps known
    client identifiers to the kind of principal they authenticate as; the
    kind is never taken from a token.

    This object is produced by `load_auth_settings`, which is the only
    validated construction path. Every value it exposes is immutable: the
    attributes cannot be reassigned and the client registry is a read-only
    mapping.
    """

    issuer: str
    audience: str
    jwks_url: str
    clients: Mapping[str, PrincipalKind] = field(hash=False)
    algorithms: tuple[str, ...] = _DEFAULT_ALGORITHMS
    tenant_claim: str = _DEFAULT_TENANT_CLAIM
    leeway_seconds: int = _DEFAULT_LEEWAY_SECONDS
    max_token_ttl_seconds: int = _DEFAULT_MAX_TOKEN_TTL_SECONDS
    jwks_cache_ttl_seconds: int = _DEFAULT_JWKS_CACHE_TTL_SECONDS
    jwks_min_refresh_seconds: int = _DEFAULT_JWKS_MIN_REFRESH_SECONDS
    jwks_max_stale_seconds: int = _DEFAULT_JWKS_MAX_STALE_SECONDS
    jwks_timeout_seconds: int = _DEFAULT_JWKS_TIMEOUT_SECONDS
    jwks_max_bytes: int = _DEFAULT_JWKS_MAX_BYTES

    def __post_init__(self) -> None:
        """Freeze the collections so the settings cannot be mutated."""
        object.__setattr__(self, "clients", MappingProxyType(dict(self.clients)))
        object.__setattr__(self, "algorithms", tuple(self.algorithms))


@dataclass(frozen=True, slots=True)
class DatabaseSettings:
    """Immutable configuration for the backend's persistence boundary.

    The schema the backend reads is not configurable. It owns one
    PostgreSQL schema and the tables in it, and pointing the connection at
    another schema would put the backend inside Laravel's tables.

    This object is produced by `load_database_settings`, which is the only
    validated construction path.

    The connection string is excluded from the representation. It can carry
    an account and a password, and a representation reaches pytest
    assertion output, traceback locals and ordinary logging.
    """

    dsn: str = field(repr=False)
    pool_size: int = _DEFAULT_DB_POOL_SIZE
    pool_timeout_seconds: int = _DEFAULT_DB_POOL_TIMEOUT_SECONDS
    pool_recycle_seconds: int = _DEFAULT_DB_POOL_RECYCLE_SECONDS
    connect_timeout_seconds: int = _DEFAULT_DB_CONNECT_TIMEOUT_SECONDS
    statement_timeout_ms: int = _DEFAULT_DB_STATEMENT_TIMEOUT_MS


def load_auth_settings(environ: Mapping[str, str] | None = None) -> AuthSettings | None:
    """Load the authentication configuration from an environment mapping.

    Args:
        environ: The environment to read. Defaults to `os.environ` when it is
            not supplied, so the call site decides what is read.

    Returns:
        The validated configuration, or ``None`` when no ``HAWKI_BACKEND_AUTH_*``
        variable is present. ``None`` means authentication is not configured.

    Raises:
        AuthConfigurationError: When any authentication variable is present but
            the configuration is incomplete or invalid.
    """
    source = os.environ if environ is None else environ
    present = {name for name in source if name.startswith(_PREFIX)}
    if not present:
        return None
    _reject_unknown_names(present)
    return AuthSettings(
        issuer=_required_text(source, "ISSUER"),
        audience=_required_text(source, "AUDIENCE"),
        jwks_url=_jwks_url(source),
        clients=_clients(source),
        algorithms=_algorithms(source),
        tenant_claim=_tenant_claim(source),
        leeway_seconds=_integer(
            source,
            "LEEWAY_SECONDS",
            _DEFAULT_LEEWAY_SECONDS,
            _MIN_LEEWAY_SECONDS,
            _MAX_LEEWAY_SECONDS,
        ),
        max_token_ttl_seconds=_integer(
            source,
            "MAX_TOKEN_TTL_SECONDS",
            _DEFAULT_MAX_TOKEN_TTL_SECONDS,
            _MIN_MAX_TOKEN_TTL_SECONDS,
            _MAX_MAX_TOKEN_TTL_SECONDS,
        ),
        jwks_cache_ttl_seconds=_integer(
            source,
            "JWKS_CACHE_TTL_SECONDS",
            _DEFAULT_JWKS_CACHE_TTL_SECONDS,
            _MIN_JWKS_CACHE_TTL_SECONDS,
            None,
        ),
        jwks_min_refresh_seconds=_integer(
            source,
            "JWKS_MIN_REFRESH_SECONDS",
            _DEFAULT_JWKS_MIN_REFRESH_SECONDS,
            _MIN_JWKS_MIN_REFRESH_SECONDS,
            None,
        ),
        jwks_max_stale_seconds=_integer(
            source,
            "JWKS_MAX_STALE_SECONDS",
            _DEFAULT_JWKS_MAX_STALE_SECONDS,
            _MIN_JWKS_MAX_STALE_SECONDS,
            None,
        ),
        jwks_timeout_seconds=_integer(
            source,
            "JWKS_TIMEOUT_SECONDS",
            _DEFAULT_JWKS_TIMEOUT_SECONDS,
            _MIN_JWKS_TIMEOUT_SECONDS,
            _MAX_JWKS_TIMEOUT_SECONDS,
        ),
        jwks_max_bytes=_integer(
            source,
            "JWKS_MAX_BYTES",
            _DEFAULT_JWKS_MAX_BYTES,
            _MIN_JWKS_MAX_BYTES,
            _MAX_JWKS_MAX_BYTES,
        ),
    )


def load_database_settings(
    environ: Mapping[str, str] | None = None,
) -> DatabaseSettings | None:
    """Load the database configuration from an environment mapping.

    Args:
        environ: The environment to read. Defaults to `os.environ` when it
            is not supplied, so the call site decides what is read.

    Returns:
        The validated configuration, or ``None`` when no ``HAWKI_BACKEND_DB_*``
        variable is present. ``None`` means the persistence boundary is not
        configured.

    Raises:
        DatabaseConfigurationError: When any database variable is present but
            the configuration is incomplete or invalid.
    """
    source = os.environ if environ is None else environ
    present = {name for name in source if name.startswith(_DB_PREFIX)}
    if not present:
        return None
    _reject_unknown_database_names(present)
    return DatabaseSettings(
        dsn=_database_dsn(source),
        pool_size=_database_integer(
            source,
            "POOL_SIZE",
            _DEFAULT_DB_POOL_SIZE,
            1,
            64,
        ),
        pool_timeout_seconds=_database_integer(
            source,
            "POOL_TIMEOUT_SECONDS",
            _DEFAULT_DB_POOL_TIMEOUT_SECONDS,
            1,
            60,
        ),
        pool_recycle_seconds=_database_integer(
            source,
            "POOL_RECYCLE_SECONDS",
            _DEFAULT_DB_POOL_RECYCLE_SECONDS,
            0,
            3600,
        ),
        connect_timeout_seconds=_database_integer(
            source,
            "CONNECT_TIMEOUT_SECONDS",
            _DEFAULT_DB_CONNECT_TIMEOUT_SECONDS,
            1,
            60,
        ),
        statement_timeout_ms=_database_integer(
            source,
            "STATEMENT_TIMEOUT_MS",
            _DEFAULT_DB_STATEMENT_TIMEOUT_MS,
            100,
            60000,
        ),
    )


def _database_env_name(suffix: str) -> str:
    """Return the full environment variable name for a database suffix."""
    return f"{_DB_PREFIX}{suffix}"


def _reject_unknown_database_names(present: set[str]) -> None:
    """Reject an unknown ``HAWKI_BACKEND_DB_*`` variable as a typo."""
    known = {_database_env_name(suffix) for suffix in _DB_KNOWN_SUFFIXES}
    unknown = sorted(present - known)
    if unknown:
        raise DatabaseConfigurationError(
            "unknown database configuration: " + ", ".join(unknown)
        )


def _database_dsn(source: Mapping[str, str]) -> str:
    """Return the validated connection string of the database."""
    raw = source.get(_database_env_name("DSN"))
    if raw is None:
        raise DatabaseConfigurationError(f"{_database_env_name('DSN')} is required")
    dsn = raw.strip()
    if not dsn:
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} must not be empty"
        )
    if any(character.isspace() for character in dsn):
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} must not contain whitespace"
        )
    try:
        parts = urlsplit(dsn)
    except ValueError as error:
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} is not a valid connection string"
        ) from error
    if parts.scheme != _DATABASE_SCHEME:
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} must use the {_DATABASE_SCHEME} scheme"
        )
    if not parts.hostname:
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} must include a host"
        )
    if parts.fragment:
        raise DatabaseConfigurationError(
            f"{_database_env_name('DSN')} must not contain a fragment"
        )
    return dsn


def _database_integer(
    source: Mapping[str, str],
    suffix: str,
    default: int,
    minimum: int,
    maximum: int,
) -> int:
    """Return a bounded integer value for the database configuration."""
    raw = source.get(_database_env_name(suffix))
    if raw is None:
        return default
    text = raw.strip()
    if not _INTEGER_PATTERN.fullmatch(text):
        raise DatabaseConfigurationError(
            f"{_database_env_name(suffix)} must be an integer"
        )
    value = int(text)
    if value < minimum or value > maximum:
        raise DatabaseConfigurationError(
            f"{_database_env_name(suffix)} must be between {minimum} and {maximum}"
        )
    return value


def _env_name(suffix: str) -> str:
    """Return the full environment variable name for a configuration suffix."""
    return f"{_PREFIX}{suffix}"


def _reject_unknown_names(present: set[str]) -> None:
    """Reject an unknown ``HAWKI_BACKEND_AUTH_*`` variable as a typo."""
    known = {_env_name(suffix) for suffix in _KNOWN_SUFFIXES}
    unknown = sorted(present - known)
    if unknown:
        raise AuthConfigurationError(
            "unknown authentication configuration: " + ", ".join(unknown)
        )


def _required_text(source: Mapping[str, str], suffix: str) -> str:
    """Return a required, non-empty text value."""
    raw = source.get(_env_name(suffix))
    if raw is None:
        raise AuthConfigurationError(f"{_env_name(suffix)} is required")
    text = raw.strip()
    if not text:
        raise AuthConfigurationError(f"{_env_name(suffix)} must not be empty")
    return text


def _optional_text(source: Mapping[str, str], suffix: str, default: str) -> str:
    """Return an optional text value, falling back to the default."""
    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    text = raw.strip()
    if not text:
        raise AuthConfigurationError(f"{_env_name(suffix)} must not be empty")
    return text


def _integer(
    source: Mapping[str, str],
    suffix: str,
    default: int,
    minimum: int,
    maximum: int | None,
) -> int:
    """Return a bounded integer value, falling back to the default."""
    raw = source.get(_env_name(suffix))
    if raw is None:
        return default
    text = raw.strip()
    if not _INTEGER_PATTERN.fullmatch(text):
        raise AuthConfigurationError(f"{_env_name(suffix)} must be an integer")
    value = int(text)
    if value < minimum or (maximum is not None and value > maximum):
        bound = (
            f"between {minimum} and {maximum}"
            if maximum is not None
            else f"at least {minimum}"
        )
        raise AuthConfigurationError(f"{_env_name(suffix)} must be {bound}")
    return value


def _jwks_url(source: Mapping[str, str]) -> str:
    """Return the explicit HTTPS URL of the signing key set."""
    url = _required_text(source, "JWKS_URL")
    if any(character.isspace() for character in url):
        raise AuthConfigurationError(f"{_env_name('JWKS_URL')} must not contain spaces")
    if not url.startswith("https://"):
        raise AuthConfigurationError(f"{_env_name('JWKS_URL')} must be an https:// URL")
    try:
        parts = urlsplit(url)
    except ValueError as error:
        raise AuthConfigurationError(
            f"{_env_name('JWKS_URL')} is not a valid URL"
        ) from error
    if not parts.netloc:
        raise AuthConfigurationError(f"{_env_name('JWKS_URL')} must include a host")
    if parts.username or parts.password:
        raise AuthConfigurationError(
            f"{_env_name('JWKS_URL')} must not embed credentials"
        )
    if parts.fragment:
        raise AuthConfigurationError(
            f"{_env_name('JWKS_URL')} must not contain a fragment"
        )
    return url


def _unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject a JSON object that defines the same key more than once.

    Python's JSON parser silently keeps the last value for a repeated key, so
    a duplicated client definition has to be detected while parsing.
    """
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise AuthConfigurationError(
                f"client registry defines {key!r} more than once"
            )
        result[key] = value
    return result


def _clients(source: Mapping[str, str]) -> Mapping[str, PrincipalKind]:
    """Return the server-side registry of known clients and their kind."""
    raw = _required_text(source, "CLIENTS")
    try:
        parsed = json.loads(raw, object_pairs_hook=_unique_object)
    except json.JSONDecodeError as error:
        raise AuthConfigurationError(
            f"{_env_name('CLIENTS')} must be a JSON object"
        ) from error
    if not isinstance(parsed, dict):
        raise AuthConfigurationError(f"{_env_name('CLIENTS')} must be a JSON object")
    if not parsed:
        raise AuthConfigurationError(
            f"{_env_name('CLIENTS')} must define at least one client"
        )
    clients: dict[str, PrincipalKind] = {}
    for client_id, kind in parsed.items():
        if not client_id or client_id != client_id.strip():
            raise AuthConfigurationError(
                f"{_env_name('CLIENTS')} contains an invalid client identifier"
            )
        if not isinstance(kind, str):
            raise AuthConfigurationError(
                f"client {client_id!r} must map to a principal kind"
            )
        try:
            clients[client_id] = PrincipalKind(kind)
        except ValueError as error:
            raise AuthConfigurationError(
                f"client {client_id!r} has an unknown principal kind {kind!r}; "
                "expected 'service' or 'user'"
            ) from error
    return MappingProxyType(clients)


def _algorithms(source: Mapping[str, str]) -> tuple[str, ...]:
    """Return the explicit allowlist of accepted signature algorithms."""
    raw = source.get(_env_name("ALGORITHMS"))
    if raw is None:
        return _DEFAULT_ALGORITHMS
    text = raw.strip()
    if not text:
        raise AuthConfigurationError(f"{_env_name('ALGORITHMS')} must not be empty")
    names = [part.strip() for part in text.split(",")]
    if any(not name for name in names):
        raise AuthConfigurationError(
            f"{_env_name('ALGORITHMS')} must be a comma separated list of algorithms"
        )
    if len(set(names)) != len(names):
        raise AuthConfigurationError(
            f"{_env_name('ALGORITHMS')} must not repeat an algorithm"
        )
    for name in names:
        if name in _ALLOWED_ALGORITHMS:
            continue
        if name == "none" or name.startswith("HS"):
            raise AuthConfigurationError(
                f"{_env_name('ALGORITHMS')} does not allow {name!r}; unsigned and "
                "symmetric algorithms are never permitted"
            )
        raise AuthConfigurationError(
            f"{_env_name('ALGORITHMS')} does not allow {name!r}; allowed algorithms "
            f"are {', '.join(sorted(_ALLOWED_ALGORITHMS))}"
        )
    return tuple(names)


def _tenant_claim(source: Mapping[str, str]) -> str:
    """Return the name of the claim that carries the tenant identifier."""
    claim = _optional_text(source, "TENANT_CLAIM", _DEFAULT_TENANT_CLAIM)
    if not _TENANT_CLAIM_PATTERN.fullmatch(claim):
        raise AuthConfigurationError(
            f"{_env_name('TENANT_CLAIM')} must be a claim name matching "
            f"{_TENANT_CLAIM_PATTERN.pattern}"
        )
    return claim


__all__ = [
    "AuthConfigurationError",
    "AuthSettings",
    "DatabaseConfigurationError",
    "DatabaseSettings",
    "load_auth_settings",
    "load_database_settings",
]
