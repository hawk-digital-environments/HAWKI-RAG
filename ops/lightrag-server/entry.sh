#!/bin/sh
set -e

# Ensure required python dependencies exist even if the base image was cached
python - <<'PY'
import importlib, subprocess, sys

def ensure(pkg, requirement):
    try:
        importlib.import_module(pkg)
    except ModuleNotFoundError:
        subprocess.check_call([sys.executable, "-m", "pip", "install", "--no-cache-dir", requirement])

ensure("httpx", "httpx>=0.27,<0.28")
ensure("fastapi", "fastapi>=0.112,<0.113")
ensure("uvicorn", "uvicorn[standard]>=0.30,<0.31")
ensure("jwt", "PyJWT>=2.9,<3")
ensure("aiofiles", "aiofiles>=24.1,<25")
ensure("multipart", "python-multipart>=0.0.9,<0.0.10")
PY

# If the packaged LightRAG distribution does not include the built WebUI assets,
# create a tiny placeholder so the server does not crash on startup.
python - <<'PY'
import importlib
import pathlib

try:
    pkg = importlib.import_module("lightrag")
    api_server = importlib.import_module("lightrag.api.lightrag_server")
except ModuleNotFoundError:
    pkg = None
    api_server = None

content = "<!doctype html><title>LightRAG UI</title><body><p>Web UI not packaged.</p></body>"

if api_server is not None:
    server_root = pathlib.Path(api_server.__file__).parent / "webui"
    try:
        server_root.mkdir(parents=True, exist_ok=True)
        index = server_root / "index.html"
        if not index.exists():
            index.write_text(content, encoding="utf-8")
    except OSError:
        pass

if pkg is not None:
    root = pathlib.Path(pkg.__file__).parent
    candidates = [
        root / "webui_static",
        root / "lightrag_webui" / "dist",
        root / "static",
    ]
    for path in candidates:
        try:
            path.mkdir(parents=True, exist_ok=True)
            index = path / "index.html"
            if not index.exists():
                index.write_text(content, encoding="utf-8")
        except OSError:
            continue
PY

python - <<'PY'
import os
import socket
import sys
import time
from urllib.parse import urlparse

uri = os.environ.get("NEO4J_URI", "bolt://neo4j:7687")
parsed = urlparse(uri)
host = parsed.hostname or "neo4j"
port = parsed.port or 7687
timeout = float(os.environ.get("NEO4J_WAIT_TIMEOUT", "120"))
deadline = time.time() + timeout
interval = float(os.environ.get("NEO4J_WAIT_INTERVAL", "3"))

while time.time() < deadline:
    try:
        with socket.create_connection((host, port), timeout=5):
            break
    except OSError as exc:
        print(f"Waiting for Neo4j at {host}:{port} ({exc})", file=sys.stderr, flush=True)
        time.sleep(interval)
else:
    sys.stderr.write(f"Neo4j is not reachable at {host}:{port} after {int(timeout)} seconds.\n")
    sys.exit(1)
PY

# Prefer the packaged console script if available
if command -v lightrag-server >/dev/null 2>&1; then
  exec lightrag-server
fi

# Fallback: try Python module entrypoint
python - <<'PY'
import importlib, sys
try:
    import lightrag
except Exception as e:
    sys.stderr.write(f"LightRAG import failed: {e}\n")
    sys.exit(1)
PY

exec python -m lightrag.server
