# Python RAG API (FastAPI bridge / RAG-Anything)
FROM python:3.11-slim AS python-rag

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DEBIAN_FRONTEND=noninteractive \
    HOME=/home/rawki \
    RAG_WORKING_DIR=/app/rag_storage \
    OLLAMA_URL=http://ollama:11434 \
    QDRANT_URL=http://qdrant:6333 \
    NEO4J_URI=bolt://neo4j:7687

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    ca-certificates \
    curl \
    git \
    imagemagick \
    libreoffice \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-deu \
    && rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements.lock.txt /app/
COPY python_rag/scripts/build_mineru_transformers5_wheel.py /tmp/
RUN --mount=type=cache,target=/root/.cache/pip \
    python -m pip install --no-cache-dir "pip==26.1.2" "setuptools==83.0.0" \
    && mkdir -p /tmp/mineru-upstream /tmp/mineru-patched \
    && pip download --no-deps --dest /tmp/mineru-upstream "mineru==3.4.4" \
    && python /tmp/build_mineru_transformers5_wheel.py \
       /tmp/mineru-upstream/mineru-3.4.4-py3-none-any.whl /tmp/mineru-patched \
    && PIP_DEFAULT_TIMEOUT=1200 PIP_RETRIES=25 \
       pip install --find-links=/tmp/mineru-patched \
       --prefer-binary --retries 25 --timeout 1200 -r requirements.lock.txt \
    && rm -rf /tmp/mineru-upstream /tmp/mineru-patched

COPY python_rag /app

COPY docker/python-rag/shared-storage-entrypoint.sh /usr/local/bin/hawki-shared-storage-entrypoint

RUN groupadd --gid 10001 rawki \
    && useradd --uid 10001 --gid rawki --create-home --shell /usr/sbin/nologin rawki \
    && mkdir -p /app/rag_storage /shared \
    && chown -R rawki:rawki /app /home/rawki \
    && chmod 0755 /usr/local/bin/hawki-shared-storage-entrypoint

EXPOSE 8003
USER rawki:rawki
ENTRYPOINT ["/usr/local/bin/hawki-shared-storage-entrypoint"]
CMD ["uvicorn", "api.main:app", "--host", "0.0.0.0", "--port", "8003"]

# Local reranker
FROM python:3.11-slim AS rerank

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/home/rawki \
    HF_HOME=/home/rawki/.cache/huggingface \
    TORCH_HOME=/home/rawki/.cache/torch

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    ca-certificates \
    curl && \
    rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements-rerank.txt /app/
RUN python -m pip install --no-cache-dir "pip==26.1.2" "setuptools==83.0.0" \
    && pip install --no-cache-dir --retries 10 --timeout 300 -r requirements-rerank.txt

COPY python_rag /app

RUN groupadd --gid 10001 rawki \
    && useradd --uid 10001 --gid rawki --create-home --shell /usr/sbin/nologin rawki \
    && mkdir -p "$HF_HOME" "$TORCH_HOME" \
    && chown -R rawki:rawki /app /home/rawki

EXPOSE 8000
USER rawki:rawki
CMD ["uvicorn", "infrastructure.rerank.local_reranker.app:app", "--host", "0.0.0.0", "--port", "8000"]
