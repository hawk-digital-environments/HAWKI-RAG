"""Read-only deployment report; never creates keys, imports, changes env or starts services."""

import argparse
import json
import os
import subprocess

from hawki_backend.deployment import configuration_checks
from hawki_backend.readiness import report

WORKERS = (
    "hawki_rag_temporal_workflow_worker",
    "hawki_rag_temporal_scraper_worker",
    "hawki_rag_temporal_converter_worker",
    "hawki_rag_indexer_worker",
)


def inspect(name):
    result = subprocess.run(
        ["docker", "inspect", name],
        capture_output=True,
        text=True,
        timeout=10,
        check=True,
    )
    value = json.loads(result.stdout)[0]
    return value, dict(
        item.split("=", 1) for item in value["Config"]["Env"] if "=" in item
    )


def replica_checks(app_env, backend_env, worker_envs):
    checks = {}
    for name in (
        "HAWKI_RAG_WORKER_CALLBACK_SECRET",
        "HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS",
    ):
        expected = app_env.get(name)
        checks[name + "_agreement"] = (
            "PASS"
            if expected
            and backend_env.get(name) == expected
            and all(env.get(name) == expected for env in worker_envs)
            else "FAIL"
        )
    callback = app_env.get("HAWKI_RAG_WORKER_CALLBACK_URL")
    checks["worker_callback_targets"] = (
        "PASS"
        if callback
        and all(
            env.get("HAWKI_RAG_WORKER_CALLBACK_URL") == callback for env in worker_envs
        )
        else "FAIL"
    )
    return checks


def deployment_report(phase, live):
    app, app_env = inspect("hawki_rag_app")
    backend, backend_env = inspect("hawki_backend")
    checks = {
        "laravel." + key: value
        for key, value in configuration_checks(app_env, phase).items()
    }
    workers = [inspect(name) for name in WORKERS]
    # The indexer publishes with its own environment and credential. Compare
    # destinations, then probe that credential in its actual container below.
    from hawki_vector_store.settings import qdrant_settings_from_env

    checks["workers.qdrant.indexer_destination"] = (
        "PASS"
        if qdrant_settings_from_env(workers[-1][1]).base_url
        == qdrant_settings_from_env(backend_env).base_url
        else "FAIL"
    )
    checks.update(
        {
            "workers." + key: value
            for key, value in replica_checks(
                app_env, backend_env, [env for _, env in workers]
            ).items()
        }
    )
    checks["containers_running"] = (
        "PASS"
        if all(
            value["State"]["Running"]
            for value in [app, backend, *(value for value, _ in workers)]
        )
        else "FAIL"
    )
    if live:
        indexer_probe = """from dataclasses import replace
from hawki_vector_store.settings import qdrant_settings_from_env
from hawki_vector_store.client import QdrantHTTP
import sys
client = QdrantHTTP(settings=replace(qdrant_settings_from_env(), timeout=3, max_attempts=1, retry_attempts_by_operation={}))
try:
    client.list_collections()
except Exception:
    sys.exit(1)
finally:
    client.close()
"""
        indexer = subprocess.run(
            ["docker", "exec", workers[-1][0]["Id"], "python", "-c", indexer_probe],
            capture_output=True,
            timeout=10,
        )
        checks["workers.qdrant.indexer_credential"] = (
            "PASS" if indexer.returncode == 0 else "FAIL"
        )
        command = [
            "docker",
            "exec",
            backend["Id"],
            "python",
            "-m",
            "hawki_backend.readiness",
            "--phase",
            phase,
            "--live",
        ]
        result = subprocess.run(command, capture_output=True, text=True, timeout=60)
        runtime = json.loads(result.stdout)
        checks.update(
            {"backend." + key: value for key, value in runtime["checks"].items()}
        )
        checks["backend.result"] = (
            "PASS"
            if result.returncode == 0 and runtime.get("status") == "PASS"
            else "FAIL"
        )
        relay = subprocess.run(
            [
                "docker",
                "exec",
                "--user",
                "www-data",
                app["Id"],
                "php",
                "artisan",
                "rag:cutover-relay-readiness",
            ],
            capture_output=True,
            text=True,
            timeout=45,
        )
        relay_checks = json.loads(relay.stdout)["checks"]
        required = {
            "callback_auth",
            "bff_identity",
            "ingress.worker-events",
            "ingress.managed-writers",
            "ingress.managed-deletion-events",
            "relay.text_ingestion",
            "relay.url_ingestion",
            "relay.managed_document",
        }
        checks["laravel.relay_result"] = (
            "PASS"
            if relay.returncode == 0 and set(relay_checks) == required
            else "FAIL"
        )
        checks.update(
            {"laravel.network." + key: value for key, value in relay_checks.items()}
        )
        # The key stays outside the backend and must actually be readable by the BFF.
        key = app_env.get("HAWKI_QUERY_BFF_PRIVATE_KEY_PATH", "")
        key_result = subprocess.run(
            ["docker", "exec", "--user", "www-data", app["Id"], "test", "-r", key],
            capture_output=True,
            timeout=5,
        )
        checks["bff_signing_key_readable"] = (
            "PASS" if key_result.returncode == 0 else "FAIL"
        )
    else:
        checks["runtime_dependencies"] = "NOT EXECUTED"
    return {
        "status": "PASS"
        if all(value == "PASS" for value in checks.values())
        else "FAIL",
        "read_only": True,
        "phase": phase,
        "checks": checks,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phase", choices=("staging", "cutover"), required=True)
    parser.add_argument("--inspect-containers", action="store_true")
    parser.add_argument("--live", action="store_true")
    args = parser.parse_args()
    try:
        value = (
            deployment_report(args.phase, args.live)
            if args.inspect_containers
            else report(os.environ, args.phase, args.live)
        )
    except Exception:
        value = {
            "status": "FAIL",
            "read_only": True,
            "checks": {"deployment_inspection": "FAIL"},
        }
    print(json.dumps(value, sort_keys=True))
    return 0 if value["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
