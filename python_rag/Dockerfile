# syntax=docker/dockerfile:1.7

ARG PYTHON_NGINX_IMAGE=neunerlei/python-nginx:3.13@sha256:05b581371d0d9faef2f160079acd0a4e18503b99d47b995825205e71fd13c136

FROM ghcr.io/astral-sh/uv:0.11.26 AS uv

FROM ${PYTHON_NGINX_IMAGE} AS python-builder-base

ENV UV_LINK_MODE=copy \
    UV_NO_PROGRESS=1 \
    UV_PYTHON=/usr/local/bin/python3.13 \
    UV_PYTHON_DOWNLOADS=never
WORKDIR /workspace/python_rag
COPY --from=uv /uv /uvx /usr/local/bin/
COPY python_rag/pyproject.toml python_rag/uv.lock ./

FROM python-builder-base AS bridge-builder

COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/packages/resilience/pyproject.toml python_rag/packages/resilience/README.md packages/resilience/
COPY python_rag/packages/resilience/src packages/resilience/src
COPY python_rag/packages/text_processing/pyproject.toml python_rag/packages/text_processing/README.md packages/text_processing/
COPY python_rag/packages/text_processing/src packages/text_processing/src
COPY python_rag/packages/model_providers/pyproject.toml python_rag/packages/model_providers/README.md packages/model_providers/
COPY python_rag/packages/model_providers/src packages/model_providers/src
COPY python_rag/packages/stores/pyproject.toml python_rag/packages/stores/README.md packages/stores/
COPY python_rag/packages/stores/src packages/stores/src
COPY python_rag/services/hawki_bridge/pyproject.toml python_rag/services/hawki_bridge/README.md services/hawki_bridge/
COPY python_rag/services/hawki_bridge/src services/hawki_bridge/src
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    uv sync --frozen --no-dev --no-editable --package hawki-bridge \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python-builder-base AS workflow-builder

COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/services/hawki_workflow_worker/pyproject.toml python_rag/services/hawki_workflow_worker/README.md services/hawki_workflow_worker/
COPY python_rag/services/hawki_workflow_worker/src services/hawki_workflow_worker/src
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    uv sync --frozen --no-dev --no-editable --package hawki-workflow-worker \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python-builder-base AS scraper-builder

COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/packages/text_processing/pyproject.toml python_rag/packages/text_processing/README.md packages/text_processing/
COPY python_rag/packages/text_processing/src packages/text_processing/src
COPY python_rag/packages/artifact_store/pyproject.toml python_rag/packages/artifact_store/README.md packages/artifact_store/
COPY python_rag/packages/artifact_store/src packages/artifact_store/src
COPY python_rag/packages/resilience/pyproject.toml python_rag/packages/resilience/README.md packages/resilience/
COPY python_rag/packages/resilience/src packages/resilience/src
COPY python_rag/packages/worker_runtime/pyproject.toml python_rag/packages/worker_runtime/README.md packages/worker_runtime/
COPY python_rag/packages/worker_runtime/src packages/worker_runtime/src
COPY python_rag/services/hawki_scraper_worker/pyproject.toml python_rag/services/hawki_scraper_worker/README.md services/hawki_scraper_worker/
COPY python_rag/services/hawki_scraper_worker/src services/hawki_scraper_worker/src
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    uv sync --frozen --no-dev --no-editable --package hawki-scraper-worker \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python-builder-base AS converter-builder

COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/packages/text_processing/pyproject.toml python_rag/packages/text_processing/README.md packages/text_processing/
COPY python_rag/packages/text_processing/src packages/text_processing/src
COPY python_rag/packages/artifact_store/pyproject.toml python_rag/packages/artifact_store/README.md packages/artifact_store/
COPY python_rag/packages/artifact_store/src packages/artifact_store/src
COPY python_rag/packages/resilience/pyproject.toml python_rag/packages/resilience/README.md packages/resilience/
COPY python_rag/packages/resilience/src packages/resilience/src
COPY python_rag/packages/worker_runtime/pyproject.toml python_rag/packages/worker_runtime/README.md packages/worker_runtime/
COPY python_rag/packages/worker_runtime/src packages/worker_runtime/src
COPY python_rag/services/hawki_converter_worker/pyproject.toml python_rag/services/hawki_converter_worker/README.md services/hawki_converter_worker/
COPY python_rag/services/hawki_converter_worker/src services/hawki_converter_worker/src
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    uv sync --frozen --no-dev --no-editable --package hawki-converter-worker \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python-builder-base AS indexer-builder

