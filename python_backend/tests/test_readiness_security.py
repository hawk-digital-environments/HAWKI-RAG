"""Regression cases for operator safety, with no Docker or network access."""

import copy
import hashlib
import json
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import query_readiness as readiness  # noqa: E402


def mounts():
    return {
        "Name": "/hawki_rag_app",
        "Id": "app",
        "Mounts": [
            {
                "Source": "/operator/private",
                "Destination": "/run/secrets/query-bff",
                "RW": False,
            }
        ],
    }


def key_report(path="/run/secrets/query-bff/private.pem"):
    return {
        "key": {"bits": 2048, "fingerprint": "public-fingerprint", "mount_path": path},
        "key_forbidden_paths": [
            "/var/www/html/public",
            "/var/www/html/storage",
            "/shared",
        ],
    }


@pytest.mark.parametrize(
    "path",
    [
        "/var/www/html/public/signing/private.pem",
        "/var/www/html/storage/private.pem",
        "/var/www/html/public/nested/signing/private.pem",
        "run/secrets/private.pem",
        "/run/secrets/../public/private.pem",
        "//run/secrets/private.pem",
    ],
)
def test_unsafe_key_destination_rejected(path):
    app = mounts()
    app["Mounts"][0]["Destination"] = str(Path(path).parent)
    assert not readiness.mounted_key_isolated(key_report(path), [app], Path("/repo"))


@pytest.mark.parametrize(
    "source,destination,writable",
    [
        ("/operator/private", "/var/www/html/public/signing", False),
        ("/operator", "/unrelated", False),
        ("/operator/private/child", "/unrelated", False),
        ("/operator/private", "/unrelated", True),
        ("/different", "/run/secrets", True),
        ("/different", "/run/secrets/query-bff/child", False),
    ],
)
def test_overlapping_app_mount_rejected(source, destination, writable):
    app = mounts()
    app["Mounts"].append({"Source": source, "Destination": destination, "RW": writable})
    assert not readiness.mounted_key_isolated(key_report(), [app], Path("/repo"))


def test_isolated_secret_mount_allowed():
    assert readiness.mounted_key_isolated(key_report(), [mounts()], Path("/repo"))


@pytest.mark.parametrize("optimize", [0, 1, 2])
@pytest.mark.parametrize("unsafe", ["private_fields", "fingerprint", "tenant"])
def test_embedded_checks_fail_under_optimization(monkeypatch, capsys, optimize, unsafe):
    import httpx
    import hawki_backend.settings as settings
    import hawki_backend.auth.jwks as jwks
    import hawki_backend.persistence.engine as db

    if unsafe == "tenant":

        class Connection:
            def __enter__(self):
                return self

            def __exit__(self, *args):
                pass

            def begin(self):
                return self

            def execute(self, statement):
                return self

            def mappings(self):
                return [
                    {
                        "tenant_id": "other",
                        "dataset_id": "a",
                        "status": "active",
                        "principal_type": "user",
                        "principal_id": "1",
                        "permission": "query",
                    }
                ]

        monkeypatch.setattr(settings, "load_database_settings", lambda: None)
        monkeypatch.setattr(
            db,
            "build_engine",
            lambda _: SimpleNamespace(connect=Connection, dispose=lambda: None),
        )
        program = readiness.BACKEND_STATE_CHECK
    else:
        entry = {
            "kid": "key",
            "kty": "RSA",
            "alg": "RS256",
            "use": "sig",
            "key_ops": ["verify"],
            "n": "AQAB",
            "e": "Aw",
        }
        if unsafe == "private_fields":
            entry["d"] = "NOT-A-REAL-PRIVATE-KEY"
        monkeypatch.setattr(
            settings,
            "load_auth_settings",
            lambda: SimpleNamespace(jwks_url="https://example.invalid/jwks"),
        )
        monkeypatch.setattr(
            httpx,
            "get",
            lambda *a, **k: SimpleNamespace(
                raise_for_status=lambda: None, json=lambda: {"keys": [entry]}
            ),
        )
        key = SimpleNamespace(
            key_size=2048, public_numbers=lambda: SimpleNamespace(n=65537, e=3)
        )
        monkeypatch.setattr(
            jwks, "JwksKeyProvider", lambda _: SimpleNamespace(get_key=lambda *a: key)
        )
        fingerprint = hashlib.sha256(b"AQAB:Aw").hexdigest()
        monkeypatch.setattr(
            sys,
            "argv",
            ["check", "key", "wrong" if unsafe == "fingerprint" else fingerprint],
        )
        program = readiness.JWKS_CHECK
    with pytest.raises(SystemExit) as error:
        exec(compile(program, "<readiness>", "exec", optimize=optimize), {})
    assert error.value.code == 1
    assert json.loads(capsys.readouterr().out)["status"] == "FAIL"


