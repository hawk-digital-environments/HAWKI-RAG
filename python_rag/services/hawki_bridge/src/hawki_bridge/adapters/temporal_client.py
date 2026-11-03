"""Import compatibility for existing adapter tests; HTTP control is retired."""

from hawki_workflow_control.temporal_client import (
    TemporalControlClient as TemporalBridgeClient,
    TemporalExecution,
)

__all__ = ["TemporalBridgeClient", "TemporalExecution"]
