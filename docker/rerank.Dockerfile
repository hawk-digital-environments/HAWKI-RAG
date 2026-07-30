FROM python:3.11-slim

ARG PYTHON_REQUIREMENTS_FILE=python_rag/requirements-rerank.cpu.lock.txt
ARG PYTORCH_WHEEL_INDEX=https://download.pytorch.org/whl/cpu

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

COPY ${PYTHON_REQUIREMENTS_FILE} /app/requirements.lock.txt

RUN --mount=type=cache,target=/home/rawki/.cache/pip \
    python -m pip install --no-cache-dir "pip==26.1.2" "setuptools==83.0.0" \
    && pip install \
       --extra-index-url="${PYTORCH_WHEEL_INDEX}" \
       --retries 10 --timeout 300 -r requirements.lock.txt

COPY python_rag /app

RUN groupadd --gid 10001 rawki \
    && useradd --uid 10001 --gid rawki --create-home --shell /usr/sbin/nologin rawki \
    && mkdir -p "$HF_HOME" "$TORCH_HOME" \
    && chown -R rawki:rawki /app /home/rawki

EXPOSE 8000
USER rawki:rawki
CMD ["uvicorn", "infrastructure.rerank.local_reranker.app:app", "--host", "0.0.0.0", "--port", "8000"]
