"""Side-effect-free contracts shared by HAWKI RAG services.

Import concrete contracts from their owning modules. Keeping this package
initializer deliberately small lets deterministic Temporal workflows import
``hawki_rag_contracts.temporal`` without importing Pydantic models.
"""

__version__ = "0.1.0"

__all__ = ["__version__"]
