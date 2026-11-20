"""Side-effect-free contracts shared by HAWKI RAG services.

Canonical contracts live below ``pipeline`` or ``retrieval``. Keeping this
initializer deliberately small lets deterministic Temporal workflows import
``hawki_rag_contracts.pipeline.temporal`` without importing Pydantic models.
Top-level contract modules remain compatibility aliases for existing clients.
"""

__version__ = "0.1.0"

__all__ = ["__version__"]
