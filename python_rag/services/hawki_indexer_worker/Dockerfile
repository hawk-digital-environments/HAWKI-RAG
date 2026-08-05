FROM ghcr.io/astral-sh/uv:0.11.26 AS uv

FROM python:3.13.11-slim AS builder

ARG TORCH_VARIANT=cpu
ENV UV_LINK_MODE=copy \
    UV_NO_PROGRESS=1
WORKDIR /workspace/python_rag
RUN apt-get update \
    && apt-get install --yes --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=uv /uv /usr/local/bin/uv
COPY python_rag/pyproject.toml python_rag/uv.lock ./
COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/packages/artifact_store/pyproject.toml python_rag/packages/artifact_store/README.md packages/artifact_store/
COPY python_rag/packages/artifact_store/src packages/artifact_store/src
COPY python_rag/packages/worker_runtime/pyproject.toml python_rag/packages/worker_runtime/README.md packages/worker_runtime/
COPY python_rag/packages/worker_runtime/src packages/worker_runtime/src
COPY python_rag/packages/resilience/pyproject.toml python_rag/packages/resilience/README.md packages/resilience/
COPY python_rag/packages/resilience/src packages/resilience/src
COPY python_rag/packages/text_processing/pyproject.toml python_rag/packages/text_processing/README.md packages/text_processing/
COPY python_rag/packages/text_processing/src packages/text_processing/src
COPY python_rag/packages/model_providers/pyproject.toml python_rag/packages/model_providers/README.md packages/model_providers/
COPY python_rag/packages/model_providers/src packages/model_providers/src
COPY python_rag/packages/stores/pyproject.toml python_rag/packages/stores/README.md packages/stores/
COPY python_rag/packages/stores/src packages/stores/src
COPY python_rag/services/hawki_indexer_worker/pyproject.toml python_rag/services/hawki_indexer_worker/README.md services/hawki_indexer_worker/
COPY python_rag/services/hawki_indexer_worker/src services/hawki_indexer_worker/src
RUN test "${TORCH_VARIANT}" = cpu -o "${TORCH_VARIANT}" = gpu \
    && uv sync --frozen --no-dev --no-editable \
        --package hawki-indexer-worker --extra "${TORCH_VARIANT}" \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' -o -name 'test_*.py' -o -name '*_test.py' \) -delete

FROM python:3.13.11-slim AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/home/rawki
WORKDIR /app
RUN apt-get update \
    && apt-get install --yes --no-install-recommends ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 rawki \
    && useradd --uid 10001 --gid rawki --create-home --shell /usr/sbin/nologin rawki \
    && mkdir -p /app/public /app/rag_storage \
    && chown -R rawki:rawki /app /home/rawki
COPY --from=builder /workspace/python_rag/.venv/lib/python3.13/site-packages /usr/local/lib/python3.13/site-packages
RUN find /usr/local/lib/python3.13 -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find /usr/local/lib/python3.13 -type f \( -name '*.pyc' -o -name '*.pyo' -o -name 'test_*.py' -o -name '*_test.py' \) -delete
USER 10001:10001
CMD ["python", "-m", "hawki_indexer_worker.main"]
