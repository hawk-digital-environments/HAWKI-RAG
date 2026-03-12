import unittest
from unittest.mock import MagicMock, patch

# Allow running tests without installing the package
import pathlib
import sys
import types

ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE_DIR = ROOT / "python_rag"
if MODULE_DIR.exists():
    sys.path.insert(0, str(MODULE_DIR))

try:
    from neo4j.exceptions import Neo4jError
except ModuleNotFoundError:  # pragma: no cover - fallback for local testing
    neo4j_module = types.ModuleType("neo4j")
    exceptions_module = types.ModuleType("neo4j.exceptions")

    class Neo4jError(Exception):
        pass

    exceptions_module.Neo4jError = Neo4jError

    class _DummyGraphDatabase:
        @staticmethod
        def driver(*args, **kwargs):
            return MagicMock()

    neo4j_module.GraphDatabase = _DummyGraphDatabase
    neo4j_module.exceptions = exceptions_module

    sys.modules["neo4j"] = neo4j_module
    sys.modules["neo4j.exceptions"] = exceptions_module

from neo4j.exceptions import Neo4jError
from graph.neo4j_graph import Neo4jGraph


class Neo4jGraphTests(unittest.TestCase):
    """Unit tests for the Neo4j helper functions."""
    def setUp(self):
        driver_patch = patch("graph.neo4j_graph.GraphDatabase.driver", return_value=MagicMock())
        self.addCleanup(driver_patch.stop)
        driver_patch.start()

        self.graph = Neo4jGraph()
        self.graph._driver = MagicMock()

    def test_fetch_related_returns_records(self):
        session = self.graph._driver.session.return_value.__enter__.return_value
        session.execute_read.return_value = [
            {"subject": "HAWK", "relation": "LOCATED_IN", "object": "Lower Saxony"}
        ]

        facts = self.graph.fetch_related(["HAWK"], limit=5)

        self.assertEqual(len(facts), 1)
        self.assertEqual(facts[0]["relation"], "LOCATED_IN")

    def test_fetch_related_handles_exception(self):
        session = self.graph._driver.session.return_value.__enter__.return_value
        session.execute_read.side_effect = Neo4jError("neo4j down")

        with patch("graph.neo4j_graph.logger"):
            facts = self.graph.fetch_related(["HAWK"], limit=5)

        self.assertEqual(facts, [])


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