def test_readiness_rejects_other_bff_destination(monkeypatch):
    import query_grants

    report = {
        "tenant": "default",
        "backend": "laravel",
        "query_all": False,
        "python_query_url": "http://other-deployment/v1/query",
        "auth_guard_drivers": {"web": "session"},
        "auth_default_guard": "web",
    }
    plan = SimpleNamespace(models={}, legacy_required=[], scope={}, users=[], grants=[])
    monkeypatch.setattr(query_grants, "compare_target", lambda *a: {"status": "PASS"})
    monkeypatch.setattr(query_grants, "verify_scope", lambda *a: True)
    monkeypatch.setattr(readiness, "auth_matches", lambda *a: True)
    monkeypatch.setattr(readiness, "deployed_target_matches", lambda *a: True)
    monkeypatch.setattr(readiness, "container_environment", lambda *a: {})
    monkeypatch.setattr(
        readiness, "shared_environment", lambda *a: {"explicit": "PASS"}
    )
    monkeypatch.setattr(readiness, "load_query_settings", lambda *a: None)
    monkeypatch.setattr(readiness, "reranker_matches", lambda *a: True)
    monkeypatch.setattr(readiness, "live_gates", lambda *a: {"live": "PASS"})
    result = readiness.check_readiness(
        None,
        plan,
        report,
        {"HAWKI_BACKEND_QUERY_MODELS": "{}"},
        inspect_containers=True,
        live=True,
        phase="activate",
    )
    assert result["status"] == "FAIL"


@pytest.mark.parametrize(
    "value",
    [
        "http://hawki_backend/v1/query",
        "http://hawki_backend:80/v1/query",
        "http://HAWKI_BACKEND:80/v1/query",
    ],
)
def test_equivalent_query_urls(value):
    assert readiness.canonical_query_url(value) == "http://hawki_backend:80/v1/query"


@pytest.mark.parametrize(
    "override",
    [
        "http://other/v1/query",
        "http://hawki_backend:8080/v1/query",
        "http://hawki_backend:0/v1/query",
        "HTTP://hawki_backend/v1/query",
        "http://hawki_backend/v1/query/",
        "https://hawki_backend/v1/query",
        "http://hawki_backend/base/v1/query",
        "http://hawki_backend/v1/query?x=1",
        "http://user:secret@hawki_backend/v1/query",
    ],
)
def test_conflicting_live_target_rejected(override):
    with pytest.raises(ValueError):
        readiness.matching_probe_url("http://hawki_backend/v1/query", override)


def binding_fixtures(monkeypatch):
    report = {
        "python_query_url": "http://hawki_backend/v1/query",
        "key_forbidden_paths": [
            "/var/www/html/public",
            "/var/www/html/storage",
            "/shared",
        ],
    }
    app = {
        "Id": "app-id",
        "Config": {
            "Env": [],
            "Labels": {
                "com.docker.compose.project": "test-project",
                "com.docker.compose.service": "hawki_rag_app",
            },
        },
        "NetworkSettings": {"Networks": {"private": {"IPAddress": "10.0.0.2"}}},
    }
    backend = copy.deepcopy(app)
    backend["Id"] = "backend-id"
    backend["State"] = {"Running": True}
    backend["Config"]["Labels"]["com.docker.compose.service"] = "hawki_backend"
    backend["NetworkSettings"]["Networks"]["private"]["IPAddress"] = "10.0.0.3"
    dns = ["10.0.0.3"]
    fresh = copy.deepcopy(report)
    monkeypatch.setattr(
        readiness,
        "container_inspect",
        lambda name: app if name == "hawki_rag_app" else backend,
    )
    monkeypatch.setattr(
        readiness,
        "run_json",
        lambda command: fresh if command[-1] == "query:cutover-export" else dns,
    )
    monkeypatch.delenv("HAWKI_PARITY_PYTHON_URL", raising=False)
    return report, fresh, app, backend, dns


