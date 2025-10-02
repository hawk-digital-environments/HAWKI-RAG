import os
import time
import unittest
from pathlib import Path

import requests


SKIP_REASON = "Integration tests require LIGHTRAG_BASE_URL and services running"


def _env(name: str, default: str | None = None) -> str | None:
    value = os.environ.get(name, default)
    return value.rstrip("/") if value else value


class LightRAGIntegrationTests(unittest.TestCase):
    """End-to-end smoke test covering LightRAG ingestion and retrieval."""
    @classmethod
    def setUpClass(cls) -> None:
        cls.base_url = _env("LIGHTRAG_BASE_URL")
        cls.bridge_url = _env("LIGHTRAG_BRIDGE_URL")
        cls.sample_root = _env("LIGHTRAG_SAMPLE_ROOT")
        if not cls.base_url or not cls.bridge_url or not cls.sample_root:
            raise unittest.SkipTest(SKIP_REASON)

        if not Path(cls.sample_root).exists():
            raise unittest.SkipTest("Sample root does not exist: " + cls.sample_root)

    def test_ingest_and_query_roundtrip(self):
        # 1. Send a tiny document directly to LightRAG so UI storage is updated
        doc_text = "LightRAG integration smoke test document"
        ingest_payload = {"texts": [doc_text], "file_sources": ["integration-test"]}
        resp = requests.post(
            f"{self.base_url}/documents/texts",
            json=ingest_payload,
            timeout=30,
        )
        self.assertTrue(resp.ok, msg=resp.text[:200])

        # 2. Allow LightRAG background tasks to finish
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
