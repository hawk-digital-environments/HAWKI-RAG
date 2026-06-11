# Python RAG API (FastAPI bridge / RAG-Anything)
FROM python:3.11-slim AS python-rag

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DEBIAN_FRONTEND=noninteractive \
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
    libgomp1 \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-deu \
    && rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements.txt /app/requirements.txt
RUN --mount=type=cache,target=/root/.cache/pip \
    python -m pip install --no-cache-dir "pip<26" \
    && PIP_DEFAULT_TIMEOUT=1200 PIP_RETRIES=25 \
       pip install --prefer-binary --retries 25 --timeout 1200 -r requirements.txt

COPY python_rag /app

RUN mkdir -p /app/rag_storage

EXPOSE 8003
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8003"]

# Local reranker
FROM python:3.11-slim AS rerank

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    libgomp1 \
    ca-certificates \
    curl && \
    rm -rf /var/lib/apt/lists/*

COPY python_rag/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir --retries 10 --timeout 180 -r requirements.txt

COPY python_rag/rerank/local_reranker/app.py /app/app.py

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
