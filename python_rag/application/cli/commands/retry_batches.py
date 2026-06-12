"""Batch submission state for retry ingest."""

from __future__ import annotations

import logging
import sys
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

PostBatch = Callable[..., tuple[bool, dict[str, Any] | None, str | None]]


@dataclass(slots=True)
class RetryBatchSender:
    """Small state holder for retry ingest batch posting."""

    args: Any
    options: dict[str, Any]
    post_batch: PostBatch
    logger_obj: logging.Logger
    sent: int = 0
    batch_index: int = 0
    failures: int = 0

    def send(self, docs: list[dict[str, Any]]) -> bool:
        """Send one retry batch and update counters."""

        if not docs:
            return True

        self.batch_index += 1
        doc_ids_batch = [doc.get("id") for doc in docs]
        ok, _, err = self.post_batch(
            self.args.base_url,
            docs,
            self.options,
            timeout=self.args.timeout,
        )
        if ok:
            self.logger_obj.info("retry:batch sent=%s docs=%s", self.batch_index, len(docs))
            self.sent += len(docs)
            status_msg = "Planned" if self.args.dry else "Sent"
            print(f"{status_msg} {self.sent} docs (batch {self.batch_index})")
            return True

        self.failures += 1
        print(f"Batch {self.batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
        return False


__all__ = ["PostBatch", "RetryBatchSender"]
