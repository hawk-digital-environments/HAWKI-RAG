"""HTTP contract tests for the dataset authority routes."""

from __future__ import annotations

import asyncio

import httpx

from hawki_backend.datasets import (
    DatasetNotFoundError,
    DatasetUnavailableError,
    GrantTokenInvalidError,
    GrantTokenRequiredError,
    InvalidDatasetRequestError,
)
from hawki_backend.main import create_app

from .doubles import (
    TENANT,
    FakeAuthenticator,
    FakeDatasetService,
    creation,
    deletion,
    service_principal,
    user_principal,
)

ADMIN = "rag:datasets:manage"


def _app(principal, service: FakeDatasetService | None = None):
    return create_app(
        authenticator=FakeAuthenticator(principal),
        dataset_service=service,
    )


def _request(
    application,
    method: str,
    path: str,
    *,
    token: str | None = "valid",
    body: dict | None = None,
):
    headers = {"Authorization": f"Bearer {token}"} if token is not None else {}

    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=application),
            base_url="http://testserver",
            headers=headers,
        ) as client:
            return await client.request(method, path, json=body)

    return asyncio.run(call())


# ------------------------------------------------------------------ route shape


def test_dataset_routes_are_absent_without_a_dataset_service() -> None:
    app = _app(service_principal(scopes=(ADMIN,)))

    assert _request(app, "GET", "/v1/datasets").status_code == 404
    assert _request(app, "GET", "/v1/query/datasets").status_code == 404


def test_management_routes_require_the_internal_management_scope() -> None:
    app = _app(user_principal(scopes=("rag:query",)), FakeDatasetService())

    no_token = _request(app, "GET", "/v1/datasets", token=None)
    wrong_scope = _request(app, "GET", "/v1/datasets")

    assert no_token.status_code == 401
    assert no_token.json() == {"detail": "authentication_required"}
    assert wrong_scope.status_code == 403
    assert wrong_scope.json() == {"detail": "insufficient_scope"}


def test_management_routes_accept_the_bff_service_principal() -> None:
    service = FakeDatasetService(list_result=[{"dataset_id": "dataset-a", "name": "A"}])
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets", body=None)

    assert response.status_code == 200
    assert response.json() == {
        "success": True,
        "datasets": [{"dataset_id": "dataset-a", "name": "A"}],
    }


# ----------------------------------------------------------------------- list


def test_list_forwards_the_authenticated_tenant_and_bounded_limit() -> None:
    service = FakeDatasetService(list_result=[])
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets?limit=7")

    assert response.status_code == 200
    assert response.json() == {"success": True, "datasets": []}
    assert service.calls == [("list", TENANT, 7)]


def test_list_maps_a_store_outage_to_the_public_failure() -> None:
    service = FakeDatasetService(error=DatasetUnavailableError())
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets")

    assert response.status_code == 503
    assert response.json() == {
        "message": "The dataset service is not available.",
        "error": "dataset_unavailable",
    }


# --------------------------------------------------------------------- create


def test_create_returns_the_one_time_token_only_on_first_creation() -> None:
    service = FakeDatasetService(
        creation=creation(dataset_id="dataset-a", token="dgt_" + "a" * 64),
        show_result={"dataset_id": "dataset-a", "name": "Dataset A"},
    )
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(
        app,
        "POST",
        "/v1/datasets",
        body={"dataset_id": "dataset-a", "name": "Dataset A"},
    )

    assert response.status_code == 201
    assert response.json() == {
        "success": True,
        "dataset_id": "dataset-a",
        "dataset": {"dataset_id": "dataset-a", "name": "Dataset A"},
        "grant_token": "dgt_" + "a" * 64,
    }
    assert service.calls[0] == (
        "create",
        TENANT,
        {"dataset_id": "dataset-a", "name": "Dataset A"},
    )
    assert service.calls[1] == ("show", TENANT, "dataset-a")


def test_compatible_create_replay_is_200_without_a_token() -> None:
    service = FakeDatasetService(
        creation=creation(dataset_id="dataset-a", token=None, created=False),
        show_result={"dataset_id": "dataset-a", "name": "Dataset A"},
    )
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "POST", "/v1/datasets", body={"dataset_id": "dataset-a"})

    assert response.status_code == 200
    assert "grant_token" not in response.json()


