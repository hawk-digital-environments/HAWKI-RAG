"""Async loop lifecycle helpers for graph extraction."""

from __future__ import annotations

import asyncio
import logging
import threading
from typing import Any

logger = logging.getLogger(__name__)


class RagAnythingGraphLoop:
    """Owns a dedicated event loop for rag-graph async operations."""

    def __init__(self, logger_obj: logging.Logger | None = None) -> None:
        self._logger = logger_obj or logger
        self._rag_graph_loop = None
        self._rag_graph_loop_thread = None
        self._rag_graph_loop_ready = threading.Event()

    def ensure_rag_graph_loop(self) -> asyncio.AbstractEventLoop:
        loop = self._rag_graph_loop
        if loop is not None and loop.is_running():
            return loop

        self._rag_graph_loop_ready.clear()

        def _runner() -> None:
            loop_obj = asyncio.new_event_loop()
            try:
                asyncio.set_event_loop(loop_obj)
                self._rag_graph_loop = loop_obj
                self._rag_graph_loop_ready.set()
                loop_obj.run_forever()
            finally:
                try:
                    pending = [t for t in asyncio.all_tasks(loop_obj) if not t.done()]
                except Exception:
                    pending = []
                for task in pending:
                    task.cancel()
                if pending:
                    try:
                        loop_obj.run_until_complete(asyncio.gather(*pending, return_exceptions=True))
                    except Exception:
                        pass
                try:
                    loop_obj.close()
                finally:
                    self._rag_graph_loop = None
                    asyncio.set_event_loop(None)

        t = threading.Thread(target=_runner, daemon=True, name="raganything-graph-loop")
        self._rag_graph_loop_thread = t
        t.start()

        if not self._rag_graph_loop_ready.wait(timeout=5):
            raise RuntimeError("RAG-Anything graph event loop did not start")
        if self._rag_graph_loop is None:
            raise RuntimeError("RAG-Anything graph event loop unavailable")

        return self._rag_graph_loop

    def run_sync(self, coro: Any) -> Any:
        loop = self.ensure_rag_graph_loop()
        future = asyncio.run_coroutine_threadsafe(coro, loop)
        return future.result()

    def close_raganything_instance(self, client: Any | None) -> None:
        if client is None:
            return
        try:
            close_fn = getattr(client, "close", None)
            if callable(close_fn):
                result = close_fn()
                if asyncio.iscoroutine(result):
                    self.run_sync(result)
        except Exception as exc:
            self._logger.debug("RAG-Anything close failed: %s", exc)
