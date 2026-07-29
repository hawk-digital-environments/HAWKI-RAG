"""Live Neo4j scenarios for dataset and namespace isolation.

Every node, relationship, document ID, and namespace is unique to one test run.
The cleanup query is parameterized and can only match those test namespaces.
"""

from __future__ import annotations

from typing import Any
from uuid import uuid4

import pytest


pytestmark = pytest.mark.integration


@pytest.fixture
def neo4j_scope_resources(live_neo4j: Any) -> dict[str, str]:
    """Reserve unique graph scopes and remove all matching nodes at teardown."""

    token = uuid4().hex
    resources = {
        "dataset_a": f"rawki-it-dataset-a-{token}",
        "dataset_b": f"rawki-it-dataset-b-{token}",
        "namespace_a": f"rawki_it_graph_a_{token}",
        "namespace_b": f"rawki_it_graph_b_{token}",
        "doc_a": f"rawki-it-doc-a-{token}",
        "doc_b": f"rawki-it-doc-b-{token}",
        "subject_a": f"RAWKI Alpha Subject {token}",
        "subject_b": f"RAWKI Beta Subject {token}",
        "object_a": f"RAWKI Alpha Object {token}",
        "object_b": f"RAWKI Beta Object {token}",
        "term": token,
    }
    try:
        yield resources
    finally:
        with live_neo4j.driver.session(database=live_neo4j.settings.database) as session:
            session.run(
                "MATCH (n:Entity) "
                "WHERE n.neo4j_namespace IN $namespaces "
                "DETACH DELETE n",
                namespaces=[resources["namespace_a"], resources["namespace_b"]],
            ).consume()


class TestLiveNeo4jScoping:
    """Prove scoped writes and reads cannot cross real Neo4j graph partitions."""

    def test_fact_and_structural_reads_require_matching_dataset_and_namespace(
        self,
        live_neo4j: Any,
        neo4j_scope_resources: dict[str, str],
    ) -> None:
        from infrastructure.graph.neo4j_graph import Neo4jGraph

        resource = neo4j_scope_resources
        graph_a = Neo4jGraph(
            dataset_id=resource["dataset_a"],
            neo4j_namespace=resource["namespace_a"],
            settings=live_neo4j.settings,
            allow_database_fallback=False,
        )
        graph_b = Neo4jGraph(
            dataset_id=resource["dataset_b"],
            neo4j_namespace=resource["namespace_b"],
            settings=live_neo4j.settings,
            allow_database_fallback=False,
        )

        try:
            graph_a.upsert_triplets(
                [(resource["subject_a"], "belongs_to_alpha", resource["object_a"])],
                doc_id=resource["doc_a"],
                request_id=f"rawki-it-upsert-a-{resource['term']}",
            )
            graph_b.upsert_triplets(
                [(resource["subject_b"], "belongs_to_beta", resource["object_b"])],
                doc_id=resource["doc_b"],
                request_id=f"rawki-it-upsert-b-{resource['term']}",
            )

            facts_a = graph_a.fetch_related(
                [resource["term"]],
                dataset_id=resource["dataset_a"],
                neo4j_namespace=resource["namespace_a"],
            )
            facts_b = graph_b.fetch_related(
                [resource["term"]],
                dataset_id=resource["dataset_b"],
                neo4j_namespace=resource["namespace_b"],
            )
            crossed_facts = graph_a.fetch_related(
                [resource["term"]],
                dataset_id=resource["dataset_a"],
                neo4j_namespace=resource["namespace_b"],
            )
            structural_a = graph_a.search_structural(
                [resource["term"]],
                dataset_id=resource["dataset_a"],
                neo4j_namespace=resource["namespace_a"],
                hops=2,
                include_rel_match=True,
            )

            assert facts_a == [
                {
                    "subject": resource["subject_a"],
                    "relation": "belongs_to_alpha",
                    "object": resource["object_a"],
                }
            ]
            assert facts_b == [
                {
                    "subject": resource["subject_b"],
                    "relation": "belongs_to_beta",
                    "object": resource["object_b"],
                }
            ]
            assert crossed_facts == []
            assert len(structural_a) == 1
            assert structural_a[0]["subject"] == resource["subject_a"]
            assert structural_a[0]["object"] == resource["object_a"]
            assert structural_a[0]["doc_id"] == resource["doc_a"]
        finally:
            # Exercise the adapter's normal scoped deletion before the fixture's
            # defensive namespace cleanup runs.
            try:
                graph_a.delete_by_doc_id(
                    resource["doc_a"],
                    request_id=f"rawki-it-delete-a-{resource['term']}",
                )
                graph_b.delete_by_doc_id(
                    resource["doc_b"],
                    request_id=f"rawki-it-delete-b-{resource['term']}",
                )
            finally:
                graph_a.close()
                graph_b.close()

    def test_unscoped_write_is_refused_without_touching_live_neo4j(
        self,
        live_neo4j: Any,
        neo4j_scope_resources: dict[str, str],
    ) -> None:
        from infrastructure.graph.neo4j_graph import Neo4jGraph

        resource = neo4j_scope_resources
        graph = Neo4jGraph(
            settings=live_neo4j.settings,
            allow_database_fallback=False,
        )
        try:
            graph.upsert_triplets(
                [(resource["subject_a"], "must_not_exist", resource["object_a"])],
                doc_id=resource["doc_a"],
            )
        finally:
            graph.close()

        with live_neo4j.driver.session(database=live_neo4j.settings.database) as session:
            record = session.run(
                "MATCH (n:Entity) WHERE n.name IN $names RETURN count(n) AS count",
                names=[resource["subject_a"], resource["object_a"]],
            ).single()

        assert record is not None
        assert record["count"] == 0
