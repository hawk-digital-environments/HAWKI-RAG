import os
import sys
import unittest
from unittest import mock


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)


class TripletFallbackTests(unittest.TestCase):
    def test_extract_triplets_raganything_logic(self):
        from core import rag_service as rag_mod
        from core.rag_service import RAGService

        async def fake_extract_entities(chunks, global_config, **_):
            return [({}, {("Alice", "HAWK"): [{"keywords": "works_at"}]})]

        class DummyProvider:
            def chat(self, system, messages):
                return "entity<|#|>Alice<|#|>Person<|#|>Student<|#|>...\nrelation<|#|>Alice<|#|>HAWK<|#|>works_at<|#|>...<|COMPLETE|>"

        with mock.patch.object(rag_mod, "extract_entities", fake_extract_entities):
            service = RAGService()
            trips = service.extract_triplets(
                "Alice works at HAWK University.",
                "raganything",
                provider=DummyProvider(),
            )
        self.assertEqual(trips, [("Alice", "works_at", "HAWK")])


if __name__ == "__main__":
    unittest.main()
