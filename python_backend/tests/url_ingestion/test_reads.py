from hawki_backend.persistence.schema import dataset_grants
from hawki_backend.url_ingestion.identity import digest

from .conftest import create, request
from .test_lifecycle import generation, event


def test_tasks_jobs_status_events_and_logs_share_authorized_python_state(env):
    payload = create(env)
    row = generation(env, payload)
    failure = event(row, "failure", status="failed")
    env.repository.complete(failure, digest(failure.model_dump(mode="json")), "failed")
    task_id = payload["task_id"]
    detail = request(env, f"/v1/pipeline/tasks/{task_id}", method="GET").json()["task"]
    assert detail["counters"]["jobs_failed"] == 1
    assert detail["counters"]["jobs_total"] == 1
    assert detail["stages"]["ingest"]["status"] == "failed"

    assert (
        request(env, "/v1/pipeline/tasks", method="GET").json()["tasks"][0]["task_id"]
        == task_id
    )
    assert (
        request(env, "/v1/pipeline/recovery/failed-jobs", method="GET").json()["jobs"][
            0
        ]["job_id"]
        == row["job_id"]
    )
    assert (
        request(env, "/v1/pipeline/status/" + row["job_id"], method="GET").json()[
            "status"
        ]
        == "failed"
    )
    events = request(env, f"/v1/pipeline/tasks/{task_id}/events", method="GET").json()
    assert events["events"][0]["status"] == "failed"
    assert events["filters"]["job_ids"] == [row["job_id"]]
    log = request(
        env, f"/v1/pipeline/tasks/{task_id}/stages/ingest/logs", method="GET"
    ).json()["log"]
    assert log["line_count"] == 1
    assert row["job_id"] in log["text"]
    assert "scraper_url" not in log["text"]
    assert (
        request(
            env, f"/v1/pipeline/tasks/{task_id}/events?job_id=other", method="GET"
        ).json()["events"]
        == []
    )
    with env.engine.begin() as connection:
        connection.execute(dataset_grants.delete())
    assert request(env, "/v1/pipeline/tasks", method="GET").json()["tasks"] == []
    assert (
        request(
            env, f"/v1/pipeline/tasks/{task_id}/stages/ingest/logs", method="GET"
        ).status_code
        == 404
    )
    assert (
        request(env, "/v1/pipeline/status/" + row["job_id"], method="GET").status_code
        == 404
    )


def test_legacy_read_authorization_deduplicates_python_authority(env):
    row = generation(env, create(env))
    response = request(
        env,
        "/v1/pipeline/legacy-access",
        {
            "candidates": [
                {"field": "job_id", "identity": row["job_id"], "dataset_id": "web"},
                {"field": "job_id", "identity": "legacy", "dataset_id": "web"},
                {"field": "task_id", "identity": "denied", "dataset_id": "other"},
            ]
        },
    )
    assert response.json() == {"success": True, "identities": ["legacy"]}


def test_recovery_inventory_respects_task_dataset_filters(env):
    row = generation(env, create(env))
    value = event(row, "failure", status="failed")
    env.repository.complete(value, digest(value.model_dump(mode="json")), "failed")
    for query in ("task_id=other", "dataset_id=other"):
        assert (
            request(
                env, "/v1/pipeline/recovery/failed-jobs?" + query, method="GET"
            ).json()["jobs"]
            == []
        )
    candidates = request(env, "/v1/pipeline/recovery/candidates", method="GET").json()[
        "jobs"
    ]
    assert [item["job_id"] for item in candidates] == [row["job_id"]]
