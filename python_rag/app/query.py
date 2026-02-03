"""
Query entrypoints routed to pipeline.query_logic and graph graph_from_text.
"""
from pipeline.query_logic import query_documents
from graph.graph_text import graph_from_text

__all__ = ["query_documents", "graph_from_text"]
