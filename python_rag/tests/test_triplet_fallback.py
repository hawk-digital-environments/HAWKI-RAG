import os
import sys
import unittest
from unittest import mock


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)


class TripletFallbackTests(unittest.TestCase):
    def test_extract_triplets_fallback(self):
        from core.rag_service import RAGService

        with mock.patch.object(RAGService, "_init_raganything", return_value=None):
            service = RAGService()
            trips = service.extract_triplets("Vincent Timm works at HAWK University.", "raganything")
        self.assertTrue(len(trips) > 0, "Expected fallback triplets when raganything is unavailable")


if __name__ == "__main__":
    unittest.main()
