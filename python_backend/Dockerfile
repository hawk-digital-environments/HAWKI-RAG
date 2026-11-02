# syntax=docker/dockerfile:1.7

# The public query backend image. It follows the repository's Python service
# convention: the shared Python/nginx runtime image serves the application
# through nginx in front of gunicorn with uvicorn workers, as www-data.
#
# The image deliberately contains only the runtime dependency graph. Tests,
# lint tools and local virtual environments are excluded by .dockerignore and
# by `uv sync --no-dev`.

ARG PYTHON_NGINX_IMAGE=neunerlei/python-nginx:3.13@sha256:05b581371d0d9faef2f160079acd0a4e18503b99d47b995825205e71fd13c136

FROM ghcr.io/astral-sh/uv:0.11.26 AS uv

FROM ${PYTHON_NGINX_IMAGE} AS builder

ENV UV_LINK_MODE=copy \
    UV_NO_PROGRESS=1 \
    UV_PYTHON=/usr/local/bin/python3.13 \
    UV_PYTHON_DOWNLOADS=never

WORKDIR /workspace/python_backend
COPY --from=uv /uv /uvx /usr/local/bin/
COPY python_backend/pyproject.toml python_backend/uv.lock ./

# The backend depends on the shared retrieval package by path, and the
# retrieval package depends on the rest of the local packages transitively.
# Copy exactly that closure so the frozen lock can be installed offline from
# the local sources.
COPY python_rag/packages/contracts/pyproject.toml python_rag/packages/contracts/README.md ../python_rag/packages/contracts/
COPY python_rag/packages/contracts/src ../python_rag/packages/contracts/src
COPY python_rag/packages/observability/pyproject.toml python_rag/packages/observability/README.md ../python_rag/packages/observability/
COPY python_rag/packages/observability/src ../python_rag/packages/observability/src
COPY python_rag/packages/text_processing/pyproject.toml python_rag/packages/text_processing/README.md ../python_rag/packages/text_processing/
COPY python_rag/packages/text_processing/src ../python_rag/packages/text_processing/src
COPY python_rag/packages/model_providers/pyproject.toml python_rag/packages/model_providers/README.md ../python_rag/packages/model_providers/
COPY python_rag/packages/model_providers/src ../python_rag/packages/model_providers/src
COPY python_rag/packages/graph_store/pyproject.toml python_rag/packages/graph_store/README.md ../python_rag/packages/graph_store/
COPY python_rag/packages/graph_store/src ../python_rag/packages/graph_store/src
COPY python_rag/packages/vector_store/pyproject.toml python_rag/packages/vector_store/README.md ../python_rag/packages/vector_store/
COPY python_rag/packages/vector_store/src ../python_rag/packages/vector_store/src
COPY python_rag/packages/retrieval/pyproject.toml python_rag/packages/retrieval/README.md ../python_rag/packages/retrieval/
COPY python_rag/packages/retrieval/src ../python_rag/packages/retrieval/src
COPY python_backend/src ./src

RUN --mount=type=cache,id=rawki-uv,target=/root/.cache/uv,sharing=locked \
    uv sync --frozen --no-dev --no-editable \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM ${PYTHON_NGINX_IMAGE} AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    HOME=/home/rawki

WORKDIR /var/www/html
RUN mkdir -p /home/rawki \
    && chown www-data:www-data /home/rawki

COPY --from=builder --chown=www-data:www-data /workspace/python_backend/.venv/lib/python3.13/site-packages/ /opt/venv/lib/python3.13/site-packages/

# The runtime image serves the ASGI application with uvicorn workers behind
# gunicorn, on the same internal port as the other Python services.
ENV PYTHON_APP_MODULE=hawki_backend.main:app \
    GUNICORN_WORKER_CLASS=uvicorn.workers.UvicornWorker \
    GUNICORN_WORKERS=1

EXPOSE 80
