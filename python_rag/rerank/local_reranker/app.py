from typing import List, Dict, Any
from fastapi import FastAPI, HTTPException  # type: ignore[reportMissingImports]
from pydantic import BaseModel, Field  # type: ignore[reportMissingImports]
from sentence_transformers import CrossEncoder  # type: ignore[reportMissingImports]

app = FastAPI(title="Local Reranker", version="0.1.0")
# Load the Mixedbread reranker once at startup so every request reuses the weights.
model = CrossEncoder("mixedbread-ai/mxbai-rerank-base-v1")

class RerankRequest(BaseModel):
    # Cohere-compatible fields
    query: str
    documents: List[str]
    top_n: int | None = Field(default=None)
    model: str | None = Field(default=None)

@app.get("/health")
def health():
    return {"ok": True}

@app.post("/v1/rerank")
def rerank(req: RerankRequest) -> Dict[str, Any]:
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
