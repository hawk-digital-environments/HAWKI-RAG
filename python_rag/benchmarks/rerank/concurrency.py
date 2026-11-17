"""Bounded parallel execution of synchronous SDK requests."""

from collections.abc import Callable, Iterable, Iterator
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from dataclasses import dataclass
from itertools import islice
from threading import Lock
from time import perf_counter
from typing import Any


@dataclass
class RequestStats:
    active: int = 0
    peak: int = 0
    completed: int = 0
    failures: int = 0


@dataclass
class Outcome:
    response: Any
    latency_seconds: float
    error: dict | None = None


def run_requests(
    jobs: Iterable[tuple[int, dict]],
    request: Callable[[dict], Any],
    *,
    concurrency: int,
    stats: RequestStats,
    clock=perf_counter,
    progress: Callable[[RequestStats], None] | None = None,
    worker_requests: int | None = None,
) -> Iterator[tuple[tuple[int, dict], Outcome]]:
    """Submit at most concurrency jobs; perform result evaluation in the caller."""
    lock = Lock()

    def invoke(job: tuple[int, dict]) -> Outcome:
        with lock:
            stats.active += 1
            stats.peak = max(stats.peak, stats.active)
        started = clock()
        error = None
        response = None
        try:
            response = request(job[1])
        except Exception as exc:
            # SDK error strings can contain secrets. Preserve only type/status.
            status = getattr(exc, "status_code", None)
            error = {
                "type": type(exc).__name__,
                "status_code": status if type(status) is int else None,
            }
        finally:
            elapsed = clock() - started
            with lock:
                stats.active -= 1
                stats.completed += 1
                stats.failures += int(error is not None)
        return Outcome(response, elapsed, error)

    if concurrency == 1:
        for job in jobs:
            yield job, invoke(job)
        return

    jobs = iter(jobs)
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        pending = {}

        def run_batch(batch):
            return [(job, invoke(job)) for job in batch]

        def submit_next() -> None:
            batch = list(islice(jobs, worker_requests or 1))
            if batch:
                pending[pool.submit(run_batch, batch)] = batch

        for _ in range(concurrency):
            submit_next()
        last_update = perf_counter()
        while pending:
            done, _ = wait(pending, timeout=1, return_when=FIRST_COMPLETED)
            if progress and perf_counter() - last_update >= 1:
                with lock:
                    snapshot = RequestStats(
                        stats.active, stats.peak, stats.completed, stats.failures
                    )
                progress(snapshot)
                last_update = perf_counter()
            for future in done:
                pending.pop(future)
                outcomes = future.result()
                if worker_requests is None:
                    submit_next()
                yield from outcomes
