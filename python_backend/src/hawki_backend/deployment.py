"""Pure deployment validation. No defaults here can switch application authority."""

from collections.abc import Mapping
from urllib.parse import urlsplit

from .query.settings import DatasetScope, load_query_settings
from .auth.models import PrincipalKind
from .settings import load_auth_settings, load_database_settings

SELECTORS = (
    "HAWKI_QUERY_BACKEND",
    "TEXT_INGESTION_BACKEND",
    "MANAGED_DOCUMENT_BACKEND",
    "CRAWLER_INGESTION_BACKEND",
    "PIPELINE_EXECUTION_BACKEND",
    "DATASET_BACKEND",
    "GRAPH_OPS_BACKEND",
)
ENABLE_FLAGS = (
    "HAWKI_WORKFLOW_CONTROL_ENABLED",
    "HAWKI_BACKEND_URL_INGESTION_ENABLED",
    "HAWKI_BACKEND_TEXT_INGESTION_ENABLED",
    "HAWKI_BACKEND_MANAGED_DOCUMENTS_ENABLED",
    "HAWKI_RAG_TEMPORAL_ENABLED",
)
REQUIRED_REVISION = "0008"


class _ConfigurationScope:
    """Validate model configuration without resolving or granting any dataset."""

    def resolve_scope(
        self, dataset_id: str, tenant_id: str | None = None
    ) -> DatasetScope | None:
        return None


def http_url(value: str, path: str | None = None, *, https: bool = False) -> bool:
    try:
        parts = urlsplit(value)
        return bool(
            parts.scheme in ({"https"} if https else {"http", "https"})
            and parts.hostname
            and parts.port != 0
            and not parts.username
            and not parts.password
            and not parts.query
            and not parts.fragment
            and not any(c.isspace() for c in value)
            and (path is None or parts.path.rstrip("/") == path)
        )
    except ValueError:
        return False


def configuration_checks(
    env: Mapping[str, str], phase: str = "runtime"
) -> dict[str, str]:
    """Return status only; exception text can contain DSNs and must never escape."""
    checks = {}
    for name, loader in (
        ("database_config", load_database_settings),
        ("auth_config", load_auth_settings),
    ):
        try:
            checks[name] = "PASS" if loader(env) is not None else "FAIL"
        except Exception:
            checks[name] = "FAIL"
    try:
        settings = load_query_settings(
            env, dataset_scope_resolver=_ConfigurationScope()
        )
        checks["query_config"] = "PASS" if settings and settings.models else "FAIL"
    except Exception:
        checks["query_config"] = "FAIL"
    checks["database_dataset_source"] = (
        "PASS"
        if env.get("HAWKI_BACKEND_QUERY_DATASET_SOURCE") == "database"
        and "HAWKI_BACKEND_QUERY_DATASETS" not in env
        else "FAIL"
    )
    if phase in {"staging", "cutover"}:
        for name in ENABLE_FLAGS:
            checks[name] = "PASS" if enabled(env, name) else "FAIL"
    for name in (
        "HAWKI_QUERY_BFF_ISSUER",
        "HAWKI_QUERY_BFF_AUDIENCE",
        "HAWKI_QUERY_BFF_CLIENT_ID",
        "HAWKI_QUERY_BFF_TENANT",
        "HAWKI_QUERY_BFF_PRIVATE_KEY_PATH",
        "HAWKI_QUERY_BFF_KEY_ID",
    ):
        checks[name] = "PASS" if env.get(name, "").strip() else "FAIL"
    try:
        auth = load_auth_settings(env)
        checks["bff_auth_agreement"] = (
            "PASS"
            if auth
            and auth.issuer == env.get("HAWKI_QUERY_BFF_ISSUER")
            and auth.audience == env.get("HAWKI_QUERY_BFF_AUDIENCE")
            and auth.clients.get(env.get("HAWKI_QUERY_BFF_CLIENT_ID"))
            == PrincipalKind.USER
            and "RS256" in auth.algorithms
            else "FAIL"
        )
    except Exception:
        checks["bff_auth_agreement"] = "FAIL"
    checks["callback_secret"] = (
        "PASS" if env.get("HAWKI_RAG_WORKER_CALLBACK_SECRET", "").strip() else "FAIL"
    )
    try:
        checks["callback_replay_window"] = (
            "PASS"
            if 1
            <= int(env.get("HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS", "0"))
            <= 3600
            else "FAIL"
        )
    except ValueError:
        checks["callback_replay_window"] = "FAIL"
    checks["callback_url"] = (
        "PASS"
        if http_url(env.get("HAWKI_RAG_WORKER_CALLBACK_URL", ""))
        and env["HAWKI_RAG_WORKER_CALLBACK_URL"].endswith(
            "/api/internal/pipeline/worker-events"
        )
        else "FAIL"
    )
    checks["control_destination"] = (
        "PASS" if http_url(env.get("HAWKI_WORKFLOW_CONTROL_URL", ""), "") else "FAIL"
    )
    if phase in {"staging", "cutover"}:
        expected = "laravel" if phase == "staging" else "python"
        for name in SELECTORS:
            checks[name] = "PASS" if env.get(name) == expected else "FAIL"
    if phase == "staging":
        checks["archived_cleanup_staged"] = (
            "PASS"
            if env.get("HAWKI_BACKEND_DATASET_CLEANUP_ENABLED") == "false"
            else "FAIL"
        )
    if phase == "cutover":
        checks["query_all_disabled"] = (
            "PASS"
            if env.get("HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT", "").lower() == "false"
            else "FAIL"
        )
    if phase == "runtime" and not any(enabled(env, name) for name in ENABLE_FLAGS[:4]):
        for key in (
            "callback_secret",
            "callback_replay_window",
            "callback_url",
            "control_destination",
        ):
            checks.pop(key, None)
    return checks


def enabled(env: Mapping[str, str], name: str) -> bool:
    if name == "HAWKI_BACKEND_TEXT_INGESTION_ENABLED":
        # The direct-text runtime accepts 'on' and strips whitespace.
        return env.get(name, "false").strip().lower() in {"1", "true", "yes", "on"}
    return env.get(name, "false").lower() in {"1", "true", "yes"}
