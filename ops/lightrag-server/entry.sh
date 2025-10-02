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
