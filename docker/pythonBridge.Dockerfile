FROM neunerlei/python-nginx:3.14 AS python-rag

LABEL org.opencontainers.image.authors="HAWKI Team <ki@hawk.de>"
LABEL org.opencontainers.image.description="The HAWKI RAG python bridge service"

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DEBIAN_FRONTEND=noninteractive \
    RAG_WORKING_DIR=/app/rag_storage \
    OLLAMA_URL=http://ollama:11434 \
    QDRANT_URL=http://qdrant:6333 \
    NEO4J_URI=bolt://neo4j:7687

# Configure the base image to run the FastAPI app with Gunicorn and Uvicorn workers
# See: https://github.com/Neunerlei/docker-images/blob/main/docs/python-nginx.md#configuration-via-environment-variables
ENV PYTHON_APP_MODULE="app.main:app"
ENV GUNICORN_WORKER_CLASS="uvicorn.workers.UvicornWorker"

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

COPY ../backend/requirements.txt .
RUN python -m pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir --retries 10 --timeout 180 -r requirements.txt

COPY ../backend .

RUN mkdir -p /app/rag_storage
