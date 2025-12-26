FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates curl unzip && \
    rm -rf /var/lib/apt/lists/*

# Install LightRAG and supplemental dependencies
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt

# Install Bun and build the LightRAG WebUI during image build so the frontend is ready at runtime
RUN <<'BASH'
set -euo pipefail
curl -fsSL https://bun.sh/install | bash
export BUN_INSTALL=/root/.bun
export PATH="$BUN_INSTALL/bin:$PATH"
WEBUI_DIR=$(python - <<'PY'
import importlib.util
import pathlib
spec = importlib.util.find_spec("lightrag_webui")
if spec and spec.origin:
    print(pathlib.Path(spec.origin).parent)
PY
)
if [ -n "$WEBUI_DIR" ]; then
  echo "Building LightRAG WebUI in $WEBUI_DIR"
  cd "$WEBUI_DIR"
  bun install --frozen-lockfile
  bun run build
else
  echo "LightRAG WebUI package not found; skipping frontend build."
fi
BASH

# The server reads configuration from environment variables (.env via env_file)
EXPOSE 9621

COPY entry.sh /app/entry.sh
RUN chmod +x /app/entry.sh

CMD ["/app/entry.sh"]
