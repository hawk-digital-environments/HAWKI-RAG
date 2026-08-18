"""Narrow environment-backed settings for the read-only bridge."""

from __future__ import annotations

import os
from dataclasses import dataclass
from datetime import timedelta
from typing import Mapping

from hawki_rag_contracts.temporal import INGEST_SOURCE_WORKFLOW, WORKFLOW_TASK_QUEUE


def _value(env: Mapping[str, str], name: str, default: str) -> str:
    return (env.get(name, default) or default).strip()


def _boolean(env: Mapping[str, str], name: str, default: bool = False) -> bool:
    raw = str(env.get(name, "")).strip().lower()
    return default if not raw else raw in {"1", "true", "yes", "on"}


@dataclass(frozen=True, slots=True)
class BridgeSettings:
    reranker_mode: str
    reranker_mix_mode: bool
    reranker_mix_weight: float
    log_level: str
    startup_checks_enabled: bool
    startup_check_attempts: int
    startup_check_timeout_seconds: float
    startup_check_backoff_seconds: float
    temporal_address: str
    temporal_namespace: str
    workflow_type: str
    workflow_task_queue: str
    workflow_execution_timeout_seconds: int
    workflow_run_timeout_seconds: int
    workflow_task_timeout_seconds: int

    @property
    def workflow_execution_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_execution_timeout_seconds)

    @property
    def workflow_run_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_run_timeout_seconds)

    @property
    def workflow_task_timeout(self) -> timedelta:
        return timedelta(seconds=self.workflow_task_timeout_seconds)

    def cron_for_cadence(self, cadence: str) -> str:
        schedules = {
            "daily": "0 2 * * *",
            "weekly": "0 2 * * 0",
            "monthly": "0 2 1 * *",
        }
        key = cadence.strip().lower()
        if key not in schedules:
            raise ValueError(f"Unsupported Temporal refresh cadence [{cadence}].")
        return schedules[key]


def load_settings(env: Mapping[str, str] | None = None) -> BridgeSettings:
    source = env or os.environ
    return BridgeSettings(
        reranker_mode=_value(source, "RERANKER_MODE", "none"),
        reranker_mix_mode=_boolean(source, "RERANKER_MIX_MODE", True),
        reranker_mix_weight=float(_value(source, "RERANKER_MIX_WEIGHT", "0.5")),
        log_level=_value(source, "LOG_LEVEL", "INFO").upper(),
        startup_checks_enabled=_boolean(source, "STARTUP_CHECKS_ENABLED", False),
        startup_check_attempts=max(
            1, int(_value(source, "STARTUP_CHECK_ATTEMPTS", "3"))
        ),
        startup_check_timeout_seconds=max(
            0.5, float(_value(source, "STARTUP_CHECK_TIMEOUT_SECONDS", "3"))
        ),
        startup_check_backoff_seconds=max(
            0.0, float(_value(source, "STARTUP_CHECK_BACKOFF_SECONDS", "0.5"))
        ),
        temporal_address=_value(source, "TEMPORAL_ADDRESS", "temporal:7233"),
        temporal_namespace=_value(source, "TEMPORAL_NAMESPACE", "default"),
        workflow_type=_value(
            source,
            "TEMPORAL_INGEST_WORKFLOW_TYPE",
            INGEST_SOURCE_WORKFLOW,
        ),
        workflow_task_queue=_value(
            source,
            "TEMPORAL_RAG_WORKFLOW_TASK_QUEUE",
            WORKFLOW_TASK_QUEUE,
        ),
        workflow_execution_timeout_seconds=int(
            _value(source, "TEMPORAL_WORKFLOW_EXECUTION_TIMEOUT", "172800")
        ),
        workflow_run_timeout_seconds=int(
            _value(source, "TEMPORAL_WORKFLOW_RUN_TIMEOUT", "86400")
        ),
        workflow_task_timeout_seconds=int(
            _value(source, "TEMPORAL_WORKFLOW_TASK_TIMEOUT", "30")
        ),
    )


__all__ = ["BridgeSettings", "load_settings"]
