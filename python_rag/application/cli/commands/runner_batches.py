"""Batch submission state for crawl-ingest runner."""

from __future__ import annotations

import sys
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from application.cli.commands.resume import should_split_batch
from application.cli.commands.runner_resume import save_resume_progress

PostBatch = Callable[..., tuple[bool, dict[str, Any] | None, str | None]]


@dataclass(slots=True)
class BatchSender:
    """Stateful batch sender with retry-by-splitting behavior."""

    args: Any
    options: dict[str, Any]
    resume_state_path: Path | None
    resume_metadata: dict[str, Any]
    processed_doc_ids: set[str]
    post_batch: PostBatch
    min_split_batch: int
    max_split_depth: int
    sent: int = 0
    batch_index: int = 0
    last_response: dict[str, Any] | None = None
    failed_batches: int = 0

    def send(self, docs_batch: list[dict[str, Any]], *, total: int, depth: int = 0) -> bool:
        """Send one batch, recursively splitting retryable failures."""

        if not docs_batch:
            return True

        self.batch_index += 1
        doc_ids_batch = [doc.get("id") for doc in docs_batch]
        ok, data, err = self.post_batch(
            base_url=self.args.base_url,
            docs=docs_batch,
            options=self.options,
            timeout=self.args.timeout,
        )
        if ok:
            self.sent += len(docs_batch)
            if self.args.dry:
                print(f"Planned {self.sent}/{total} docs… (batch {self.batch_index})")
            else:
                print(f"Sent {self.sent}/{total} docs… (batch {self.batch_index})")
            if data:
                self.last_response = data
            if not self.args.dry and self.resume_state_path is not None:
                self.processed_doc_ids.update(str(doc.get("id")) for doc in docs_batch if doc.get("id"))
                save_resume_progress(self.resume_state_path, self.processed_doc_ids, self.resume_metadata)
            return True

        if should_split_batch(err) and len(docs_batch) > max(1, self.min_split_batch) and depth < self.max_split_depth:
            mid = max(1, len(docs_batch) // 2)
            left = docs_batch[:mid]
            right = docs_batch[mid:]
            print(
                f"Batch {self.batch_index} failed; splitting {len(docs_batch)} into {len(left)} + {len(right)} due to timeout/5xx.",
                file=sys.stderr,
            )
            left_ok = self.send(left, total=total, depth=depth + 1)
            right_ok = self.send(right, total=total, depth=depth + 1)
            return left_ok and right_ok

        print(f"Batch {self.batch_index} failed; docs={doc_ids_batch} ({err or 'see log'})", file=sys.stderr)
        self.failed_batches += 1
        return False


__all__ = ["BatchSender", "PostBatch"]
