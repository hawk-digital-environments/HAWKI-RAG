"""Read-only Python deployment checks; never starts services or prints credentials."""

import argparse
import json
import subprocess

from hawki_backend.deployment import configuration_checks

WORKERS = (
    "rawki_local_workflow_worker",
    "rawki_local_scraper_worker",
    "rawki_local_converter_worker",
    "rawki_local_indexer_worker",
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


def replica_checks(reference_env, backend_env, worker_envs):
    checks = {}
    for name in (
        "HAWKI_RAG_WORKER_CALLBACK_SECRET",
        "HAWKI_RAG_WORKER_CALLBACK_MAX_AGE_SECONDS",
    ):
        expected = reference_env.get(name)
        checks[name + "_agreement"] = (
            "PASS"
            if expected
            and backend_env.get(name) == expected
            and all(env.get(name) == expected for env in worker_envs)
            else "FAIL"
        )
    callback = reference_env.get("HAWKI_RAG_WORKER_CALLBACK_URL")
    checks["worker_callback_targets"] = (
        "PASS"
        if callback
        and all(
            env.get("HAWKI_RAG_WORKER_CALLBACK_URL") == callback for env in worker_envs
        )
        else "FAIL"
    )
    return checks


def deployment_report(live=False):
    backend, backend_env = inspect("hawki_backend")
    project = backend["Config"]["Labels"]["com.docker.compose.project"]
    workers = [inspect(f"{project}-{name}-1") for name in WORKERS]
    checks = {
        "configuration." + key: value
        for key, value in configuration_checks(backend_env).items()
    }
    checks.update(
        {
            "workers." + key: value
            for key, value in replica_checks(
                backend_env, backend_env, [env for _, env in workers]
            ).items()
        }
    )
    checks["containers_running"] = (
        "PASS"
        if all(
            value["State"]["Running"]
            for value in [backend, *(value for value, _ in workers)]
        )
        else "FAIL"
    )
    from hawki_vector_store.settings import qdrant_settings_from_env

    checks["workers.qdrant.indexer_destination"] = (
        "PASS"
        if qdrant_settings_from_env(workers[-1][1]).base_url
        == qdrant_settings_from_env(backend_env).base_url
        else "FAIL"
    )
    if live:
        result = subprocess.run(
            [
                "docker",
                "exec",
                backend["Id"],
                "python",
                "-m",
                "hawki_backend.readiness",
                "--live",
            ],
            capture_output=True,
            text=True,
            timeout=60,
        )
        runtime = json.loads(result.stdout)
        checks.update(
            {"backend." + key: value for key, value in runtime["checks"].items()}
        )
        checks["backend.result"] = (
            "PASS"
            if result.returncode == 0 and runtime.get("status") == "PASS"
            else "FAIL"
        )
        probe = """from dataclasses import replace
from hawki_vector_store.settings import qdrant_settings_from_env
from hawki_vector_store.client import QdrantHTTP
client = QdrantHTTP(settings=replace(qdrant_settings_from_env(), timeout=3, max_attempts=1, retry_attempts_by_operation={}))
try:
    client.list_collections()
finally:
    client.close()
"""
        result = subprocess.run(
            ["docker", "exec", workers[-1][0]["Id"], "python", "-c", probe],
            capture_output=True,
            timeout=10,
        )
        checks["workers.qdrant.indexer_credential"] = (
            "PASS" if result.returncode == 0 else "FAIL"
        )
    else:
        checks["runtime_dependencies"] = "NOT EXECUTED"
    return {
        "status": "PASS"
        if all(value == "PASS" for value in checks.values())
        else "FAIL",
        "read_only": True,
        "checks": checks,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--live", action="store_true")
    args = parser.parse_args()
    try:
        result = deployment_report(live=args.live)
    except Exception:
        result = {"status": "FAIL", "read_only": True}
    print(json.dumps(result, sort_keys=True))
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