ARG TORCH_VARIANT=cpu
RUN apt-get update \
    && apt-get install --yes --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/*
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
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    test "$TORCH_VARIANT" = cpu -o "$TORCH_VARIANT" = gpu \
    && uv sync --frozen --no-dev --no-editable --package hawki-indexer-worker --extra "$TORCH_VARIANT" \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python-builder-base AS reranker-builder

ARG TORCH_VARIANT=cpu
COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md packages/contracts/
COPY python_rag/packages/contracts/src packages/contracts/src
COPY python_rag/packages/resilience/pyproject.toml python_rag/packages/resilience/README.md packages/resilience/
COPY python_rag/packages/resilience/src packages/resilience/src
COPY python_rag/services/hawki_reranker/pyproject.toml python_rag/services/hawki_reranker/README.md services/hawki_reranker/
COPY python_rag/services/hawki_reranker/src services/hawki_reranker/src
RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    test "$TORCH_VARIANT" = cpu -o "$TORCH_VARIANT" = gpu \
    && uv sync --frozen --no-dev --no-editable --package hawki-reranker --extra "$TORCH_VARIANT" \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM ${PYTHON_NGINX_IMAGE} AS runtime-base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/home/rawki
WORKDIR /var/www/html
RUN mkdir -p /home/rawki /app/public /app/rag_storage
COPY docker/python_rag/10-rawki-runtime.env /container/custom/env/10-rawki-runtime.env
COPY docker/python_rag/80-rawki-runtime-permissions.sh /container/custom/entrypoint/80-rawki-runtime-permissions.sh
RUN chmod 0644 /container/custom/env/10-rawki-runtime.env \
    && chmod 0755 /container/custom/entrypoint/80-rawki-runtime-permissions.sh

FROM runtime-base AS bridge-runtime

ENV PYTHON_APP_MODULE=hawki_bridge.main:app \
    GUNICORN_WORKER_CLASS=uvicorn.workers.UvicornWorker \
    GUNICORN_WORKERS=1
COPY --from=bridge-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/
EXPOSE 80

FROM runtime-base AS reranker-runtime

ENV PYTHON_APP_MODULE=hawki_reranker.main:app \
    GUNICORN_WORKER_CLASS=uvicorn.workers.UvicornWorker \
    GUNICORN_WORKERS=1 \
    HF_HOME=/home/rawki/.cache/huggingface \
    TORCH_HOME=/home/rawki/.cache/torch
RUN apt-get update \
    && apt-get install --yes --no-install-recommends ca-certificates libgomp1 \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p "$HF_HOME" "$TORCH_HOME"
COPY --from=reranker-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/
EXPOSE 80

FROM runtime-base AS worker-runtime-base

ENV PYTHON_WORKER_PROCESS_COUNT=1 \
    PYTHON_WORKER_STOP_WAIT_SECS=10

FROM worker-runtime-base AS workflow-runtime

ENV PYTHON_WORKER_COMMAND="python -m hawki_workflow_worker.main"
COPY --from=workflow-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/

FROM worker-runtime-base AS scraper-runtime

ENV PYTHON_WORKER_COMMAND="python -m hawki_scraper_worker.main"
COPY --from=scraper-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/

FROM worker-runtime-base AS converter-runtime

ENV PYTHON_WORKER_COMMAND="python -m hawki_converter_worker.main"
COPY --from=converter-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/

FROM worker-runtime-base AS indexer-runtime

RUN apt-get update \
    && apt-get install --yes --no-install-recommends ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*
ENV PYTHON_WORKER_COMMAND="python -m hawki_indexer_worker.main"
COPY --from=indexer-builder --chown=www-data:www-data /workspace/python_rag/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/
