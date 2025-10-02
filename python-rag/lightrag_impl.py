from typing import List, Tuple, Any
import logging

HAS_LIGHTRAG = False

try:
    import lightrag  # type: ignore
    HAS_LIGHTRAG = True
except Exception:
    HAS_LIGHTRAG = False


def extract_triplets_fallback(text: str) -> List[Tuple[str, str, str]]:
    import re
    tokens = re.findall(r"\b[A-Z][a-zA-Z0-9_-]+\b", text)
    triplets: List[Tuple[str, str, str]] = []
    for i in range(len(tokens) - 1):
        s, o = tokens[i], tokens[i + 1]
        triplets.append((s, "RELATED_TO", o))
    return triplets


def _normalize_triplets(obj: Any) -> List[Tuple[str, str, str]]:
    out: List[Tuple[str, str, str]] = []
    if obj is None:
        return out
    # Common shapes: [(s,r,o), ...] or [{"subject":, "relation":, "object":}, ...]
    if isinstance(obj, list):
        for item in obj:
            if isinstance(item, (list, tuple)) and len(item) >= 3:
                s, r, o = item[0], item[1], item[2]
                if s and r and o:
                    out.append((str(s), str(r), str(o)))
            elif isinstance(item, dict):
                s = item.get("subject") or item.get("s") or item.get("head")
                r = item.get("relation") or item.get("r") or item.get("type")
                o = item.get("object") or item.get("o") or item.get("tail")
                if s and r and o:
                    out.append((str(s), str(r), str(o)))
    return out


def extract_triplets_with_lightrag(text: str) -> List[Tuple[str, str, str]]:
    if not HAS_LIGHTRAG:
        return extract_triplets_fallback(text)

    # Try several likely APIs to remain compatible across versions
    try:
        # Pattern A: top-level function
        if hasattr(lightrag, "extract_triplets") and callable(getattr(lightrag, "extract_triplets")):
            res = lightrag.extract_triplets(text)  # type: ignore[attr-defined]
            trips = _normalize_triplets(res)
            if trips:
                return trips
    except Exception as e:
        logging.info("LightRAG extract_triplets() failed: %s", e)

    try:
        # Pattern B: class-based API
        if hasattr(lightrag, "LightRAG"):
            LR = getattr(lightrag, "LightRAG")
            try:
                rag = LR()  # type: ignore[call-arg]
            except TypeError:
                # maybe requires from_env()
                if hasattr(LR, "from_env"):
                    rag = LR.from_env()  # type: ignore[attr-defined]
                else:
                    rag = LR()  # best effort

            # Probe common method names
            for m in ("extract_triplets", "build_knowledge_graph", "kg_extract", "extract_kg"):
                if hasattr(rag, m):
                    try:
                        res = getattr(rag, m)(text)
                        trips = _normalize_triplets(res)
                        if trips:
                            return trips
                    except Exception as e:
                        logging.info("LightRAG %s() failed: %s", m, e)
    except Exception as e:
        logging.info("LightRAG class API failed: %s", e)

    # Fallback if nothing worked
    return extract_triplets_fallback(text)
