"""HTTP helpers for crawled ingest posting."""
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any, Dict, Optional

import requests


def post_batch(base_url: str, docs: list[Dict[str, Any]], options: Dict[str, Any], timeout: int) -> tuple[bool, Optional[Dict[str, Any]], Optional[str]]:
    url = base_url.rstrip("/") + "/ingest"
    body = {"docs": docs}
    body.update(options)
    try:
        resp = requests.post(url, json=body, timeout=timeout)
        if resp.ok:
            try:
                data = resp.json()
            except ValueError:
                data = None
            return True, data, None
        err = f"HTTP {resp.status_code} {resp.text[:300]}"
        sys.stderr.write(f"Ingest failed: {err}\n")
        return False, None, err
    except Exception as e:
        err = f"Exception: {e}"
        sys.stderr.write(f"Ingest error: {err}\n")
        return False, None, err


def write_summary_file(summary_file: Optional[str], summary: Dict[str, Any]) -> None:
    if not summary_file:
        return
    out_path = Path(summary_file).expanduser().resolve()
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Saved summary to {out_path}")
