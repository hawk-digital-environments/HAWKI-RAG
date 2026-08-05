"""Async loop lifecycle helpers for graph extraction."""

from __future__ import annotations

import asyncio
import inspect
import logging
import threading
from typing import Any

logger = logging.getLogger(__name__)


class RagAnythingGraphLoop:
    """Owns a dedicated event loop for rag-graph async operations."""

    def __init__(self, logger_obj: logging.Logger | None = None) -> None:
        self._logger = logger_obj or logger
        self._rag_graph_loop = None

    def ensure_rag_graph_loop(self) -> asyncio.AbstractEventLoop:
        loop = self._rag_graph_loop
        if loop is not None and not loop.is_closed():
            return loop

        loop = asyncio.new_event_loop()
        self._rag_graph_loop = loop
        return loop

    def run_sync(self, coro: Any) -> Any:
        loop = self.ensure_rag_graph_loop()
        try:
            asyncio.get_running_loop()
        except RuntimeError:
            return loop.run_until_complete(coro)

        result: dict[str, Any] = {}
        finished = threading.Event()

        def _runner() -> None:
            try:
                result["value"] = loop.run_until_complete(coro)
            except BaseException as exc:
                result["error"] = exc
            finally:
                finished.set()

        thread = threading.Thread(
            target=_runner, daemon=True, name="raganything-graph-loop"
        )
        thread.start()
        if not finished.wait(timeout=300):
            raise TimeoutError("RAG-Anything graph coroutine did not finish")
        if "error" in result:
            raise result["error"]
        return result.get("value")

    def close_raganything_instance(self, client: object | None) -> None:
        if client is None:
            return
        try:
            finalize_fn = getattr(client, "finalize_storages", None)
            if callable(finalize_fn):
                result = finalize_fn()
                if inspect.isawaitable(result):
                    self.run_sync(result)
                return

            close_fn = getattr(client, "close", None)
            if callable(close_fn):
                result = close_fn()
                if inspect.isawaitable(result):
                    self.run_sync(result)
        except Exception as exc:
            self._logger.debug("RAG-Anything close failed: %s", exc)
