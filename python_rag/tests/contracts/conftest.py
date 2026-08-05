"""Make the uv workspace contracts package importable before root installation."""

from __future__ import annotations

from pathlib import Path
import sys


CONTRACTS_SRC = Path(__file__).resolve().parents[2] / "packages" / "contracts" / "src"
sys.path.insert(0, str(CONTRACTS_SRC))
