import os
import time
import unittest

import requests


SKIP_REASON = "Integration tests require HAWKI_RAG_BRIDGE_URL and services running"

def _env(name: str, default: str | None = None) -> str | None:
    value = os.environ.get(name, default)
    return value.rstrip("/") if value else value


def _first_env(names: list[str]) -> str | None:
    for name in names:
        value = _env(name)
        if value:
            return value
    return None


class LightRAGIntegrationTests(unittest.TestCase):
    """End-to-end smoke test covering HAWKI RAG ingestion and retrieval."""
    @classmethod
    def setUpClass(cls) -> None:
        cls.bridge_url = _first_env(["HAWKI_RAG_BRIDGE_URL"])
        if not cls.bridge_url:
            raise unittest.SkipTest(SKIP_REASON)

    def test_ingest_and_query_roundtrip(self):
        # 1. Ingest a tiny document via the bridge
        doc_text = "HAWKI RAG integration smoke test document"
        ingest_payload = {
            "docs": [
                {"id": "integration-test", "text": doc_text, "payload": {}},
            ],
            "graph": False,
        }
        resp = requests.post(
            f"{self.bridge_url}/ingest",
            json=ingest_payload,
            timeout=30,
        )
        self.assertTrue(resp.ok, msg=resp.text[:200])

        # 2. Allow background tasks to finish
        time.sleep(2)

        # 3. Run query via production bridge (Qdrant + Neo4j)
        query_payload = {"query": "integration smoke test", "top_k": 1, "generate": False}
        resp = requests.post(
            f"{self.bridge_url}/query",
            json=query_payload,
            timeout=30,
        )
        self.assertTrue(resp.ok, msg=resp.text[:200])
        data = resp.json()
        self.assertGreaterEqual(data.get("count", 0), 0)
        self.assertIn("hits", data)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
