"""Cached-model CPU smoke test; opt in with RAWKI_LOCAL_RERANKER_SMOKE=1.

Run from python_rag with:
    RAWKI_LOCAL_RERANKER_SMOKE=1 .venv/bin/python -m pytest -s \
        services/hawki_bridge/tests/integration/test_local_reranker_smoke.py

Download mixedbread-ai/mxbai-rerank-base-v1 separately before running.
"""

from collections.abc import Callable
from math import isfinite
import os
from time import perf_counter

import pytest


pytestmark = [
    pytest.mark.integration,
    pytest.mark.model,
    pytest.mark.skipif(
        os.environ.get("RAWKI_LOCAL_RERANKER_SMOKE") != "1",
        reason="set RAWKI_LOCAL_RERANKER_SMOKE=1 to use the cached CPU model",
    ),
]


def test_cached_cross_encoder_reranks_on_cpu_and_reuses_model(
    monkeypatch: pytest.MonkeyPatch,
    record_property: Callable[[str, object], None],
) -> None:
    # Set these before importing model libraries; this test never downloads weights.
    monkeypatch.setenv("HF_HUB_OFFLINE", "1")
    monkeypatch.setenv("TRANSFORMERS_OFFLINE", "1")
    monkeypatch.setenv("LITELLM_LOCAL_MODEL_COST_MAP", "True")

    from litellm.types.rerank import RerankResponse
    from sentence_transformers import CrossEncoder

    from hawki_bridge.adapters.local_reranker import LocalReranker

    models: list[CrossEncoder] = []

    def load_model(name: str) -> CrossEncoder:
        started = perf_counter()
        model = CrossEncoder(name, device="cpu", local_files_only=True)
        record_property("model_initialization_seconds", perf_counter() - started)
        assert str(model.device) == "cpu"
        models.append(model)
        return model

    adapter = LocalReranker(
        "mixedbread-ai/mxbai-rerank-base-v1", model_factory=load_model
    )
    documents = [
        "Dataset deletion removes owned Qdrant points.",
        "The weather in Germany is sunny.",
        "Neo4j graph contributions are cleaned up during dataset deletion.",
    ]
    original = documents.copy()

    for run in (1, 2):
        started = perf_counter()
        response = adapter.rerank(
            "How does HAWKI-RAG delete datasets?", documents, top_n=3
        )
        # First call includes initialization; second call measures warm reranking.
        elapsed = perf_counter() - started
        record_property(f"rerank_{run}_seconds", elapsed)
        assert isinstance(response, RerankResponse)
        results = response.results
        assert results is not None
        assert sorted(item["index"] for item in results) == [0, 1, 2]
        scores = [item["relevance_score"] for item in results]
        assert all(isfinite(score) for score in scores)
        assert scores == sorted(scores, reverse=True)
        assert results[0]["index"] != 1, "unrelated weather document ranked first"
        assert documents == original
        assert len(models) == 1, "CrossEncoder must be loaded once across both calls"
        print(f"Run {run}: {elapsed:.3f}s; results={results}")
