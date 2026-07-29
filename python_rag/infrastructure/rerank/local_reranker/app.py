from __future__ import annotations

from typing import Any

from common.optional_imports import import_required_module


def _load_symbol(module_name: str, symbol_name: str) -> Any:
    module = import_required_module(
        module_name,
        install_hint="Install python_rag/requirements.txt to run the local reranker service.",
    )
    return getattr(module, symbol_name)


FastAPI = _load_symbol("fastapi", "FastAPI")
HTTPException = _load_symbol("fastapi", "HTTPException")
BaseModel = _load_symbol("pydantic", "BaseModel")
Field = _load_symbol("pydantic", "Field")
CrossEncoder = _load_symbol("sentence_transformers", "CrossEncoder")

app = FastAPI(title="Local Reranker", version="0.1.0")
# Load the Mixedbread reranker once at startup so every request reuses the weights.
model = CrossEncoder("mixedbread-ai/mxbai-rerank-base-v1")


class RerankRequest(BaseModel):  # type: ignore[misc, valid-type]
    # Cohere-compatible fields
    query: str
    documents: list[str]
    top_n: int | None = Field(default=None)
    model: str | None = Field(default=None)

@app.get("/health")
def health():
    return {"ok": True}

@app.post("/v1/rerank")
def rerank(req: RerankRequest) -> dict[str, Any]:
    q = (req.query or "").strip()
    docs = [d if isinstance(d, str) else str(d) for d in (req.documents or [])]
    if not q or not docs:
        raise HTTPException(status_code=400, detail="query and documents are required")
    pairs = [[q, d] for d in docs]
    scores = model.predict(pairs)
    indexed = list(enumerate(scores))
    indexed.sort(key=lambda x: x[1], reverse=True)
    k = req.top_n if isinstance(req.top_n, int) and req.top_n > 0 else len(indexed)
    top = indexed[:k]
    results = [
        {
            "index": i,
            "document": docs[i],
            "relevance_score": float(s),
        } for i, s in top
    ]
    return {"results": results}
