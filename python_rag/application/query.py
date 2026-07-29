"""
Query entrypoints routed to pipeline.query_logic and graph graph_from_text.
"""
import logging
from application.workflows.query_logic import query_documents
from infrastructure.graph.graph_text import graph_from_text

logger = logging.getLogger(__name__)

__all__ = ["query_documents", "graph_from_text"]
