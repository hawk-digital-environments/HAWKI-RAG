import unittest
from unittest.mock import MagicMock, patch

# Allow running tests without installing the package
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE_DIR = ROOT / "python_rag"
if MODULE_DIR.exists():
    sys.path.insert(0, str(MODULE_DIR))

from vectorstore.qdrant_http import QdrantHTTP
from requests import RequestException


class QdrantHTTPTests(unittest.TestCase):
    """Unit tests for the lightweight Qdrant client."""
    def setUp(self):
        self.client = QdrantHTTP()
        self.client.collection = "test"
        self.client._session = MagicMock()

    def test_request_retries_and_success(self):
        calls = []

        def side_effect(method, url, **kwargs):
            calls.append((method, url))
            if len(calls) < 2:
                raise RequestException("boom")
            mock_resp = MagicMock()
            mock_resp.status_code = 200
            mock_resp.json.return_value = {}
            return mock_resp

        self.client._session.request.side_effect = side_effect

        with patch("vectorstore.qdrant_http.logger"):
            response = self.client._request("GET", "/collections/test")

        self.assertEqual(response.status_code, 200)
        self.assertGreaterEqual(len(calls), 2)

    def test_search_builds_body(self):
        mock_response = MagicMock()
        mock_response.status_code = 200
        mock_response.json.return_value = {"result": [{"id": 1}]}
        self.client._session.request.return_value = mock_response

        results = self.client.search([0.1, 0.2], top_k=3, filters={"lang": "de"})

        self.assertEqual(results, [{"id": 1}])
        args, kwargs = self.client._session.request.call_args
        self.assertEqual(args[0], "POST")
        self.assertIn("filter", kwargs["json"])


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
