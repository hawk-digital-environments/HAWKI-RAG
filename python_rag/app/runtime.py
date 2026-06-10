"""Runtime diagnostics for the RAG API."""
from __future__ import annotations

import shutil
import subprocess
from pathlib import Path
from typing import Any


def log_gpu_status(
    logger: Any,
    context: str,
    *,
    cuda_visible_devices: str,
    nvidia_visible_devices: str,
) -> None:
    """Log GPU visibility state with explicitly injected runtime settings."""
    has_dev = any(Path(p).exists() for p in ("/dev/nvidia0", "/dev/nvidiactl", "/dev/nvidia-uvm"))
    logger.info(
        "gpu:%s env CUDA_VISIBLE_DEVICES=%s NVIDIA_VISIBLE_DEVICES=%s dev_nodes=%s",
        context,
        cuda_visible_devices,
        nvidia_visible_devices,
        "present" if has_dev else "missing",
    )

    smi = shutil.which("nvidia-smi")
    if not smi:
        logger.info("gpu:%s nvidia-smi not found", context)
        return
    try:
        result = subprocess.run(
            [
                smi,
                "--query-gpu=index,name,memory.total,memory.free,utilization.gpu",
                "--format=csv,noheader,nounits",
            ],
            capture_output=True,
            text=True,
            timeout=2,
        )
    except Exception as exc:
        logger.info("gpu:%s nvidia-smi failed: %s", context, exc)
        return
    if result.returncode != 0:
        err = result.stderr.strip() or "unknown error"
        logger.info("gpu:%s nvidia-smi error rc=%s err=%s", context, result.returncode, err)
        return
    for line in result.stdout.splitlines():
        line = line.strip()
        if line:
            logger.info("gpu:%s nvidia-smi %s", context, line)