def test_bound_target_uses_container_id_after_config_and_dns_verification(monkeypatch):
    report, _, _, _, _ = binding_fixtures(monkeypatch)
    assert readiness.bound_backend(report) == (
        "backend-id",
        "http://hawki_backend:80/v1/query",
    )
    monkeypatch.setenv("HAWKI_PARITY_PYTHON_URL", "http://HAWKI_BACKEND:80/")
    assert readiness.bound_backend(report)[0] == "backend-id"


@pytest.mark.parametrize(
    "mutation",
    [
        "effective_url",
        "exclusion_paths",
        "project",
        "service",
        "dns",
        "proxy",
        "override",
        "port",
        "scheme",
        "host",
        "path",
    ],
)
def test_backend_identity_mismatch_rejected(monkeypatch, mutation):
    report, fresh, app, backend, dns = binding_fixtures(monkeypatch)
    if mutation == "effective_url":
        fresh["python_query_url"] = "http://other/v1/query"
    elif mutation == "exclusion_paths":
        fresh["key_forbidden_paths"] = ["/changed/public"]
    elif mutation in {"project", "service"}:
        backend["Config"]["Labels"]["com.docker.compose." + mutation] = "different"
    elif mutation == "dns":
        dns.append("10.0.0.99")
    elif mutation == "proxy":
        # The CLI BFF probe could otherwise use Guzzle's proxy rather than DNS.
        app["Config"]["Env"].append("HTTP_PROXY=http://other-deployment")
    elif mutation == "override":
        monkeypatch.setenv("HAWKI_PARITY_PYTHON_URL", "http://other")
    else:
        report["python_query_url"] = {
            "port": "http://hawki_backend:81/v1/query",
            "scheme": "https://hawki_backend/v1/query",
            "host": "http://other/v1/query",
            "path": "http://hawki_backend/base/v1/query",
        }[mutation]
    with pytest.raises(ValueError):
        readiness.bound_backend(report)


@pytest.mark.parametrize(
    "mutation",
    [
        "missing_source",
        "relative_source",
        "missing_rw",
        "string_rw",
        "duplicate",
        "configured_public",
        "configured_static",
    ],
)
def test_ambiguous_mount_metadata_fails_closed(mutation):
    app = mounts()
    report = key_report()
    if mutation == "missing_source":
        del app["Mounts"][0]["Source"]
    elif mutation == "relative_source":
        app["Mounts"][0]["Source"] = "relative/path"
    elif mutation == "missing_rw":
        del app["Mounts"][0]["RW"]
    elif mutation == "string_rw":
        app["Mounts"][0]["RW"] = "false"
    elif mutation == "duplicate":
        app["Mounts"].append(copy.deepcopy(app["Mounts"][0]))
    elif mutation == "configured_public":
        report["key_forbidden_paths"] = ["/run/secrets"]
    elif mutation == "configured_static":
        app["Config"] = {"Env": ["WEB_DOCUMENT_ROOT=/run/secrets"]}
    assert not readiness.mounted_key_isolated(report, [app], Path("/repo"))


@pytest.mark.parametrize(
    "value",
    [
        "http://hawki_rag_bridge/query",
        "http://hawki_rag_bridge:80/query",
        "http://HAWKI_RAG_BRIDGE:80/query",
    ],
)
def test_equivalent_bridge_query_endpoints(value):
    assert (
        readiness.canonical_bridge_query_url(value)
        == "http://hawki_rag_bridge:80/query"
    )


@pytest.mark.parametrize(
    "override",
    [
        "http://other-bridge",
        "http://hawki_rag_bridge:81",
        "https://hawki_rag_bridge",
        "http://hawki_rag_bridge/other",
        "http://hawki_rag_bridge/query/",
        "http://user:secret@hawki_rag_bridge",
        "http://hawki_rag_bridge?secret=1",
        "HTTP://hawki_rag_bridge",
        "http://hawki_rag_bridge:0",
        "http://hawki_rag_bridge//query",
    ],
)
def test_conflicting_bridge_confirmation_rejected(override):
    with pytest.raises(ValueError):
        readiness.matching_bridge_target("http://hawki_rag_bridge/query", override)


