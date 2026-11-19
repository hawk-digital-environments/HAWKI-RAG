"""Ops authentication, authorization, enrichment, scope and fail-closed behavior."""

from types import SimpleNamespace
import pytest
import sqlalchemy as sa

from hawki_backend.managed_documents.schema import documents, revisions
from hawki_backend.persistence.datasets import lock_active_dataset, DatasetInactiveError
from hawki_backend.workflow_control.schema import operations
from hawki_backend.ops.admission import prepare_clear
from .conftest import call


@pytest.mark.parametrize(
    "token,status",
    [(None, 401), ("invalid", 401), ("ungranted", 404), ("other-tenant", 404)],
)
def test_read_credentials_and_exact_grant(env, token, status):
    assert call(env, token=token).status_code == status
    env.factory.assert_not_called()


@pytest.mark.parametrize(
    "dataset,status",
    [("archived", 404), ("disabled", 409), ("unknown", 404), ("foreign", 404)],
)
def test_dataset_visibility(env, dataset, status):
    assert call(env, "graph/overview?dataset_id=" + dataset).status_code == status
    env.factory.assert_not_called()


def test_query_read_uses_canonical_scope_and_missing_sources(env):
    response = call(env)
    assert response.status_code == 200
    env.factory.assert_called_once_with("one", "physical-graph-one")
    assert all(
        source["missing"] for source in response.json()["nodes"][0]["source_documents"]
    )


@pytest.mark.parametrize("path", ["stats", "config"])
def test_ops_requires_manager(env, path):
    assert call(env, path).status_code == 403
    assert call(env, path, token="manager").status_code == 200


def test_tenant_stats_do_not_expose_other_tenant(env):
    response = call(env, "stats", token="manager")
    assert response.status_code == 200
    assert "foreign" not in response.text
    assert response.json()["neo4j"]["entities"] == 4
    assert {args.args[0] for args in env.catalog.point_count.call_args_list} == {
        "physical-vector-one",
        "physical-vector-two",
        "physical-vector-disabled",
    }


def test_config_is_explicit_secret_free_projection(env, monkeypatch):
    monkeypatch.setenv("OPENAI_API_KEY", "forbidden-api-key")
    monkeypatch.setenv("HAWKI_BACKEND_DB_DSN", "forbidden-dsn")
    response = call(env, "config", token="manager")
    assert response.status_code == 200
    assert "forbidden" not in response.text
    assert "physical-" not in response.text
    assert response.json()["managedBy"] == "deployment"


def test_clear_query_forbidden_manager_allowed_and_idempotent(env):
    body = {"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"}
    assert call(env, "graph/clear", method="POST", body=body).status_code == 403
    assert (
        call(env, "graph/clear", token="manager", method="POST", body=body).status_code
        == 200
    )
    assert (
        call(env, "graph/clear", token="manager", method="POST", body=body).status_code
        == 200
    )


def test_clear_confirmation_and_cross_tenant(env):
    for body, status in [
        ({"dataset_id": "one"}, 422),
        ({"dataset_id": "one", "confirm": "ALL"}, 422),
        ({"dataset_id": "foreign", "confirm": "CLEAR_DATASET_GRAPH"}, 404),
    ]:
        assert (
            call(
                env, "graph/clear", token="manager", method="POST", body=body
            ).status_code
            == status
        )
    env.factory.assert_not_called()


def test_clear_outage_keeps_durable_fence_and_never_retries(env):
    env.factory.side_effect = RuntimeError("private-driver-message")
    body = {"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"}
    assert (
        call(env, "graph/clear", token="manager", method="POST", body=body).status_code
        == 503
    )
    with env.engine.begin() as connection:
        with pytest.raises(DatasetInactiveError):
            lock_active_dataset(connection, "a", "one")
        assert (
            connection.execute(sa.select(operations.c.status)).scalar_one()
            == "uncertain"
        )
    retry = call(env, "graph/clear", token="manager", method="POST", body=body)
    assert retry.status_code == 409
    env.factory.assert_called_once()


def test_compatibility_dispatch_blocks_clear_even_after_rpc_confirmation(env):
    with env.engine.begin() as connection:
        connection.execute(
            operations.insert().values(
                operation_id="legacy",
                workflow_id="legacy",
                action="source",
                input_digest="hash",
                status="confirmed",
                payload={
                    "workflow_input": {
                        "dataset_id": "one",
                        "ingestion": {
                            "graph": True,
                            "neo4j_namespace": "physical-graph-one",
                        },
                    }
                },
            )
        )
    body = {"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"}
    assert (
        call(env, "graph/clear", token="manager", method="POST", body=body).status_code
        == 409
    )
    env.factory.assert_not_called()


