"""Public managed reads use query grants and Python lifecycle state."""

from dataclasses import replace

import pytest

from hawki_backend.persistence.schema import dataset_grants

from .conftest import send
from .test_lifecycle import create, ready, released, revision


@pytest.fixture
def reader(env):
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="files",
                principal_type="user",
                principal_id="42",
                permission="query",
            )
        )
    env.authenticator.authenticate.return_value = replace(
        env.principal, scopes=frozenset({"rag:ingest", "rag:query"})
    )
    return env


def test_create_then_public_detail_and_list_without_laravel_rows(reader):
    value = create(reader)
    doc_id = value["document"]["id"]
    detail = send(reader, path="/v1/documents/" + doc_id, method="GET")
    assert detail.status_code == 200, detail.text
    assert detail.json()["document"]["id"] == doc_id
    listing = send(reader, method="GET")
    assert listing.status_code == 200
    assert [row["id"] for row in listing.json()["documents"]] == [doc_id]
    assert "markdown_preview" not in listing.json()["documents"][0]


def test_completion_is_authoritative_for_reads(reader):
    value = create(reader)
    row = revision(reader, value)
    released(reader, row)
    ready(reader, row)
    detail = send(reader, path="/v1/documents/" + value["document"]["id"], method="GET")
    assert detail.json()["document"]["status"] == "indexed"
    assert detail.json()["document"]["active_output_count"] == 1


@pytest.mark.parametrize("denial", ["grant", "tenant", "user", "scope", "inactive"])
def test_reads_never_substitute_ingest_for_query_access(reader, denial):
    value = create(reader)
    if denial == "inactive":
        from hawki_backend.persistence.schema import datasets

        with reader.engine.begin() as connection:
            connection.execute(datasets.update().values(status="archived"))
    elif denial == "grant":
        with reader.engine.begin() as connection:
            connection.execute(
                dataset_grants.delete().where(dataset_grants.c.permission == "query")
            )
    else:
        field = {"tenant": "tenant_id", "user": "subject", "scope": "scopes"}[denial]
        reader.authenticator.authenticate.return_value = replace(
            reader.authenticator.authenticate.return_value,
            **{field: frozenset({"rag:ingest"}) if denial == "scope" else "wrong"},
        )
    detail = send(reader, path="/v1/documents/" + value["document"]["id"], method="GET")
    assert detail.status_code == (403 if denial == "scope" else 404)
    listing = send(reader, method="GET")
    assert listing.status_code == (403 if denial == "scope" else 200)
    if listing.status_code == 200:
        assert listing.json()["documents"] == []


def test_unknown_id_is_404(reader):
    assert (
        send(reader, path="/v1/documents/adoc_unknown", method="GET").status_code == 404
    )


def test_query_only_principal_can_read_without_ingest_scope(reader):
    value = create(reader)
    reader.authenticator.authenticate.return_value = replace(
        reader.principal, scopes=frozenset({"rag:query"})
    )
    assert (
        send(
            reader, path="/v1/documents/" + value["document"]["id"], method="GET"
        ).status_code
        == 200
    )


def test_list_aliases_search_limit_and_deleted_filter(reader):
    first = create(reader, files=[("file", ("first.txt", b"hello"))])
    create(reader, key="second", files=[("file", ("second.txt", b"hello"))])
    listing = send(
        reader, path="/v1/documents?datasetId=files&search=first&limit=1", method="GET"
    )
    assert [row["id"] for row in listing.json()["documents"]] == [
        first["document"]["id"]
    ]
    row = revision(reader, first)
    released(reader, row)
    assert (
        send(
            reader, path="/v1/documents/" + first["document"]["id"], method="DELETE"
        ).status_code
        == 200
    )
    assert len(send(reader, method="GET").json()["documents"]) == 1


def test_legacy_access_requires_query_grant_and_absence_of_python_owner(reader):
    from .conftest import request_json

    value = create(reader)
    response = request_json(
        reader,
        "/v1/documents/read-access",
        {
            "documents": [
                {"document_id": value["document"]["id"], "dataset_id": "files"},
                {"document_id": "adoc_legacy", "dataset_id": "files"},
                {"document_id": "adoc_denied", "dataset_id": "other"},
            ]
        },
    )
    assert response.status_code == 200
    assert response.json() == {"legacy_document_ids": ["adoc_legacy"]}
