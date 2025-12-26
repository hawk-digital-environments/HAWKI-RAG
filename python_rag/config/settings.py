import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    rag_working_dir: Path
    ollama_url: str
    qdrant_url: str
    neo4j_uri: str


def load_settings() -> Settings:
    return Settings(
        rag_working_dir=Path(os.environ.get("RAG_WORKING_DIR", "/app/rag_storage")).expanduser(),
        ollama_url=os.environ.get("OLLAMA_URL", "http://ollama:11434"),
        qdrant_url=os.environ.get("QDRANT_URL", "http://qdrant:6333"),
        neo4j_uri=os.environ.get("NEO4J_URI", "bolt://neo4j:7687"),
    )