def test_admission_is_durable_across_instances(env):
    prepare_clear(env.engine, "a", "one")
    with env.engine.begin() as connection:
        with pytest.raises(DatasetInactiveError):
            lock_active_dataset(connection, "a", "one")
        assert lock_active_dataset(connection, "a", "two").dataset_id == "two"


def test_semantic_search_reuses_query_handler_without_generation(env, monkeypatch):
    from hawki_backend.ops import api

    seen = []

    def query(body, principal, request):
        seen.append(body)
        return SimpleNamespace(kg=[{"subject": "Shared", "object": "Other"}])

    monkeypatch.setattr(api, "query", query)
    env.graph.graph_search.return_value = []
    response = call(env, "graph/semantic-search?dataset_id=one&q=Shared")
    assert response.status_code == 200
    assert seen[0].generate is False
    assert seen[0].dataset_id == "one"
    env.graph.graph_search.assert_called_once_with(
        query="Shared", names=["Other", "Shared"], limit=8
    )


def test_source_enrichment_uses_only_current_python_managed_revision(env):
    with env.engine.begin() as connection:
        connection.execute(
            documents.insert().values(
                managed_document_id="managed",
                tenant_id="a",
                dataset_id="one",
                principal_id="reader",
                generation=1,
                current_revision_id="revision",
                status="indexed",
                attributes={"display_name": "Python source"},
            )
        )
        connection.execute(
            revisions.insert().values(
                revision_id="revision",
                managed_document_id="managed",
                tenant_id="a",
                dataset_id="one",
                generation=1,
                expected_generation=0,
                mutation_id="mutation",
                source_checksum="hash",
                upload_path="/never/read",
                source_id="source",
                task_id="task",
                job_id="job",
                workflow_id="workflow",
                status="ready",
                workflow_input={},
                writer_state={"managed_targets": {"known": {}}},
            )
        )
    response = call(env)
    assert response.status_code == 200
    sources = response.json()["nodes"][0]["source_documents"]
    assert sources[0]["label"] == "Python source"
    assert sources[0]["missing"] is False
    assert sources[1]["missing"] is True


@pytest.mark.parametrize(
    "path", ["graph/overview", "graph/search?q=test", "graph/node?node_id=x"]
)
def test_dataset_is_required(env, path):
    assert call(env, path).status_code == 422


@pytest.mark.parametrize("limit", [0, 301])
def test_overview_limits(env, limit):
    assert call(env, f"graph/overview?dataset_id=one&limit={limit}").status_code == 422


def test_graph_and_catalog_failure_are_unavailable(env):
    env.factory.side_effect = RuntimeError("private-driver-message")
    response = call(env)
    assert response.status_code == 503
    assert "private" not in response.text
    env.catalog.available_collections.return_value = None
    assert call(env, "stats", token="manager").status_code == 503


def test_internal_clear_admission_and_receipt_require_manager_and_exact_tenant(env):
    body = {"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"}
    assert (
        call(
            env, "graph/clear-admission", token=None, method="POST", body=body
        ).status_code
        == 401
    )
    assert (
        call(env, "graph/clear-admission", method="POST", body=body).status_code == 403
    )
    admitted = call(
        env, "graph/clear-admission", token="manager", method="POST", body=body
    )
    assert admitted.status_code == 200
    receipt = {
        "dataset_id": "foreign",
        "operation_id": admitted.json()["operation_id"],
        "success": True,
    }
    assert (
        call(
            env, "graph/clear-receipt", token="manager", method="POST", body=receipt
        ).status_code
        == 404
    )
    receipt["dataset_id"] = "one"
    assert (
        call(
            env, "graph/clear-receipt", token="manager", method="POST", body=receipt
        ).status_code
        == 200
    )


def test_physical_scope_cannot_be_supplied_in_mutation_body(env):
    body = {
        "dataset_id": "one",
        "confirm": "CLEAR_DATASET_GRAPH",
        "neo4j_namespace": "foreign",
    }
    assert (
        call(env, "graph/clear", token="manager", method="POST", body=body).status_code
        == 422
    )
    env.factory.assert_not_called()


