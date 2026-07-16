"""Provider-boundary scenarios for graph models and dependency-injected RAG services."""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import install_optional_dependency_stubs

install_optional_dependency_stubs()



class GraphProviderCharacterizationTests(unittest.TestCase):
    """Show how an explicit graph model is isolated from the main provider configuration."""
    def test_graph_provider_helper_clones_and_applies_explicit_graph_model(self) -> None:
        from infrastructure.raganything.provider_config import clone_provider_for_graph, provider_fingerprint

        class Provider:
            def __init__(self) -> None:
                self.base = "http://ollama"
                self.key = "secret-key-material"
                self.rag_model = "default-model"
                self.embed_model = "bge-m3"
                self._explicit_graph_model = ""

        provider = Provider()
        provider._explicit_graph_model = "graph-model"

        clone = clone_provider_for_graph(provider)

        self.assertIsNot(clone, provider)
        self.assertEqual(clone.rag_model, "graph-model")
        self.assertEqual(provider.rag_model, "default-model")
        self.assertIn("secret-k", provider_fingerprint(provider))
        self.assertNotIn("secret-key-material", provider_fingerprint(provider))


class RAGServiceDependencyCharacterizationTests(unittest.TestCase):
    """Show how the RAG service receives provider, vector, and graph boundaries."""
    def test_rag_service_uses_injected_dependency_boundaries(self) -> None:
        from application.service import RAGService
        from application.service_dependencies import RAGServiceDependencies
        from domain.settings import RAGServiceSettings

        with tempfile.TemporaryDirectory() as tmp:
            settings = RAGServiceSettings(
                rag_working_dir=Path(tmp),
                graph_debug=False,
                graph_debug_llm=False,
                graph_perf_log=True,
                graph_provider="toy-provider",
            )
            calls: dict[str, list[object]] = {
                "providers": [],
                "graph_dirs": [],
                "extract": [],
                "rerank": [],
            }

            class FakeGraphService:
                def __init__(self) -> None:
                    self.client = {"client": "fake-raganything"}

                def clear_graph_cache(self) -> dict[str, object]:
                    return {"ok": True, "cleared": "fake"}

                def graph_runtime_summary(self) -> dict[str, object]:
                    return {"graph_client_initialized": True}

                def triplets_from_llm_cache(self) -> list[tuple[str, str, str]]:
                    return [("Cache", "mentions", "Toy")]

            fake_graph_service = FakeGraphService()

            def settings_loader() -> RAGServiceSettings:
                return settings

            def provider_factory(name: str) -> dict[str, str]:
                calls["providers"].append(name)
                return {"provider": name}

            def graph_service_factory(working_dir: Path, logger_obj: object) -> FakeGraphService:
                calls["graph_dirs"].append(working_dir)
                return fake_graph_service

            def triplet_extractor(graph_service: object, text: str, engine: str | None, **kwargs: object) -> list[tuple[str, str, str]]:
                calls["extract"].append(
                    {
                        "graph_service": graph_service,
                        "text": text,
                        "engine": engine,
                        "provider": kwargs["provider"],
                        "doc_id": kwargs["doc_id"],
                        "graph_perf_log": kwargs["graph_perf_log"],
                    }
                )
                return [("Toy", "has", "Wheels")]

            def reranker(**kwargs: object) -> list[dict[str, object]]:
                calls["rerank"].append(kwargs)
                return [{"id": "ranked"}]

            service = RAGService(
                dependencies=RAGServiceDependencies(
                    settings_loader=settings_loader,
                    provider_factory=provider_factory,
                    graph_service_factory=graph_service_factory,
                    triplet_extractor=triplet_extractor,
                    reranker=reranker,
                )
            )

            self.assertEqual(service.get_provider("manual-provider"), {"provider": "manual-provider"})
            self.assertEqual(
                service.extract_triplets("toy text", None, doc_id="toy-doc"),
                [("Toy", "has", "Wheels")],
            )
            self.assertEqual(service.raganything, fake_graph_service.client)
            self.assertEqual(service.graph_runtime_summary(), {"graph_client_initialized": True})
            self.assertEqual(service.clear_graph_cache(), {"ok": True, "cleared": "fake"})
            self.assertEqual(service._triplets_from_raganything_llm_cache(), [("Cache", "mentions", "Toy")])
            self.assertEqual(
                service.rerank_hits(
                    hits=[{"id": "raw"}],
                    user_query="toy",
                    provider={"provider": "toy-provider"},
                    query_vector=None,
                    mode="cosine",
                    top_n=1,
                    mix_mode=False,
                    mix_weight=0.5,
                ),
                [{"id": "ranked"}],
            )

            self.assertEqual(calls["providers"], ["manual-provider", "toy-provider"])
            self.assertEqual(calls["graph_dirs"], [Path(tmp).expanduser()])
            self.assertEqual(calls["extract"][0]["provider"], {"provider": "toy-provider"})
            self.assertEqual(calls["extract"][0]["doc_id"], "toy-doc")
            self.assertTrue(calls["extract"][0]["graph_perf_log"])
            self.assertEqual(calls["rerank"][0]["top_n"], 1)