def test_create_forwards_forbidden_scope_fields_and_drops_unknown_fields() -> None:
    service = FakeDatasetService(
        creation=creation(),
        show_result={"dataset_id": "dataset-a"},
    )
    app = _app(service_principal(scopes=(ADMIN,)), service)

    _request(
        app,
        "POST",
        "/v1/datasets",
        body={
            "dataset_id": "dataset-a",
            "datasetId": "ignored-alias",
            "name": "A",
            "description": "D",
            "status": "active",
            "qdrant_collection": "hawki_a",
            "qdrantCollection": "ignored",
            "neo4j_namespace": "graph_a",
            "neo4jNamespace": "ignored",
            "embedding_provider": "openai",
            "graph_enabled": True,
            "tenant_id": "tenant-b",
            "unexpected": "never forwarded",
        },
    )

    # The service must see every scope field so it can refuse it. Unknown
    # fields are never forwarded into the authority.
    assert service.calls[0][2] == {
        "dataset_id": "dataset-a",
        "datasetId": "ignored-alias",
        "name": "A",
        "description": "D",
        "status": "active",
        "qdrant_collection": "hawki_a",
        "qdrantCollection": "ignored",
        "neo4j_namespace": "graph_a",
        "neo4jNamespace": "ignored",
        "embedding_provider": "openai",
        "graph_enabled": True,
        "tenant_id": "tenant-b",
    }


def test_create_forwards_an_explicit_null_scope_field_for_refusal() -> None:
    service = FakeDatasetService(
        creation=creation(),
        show_result={"dataset_id": "dataset-a"},
    )
    app = _app(service_principal(scopes=(ADMIN,)), service)

    _request(
        app,
        "POST",
        "/v1/datasets",
        body={"dataset_id": "dataset-a", "qdrant_collection": None},
    )

    # The caller explicitly touched the field. Presence is enough for the
    # service to refuse it; a null value must not be silently dropped.
    assert service.calls[0][2] == {
        "dataset_id": "dataset-a",
        "qdrant_collection": None,
    }


def test_create_maps_a_forbidden_scope_field_to_422() -> None:
    service = FakeDatasetService(error=InvalidDatasetRequestError())
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(
        app,
        "POST",
        "/v1/datasets",
        body={"dataset_id": "dataset-a", "qdrant_collection": "chosen"},
    )

    assert response.status_code == 422
    assert response.json() == {
        "message": "The given data was invalid.",
        "error": "invalid_dataset_request",
    }


# ----------------------------------------------------------------------- show


def test_show_returns_the_active_dataset_details() -> None:
    service = FakeDatasetService(
        show_result={
            "dataset_id": "dataset-a",
            "name": "A",
            "tasks": [],
            "ingestion_history": [],
        }
    )
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets/dataset-a")

    assert response.status_code == 200
    assert response.json()["success"] is True
    assert response.json()["dataset"]["dataset_id"] == "dataset-a"


def test_show_uses_the_legacy_not_found_envelope() -> None:
    service = FakeDatasetService(show_result=None)
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets/A%20dataset")

    assert response.status_code == 404
    assert response.json() == {
        "success": False,
        "message": "Dataset A dataset was not found.",
    }


def test_show_maps_the_authority_not_found_error() -> None:
    service = FakeDatasetService(error=DatasetNotFoundError())
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "GET", "/v1/datasets/dataset-a")

    assert response.status_code == 404
    assert response.json() == {
        "success": False,
        "message": "Dataset dataset-a was not found.",
    }


# --------------------------------------------------------------------- delete


def test_delete_reports_pending_cleanup_with_202() -> None:
    service = FakeDatasetService(deletion=deletion(status="archived", pending=True))
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "DELETE", "/v1/datasets/dataset-a/storage")

    assert response.status_code == 202
    assert response.json() == {
        "success": True,
        "message": "Dataset dataset-a is hidden. Scoped cleanup will continue "
        "automatically in the background.",
        "cleanup": {
            "dataset_id": "dataset-a",
            "status": "archived",
            "cleanup_pending": True,
        },
    }


def test_delete_reports_a_completed_tombstone_with_200() -> None:
    service = FakeDatasetService(deletion=deletion(status="deleted", pending=False))
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "DELETE", "/v1/datasets/dataset-a/storage")

    assert response.status_code == 200
    assert response.json()["message"] == "Dataset dataset-a cleanup is complete."
    assert response.json()["cleanup"]["status"] == "deleted"


def test_delete_refuses_an_unknown_dataset() -> None:
    service = FakeDatasetService(deletion=None)
    app = _app(service_principal(scopes=(ADMIN,)), service)

    response = _request(app, "DELETE", "/v1/datasets/dataset-a/storage")

    assert response.status_code == 404
    assert response.json() == {
        "success": False,
        "message": "Dataset dataset-a was not found.",
    }


# ------------------------------------------------------------- self-grant query


def test_query_self_grant_requires_a_user_query_principal() -> None:
    service = FakeDatasetService()
    app = _app(service_principal(scopes=(ADMIN, "rag:query")), service)

    response = _request(app, "POST", "/v1/datasets/dataset-a/query-grants/self")

    assert response.status_code == 403
    assert response.json() == {"detail": "insufficient_scope"}