@pytest.mark.parametrize("protocol,status", [(None, 409), (0, 409), (1, 200)])
def test_empty_ledger_needs_protocol_bound_durable_closure(env, protocol, status):
    from .test_postgres import seed_text
    from hawki_backend.text_ingestion.schema import ingest_text_operations

    with env.engine.begin() as connection:
        seed_text(connection, status="failed")
        connection.execute(
            ingest_text_operations.update().values(
                request_metadata={
                    "managed_writer_gate": "closed",
                    "managed_no_writers_closed": True,
                    "managed_writers": {},
                    "managed_writer_protocol": protocol,
                    "workflow_input": {"text_writer_protocol": 1},
                }
            )
        )
    response = call(
        env,
        "graph/clear",
        token="manager",
        method="POST",
        body={"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"},
    )
    assert response.status_code == status


@pytest.mark.parametrize(
    "action", ["overview", "search", "node", "expand", "semantic-search", "scope"]
)
def test_signed_query_token_cannot_authorize_another_dataset(env, action):
    import time
    import jwt
    from cryptography.hazmat.primitives.asymmetric import rsa
    from hawki_backend.auth.jwt import JwtAuthenticator
    from hawki_backend.auth.jwks import StaticKeyProvider
    from hawki_backend.auth.models import PrincipalKind
    from hawki_backend.persistence.schema import dataset_grants
    from hawki_backend.settings import AuthSettings

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    env.app.state.authenticator = JwtAuthenticator(
        AuthSettings(
            issuer="https://bff.test",
            audience="hawki-backend",
            jwks_url="https://bff.test/jwks",
            clients={"hawki-bff": PrincipalKind.USER},
        ),
        StaticKeyProvider({"test": key.public_key()}),
    )
    now = int(time.time())
    token = jwt.encode(
        {
            "iss": "https://bff.test",
            "aud": "hawki-backend",
            "azp": "hawki-bff",
            "sub": "reader",
            "tenant_id": "a",
            "scope": "rag:query",
            "iat": now,
            "exp": now + 60,
            "jti": "signed-graph-test",
        },
        key,
        algorithm="RS256",
        headers={"kid": "test"},
    )
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.delete().where(dataset_grants.c.dataset_id == "two")
        )
    query = "?dataset_id=two&q=secret&node_id=opaque"
    response = call(
        env,
        "graph/" + action + query,
        token=token,
        method="POST" if action == "expand" else "GET",
        body={"dataset_id": "two", "node_id": "opaque"} if action == "expand" else None,
    )
    assert response.status_code == 404
    env.factory.assert_not_called()
    # Same verified identity still has its exact Dataset A grant.
    assert call(env, "graph/scope?dataset_id=one", token=token).status_code == 200


def test_uncertain_laravel_clear_receipt_keeps_fence_until_valid_reconciliation(env):
    body = {"dataset_id": "one", "confirm": "CLEAR_DATASET_GRAPH"}
    admitted = call(
        env, "graph/clear-admission", token="manager", method="POST", body=body
    )
    assert admitted.status_code == 200
    operation = admitted.json()["operation_id"]
    receipt = {"dataset_id": "one", "operation_id": operation, "success": False}
    response = call(
        env, "graph/clear-receipt", token="manager", method="POST", body=receipt
    )
    assert response.json() == {"ok": False}
    with env.engine.begin() as connection:
        row = (
            connection.execute(
                sa.select(operations).where(operations.c.operation_id == operation)
            )
            .mappings()
            .one()
        )
        assert row["status"] == "uncertain" and row["receipt"] is None
        with pytest.raises(DatasetInactiveError):
            lock_active_dataset(connection, "a", "one")
    assert (
        call(
            env, "graph/clear-admission", token="manager", method="POST", body=body
        ).status_code
        == 409
    )
    env.factory.assert_not_called()
    # Only a subsequent trusted sink acknowledgement can reconcile the same operation.
    receipt.update(success=True, deleted_nodes=0, deleted_relationships=0)
    assert call(
        env, "graph/clear-receipt", token="manager", method="POST", body=receipt
    ).json() == {"ok": True}
    with env.engine.begin() as connection:
        assert lock_active_dataset(connection, "a", "one").dataset_id == "one"


@pytest.mark.parametrize(
    "available", [None, set(), {"physical-vector-one", "physical-vector-two"}]
)
def test_stats_fails_closed_when_canonical_collections_are_missing(env, available):
    env.catalog.available_collections.return_value = available
    response = call(env, "stats", token="manager")
    assert response.status_code == 503
    assert response.json() == {"detail": "ops_unavailable"}


def test_existing_empty_canonical_collections_are_healthy(env):
    env.catalog.point_count.return_value = 0
    response = call(env, "stats", token="manager")
    assert response.status_code == 200
    assert response.json()["qdrant"]["ok"] is True
    assert all(row["count"] == 0 for row in response.json()["qdrant"]["collections"])
