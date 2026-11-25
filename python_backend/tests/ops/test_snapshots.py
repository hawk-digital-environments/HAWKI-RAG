from datetime import datetime, timezone

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.persistence.schema import dataset_grants
from .conftest import call


def test_service_graph_views_preserve_tenant_principal_and_dataset_grants(
    env, monkeypatch
):
    monkeypatch.setenv("RAG_BACKEND_SERVICE_ONLY", "true")
    with env.engine.begin() as connection:
        for principal in ("management", "other-management"):
            connection.execute(
                dataset_grants.insert().values(
                    tenant_id="a",
                    dataset_id="one",
                    principal_type="service",
                    principal_id=principal,
                    permission="query",
                )
            )

    def authenticate(token):
        return AuthenticatedPrincipal(
            PrincipalKind.SERVICE,
            "instance",
            "b" if token == "foreign" else "a",
            "other-management" if token == "other" else "management",
            frozenset({"rag:query"}),
            "issuer",
            datetime(2100, 1, 1, tzinfo=timezone.utc),
        )

    env.app.state.authenticator.authenticate = authenticate
    result = call(
        env,
        "graph/snapshots",
        method="POST",
        body={
            "dataset_id": "one",
            "name": "View",
            "scene": {"nodes": [{"data": {"id": "one"}}]},
        },
    )
    assert result.status_code == 200
    identity = result.json()["snapshot"]["id"]
    assert (
        call(env, "graph/snapshots?dataset_id=one").json()["snapshots"][0]["id"]
        == identity
    )
    assert (
        call(env, "graph/snapshots/" + identity).json()["snapshot"]["dataset_id"]
        == "one"
    )
    assert call(env, "graph/snapshots/" + identity, token="other").status_code == 404
    assert call(env, "graph/snapshots/" + identity, token="foreign").status_code == 404
    assert (
        call(
            env,
            "graph/snapshots",
            method="POST",
            body={"dataset_id": "two", "scene": {}},
        ).status_code
        == 404
    )
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.delete().where(dataset_grants.c.principal_id == "management")
        )
    assert call(env, "graph/snapshots/" + identity).status_code == 404


def test_anonymous_graph_views_are_denied(env):
    assert call(env, "graph/snapshots?dataset_id=one", token=None).status_code == 401