@pytest.mark.parametrize(
    "override",
    [
        None,
        "http://hawki_rag_bridge",
        "http://hawki_rag_bridge/",
        "http://HAWKI_RAG_BRIDGE:80/query",
    ],
)
def test_bridge_confirmation_uses_only_laravel_endpoint(override):
    assert (
        readiness.matching_bridge_target("http://hawki_rag_bridge/query", override)
        == "http://hawki_rag_bridge:80/query"
    )


def bridge_binding_fixtures(monkeypatch):
    report = {"legacy_bridge_query_url": "http://hawki_rag_bridge/query"}
    app = {
        "Id": "app-id",
        "Config": {
            "Env": [],
            "Labels": {
                "com.docker.compose.project": "project",
                "com.docker.compose.service": "hawki_rag_app",
            },
        },
        "NetworkSettings": {"Networks": {"private": {"IPAddress": "10.0.0.2"}}},
    }
    bridge = copy.deepcopy(app)
    bridge["Id"] = "bridge-id"
    bridge["State"] = {"Running": True}
    bridge["Config"]["Labels"]["com.docker.compose.service"] = "hawki_rag_bridge"
    bridge["NetworkSettings"]["Networks"]["private"]["IPAddress"] = "10.0.0.4"
    fresh = copy.deepcopy(report)
    resolved = ["10.0.0.4"]
    monkeypatch.setattr(
        readiness,
        "container_inspect",
        lambda name: app if name == "hawki_rag_app" else bridge,
    )
    monkeypatch.setattr(
        readiness,
        "run_json",
        lambda command: fresh if command[-1] == "query:cutover-export" else resolved,
    )
    monkeypatch.delenv("HAWKI_PARITY_BRIDGE_URL", raising=False)
    return report, fresh, app, bridge, resolved


def test_bound_bridge_uses_effective_laravel_destination_when_override_unset(
    monkeypatch,
):
    report, _, _, _, _ = bridge_binding_fixtures(monkeypatch)
    assert readiness.bound_bridge(report) == (
        "bridge-id",
        "http://hawki_rag_bridge:80/query",
    )
    monkeypatch.setenv("HAWKI_PARITY_BRIDGE_URL", "http://HAWKI_RAG_BRIDGE:80/")
    assert readiness.bound_bridge(report)[0] == "bridge-id"


@pytest.mark.parametrize(
    "mutation",
    [
        "missing",
        "malformed",
        "stale",
        "other_host",
        "other_port",
        "other_scheme",
        "other_path",
        "override",
        "project",
        "service",
        "dns",
        "proxy",
    ],
)
def test_bound_bridge_rejects_unsafe_target_and_deployment(monkeypatch, mutation):
    report, fresh, app, bridge, resolved = bridge_binding_fixtures(monkeypatch)
    if mutation == "missing":
        del report["legacy_bridge_query_url"]
    elif mutation == "malformed":
        report["legacy_bridge_query_url"] = "http://user:secret@hawki_rag_bridge/query"
    elif mutation == "stale":
        fresh["legacy_bridge_query_url"] = "http://other/query"
    elif mutation == "override":
        monkeypatch.setenv("HAWKI_PARITY_BRIDGE_URL", "http://unrelated-bridge:9999")
    elif mutation in {"project", "service"}:
        bridge["Config"]["Labels"]["com.docker.compose." + mutation] = "different"
    elif mutation == "dns":
        resolved.append("10.0.0.99")
    elif mutation == "proxy":
        app["Config"]["Env"].append("HTTP_PROXY=http://other")
    else:
        report["legacy_bridge_query_url"] = {
            "other_host": "http://other/query",
            "other_port": "http://hawki_rag_bridge:81/query",
            "other_scheme": "https://hawki_rag_bridge/query",
            "other_path": "http://hawki_rag_bridge/other/query",
        }[mutation]
    with pytest.raises((ValueError, KeyError)):
        readiness.bound_bridge(report)