def test_query_self_grant_returns_the_exact_legacy_envelope() -> None:
    service = FakeDatasetService()
    app = _app(user_principal(scopes=("rag:query",)), service)

    response = _request(app, "POST", "/v1/datasets/dataset-a/query-grants/self")

    assert response.status_code == 200
    assert response.json() == {
        "success": True,
        "dataset_id": "dataset-a",
        "query_access": {"granted": True, "permission": "query"},
    }
    assert service.calls[0][2] == "dataset-a"


def test_query_self_grant_maps_a_store_outage() -> None:
    service = FakeDatasetService(error=DatasetUnavailableError())
    app = _app(user_principal(scopes=("rag:query",)), service)

    response = _request(app, "POST", "/v1/datasets/dataset-a/query-grants/self")

    assert response.status_code == 503
    assert response.json()["error"] == "dataset_unavailable"


# ------------------------------------------------------------ self-grant ingest


def test_ingest_self_grant_requires_the_ingest_scope() -> None:
    service = FakeDatasetService()
    app = _app(user_principal(scopes=("rag:query",)), service)

    response = _request(app, "POST", "/v1/datasets/dataset-a/ingest-grants/self")

    assert response.status_code == 403
    assert response.json() == {"detail": "insufficient_scope"}


def test_ingest_self_grant_passes_the_optional_token() -> None:
    service = FakeDatasetService(replayed=False)
    app = _app(user_principal(scopes=("rag:ingest",)), service)

    response = _request(
        app,
        "POST",
        "/v1/datasets/dataset-a/ingest-grants/self",
        body={"grant_token": "dgt_" + "a" * 64},
    )

    assert response.status_code == 200
    assert response.json() == {
        "success": True,
        "dataset_id": "dataset-a",
        "ingest_access": {"granted": True, "permission": "ingest"},
        "replayed": False,
    }
    call = service.calls[0]
    assert call[0] == "grant_ingest"
    assert call[2] == "dataset-a"
    assert call[3] == "dgt_" + "a" * 64


def test_ingest_self_grant_accepts_an_absent_body() -> None:
    service = FakeDatasetService(error=GrantTokenRequiredError())
    app = _app(user_principal(scopes=("rag:ingest",)), service)

    response = _request(app, "POST", "/v1/datasets/dataset-a/ingest-grants/self")

    assert response.status_code == 403
    assert response.json()["error"] == "grant_token_required"
    assert service.calls[0][3] is None


def test_ingest_self_grant_maps_required_and_invalid_tokens() -> None:
    app = _app(
        user_principal(scopes=("rag:ingest",)),
        FakeDatasetService(error=GrantTokenRequiredError()),
    )
    required = _request(app, "POST", "/v1/datasets/dataset-a/ingest-grants/self")
    assert required.status_code == 403
    assert required.json()["error"] == "grant_token_required"

    app = _app(
        user_principal(scopes=("rag:ingest",)),
        FakeDatasetService(error=GrantTokenInvalidError()),
    )
    invalid = _request(
        app,
        "POST",
        "/v1/datasets/dataset-a/ingest-grants/self",
        body={"grant_token": "dgt_" + "b" * 64},
    )
    assert invalid.status_code == 403
    assert invalid.json()["error"] == "grant_token_invalid"


def test_ingest_self_grant_ignores_an_extra_body_field() -> None:
    service = FakeDatasetService(error=GrantTokenRequiredError())
    app = _app(user_principal(scopes=("rag:ingest",)), service)

    response = _request(
        app,
        "POST",
        "/v1/datasets/dataset-a/ingest-grants/self",
        body={"dataset_id": "another"},
    )

    assert response.status_code == 403
    assert response.json()["error"] == "grant_token_required"
    assert service.calls[0][3] is None


# ------------------------------------------------------------- query visibility


def test_query_datasets_of_a_service_principal_are_empty() -> None:
    app = _app(service_principal(scopes=(ADMIN, "rag:query")), FakeDatasetService())

    response = _request(app, "GET", "/v1/query/datasets")

    assert response.status_code == 200
    assert response.json() == {"success": True, "datasets": []}


def test_query_datasets_returns_the_legacy_list_envelope() -> None:
    service = FakeDatasetService(
        query_datasets_result=[{"dataset_id": "dataset-a", "name": "A"}]
    )
    app = _app(user_principal(scopes=("rag:query",)), service)

    response = _request(app, "GET", "/v1/query/datasets")

    assert response.status_code == 200
    assert response.json() == {
        "success": True,
        "datasets": [{"dataset_id": "dataset-a", "name": "A"}],
    }


def test_query_datasets_reports_a_store_outage() -> None:
    service = FakeDatasetService(error=DatasetUnavailableError())
    app = _app(user_principal(scopes=("rag:query",)), service)

    response = _request(app, "GET", "/v1/query/datasets")

    assert response.status_code == 503
    assert response.json()["error"] == "dataset_unavailable"
