# syntax=docker/dockerfile:1.7

FROM ghcr.io/astral-sh/uv:0.11.26 AS uv

FROM python:3.13.14-slim AS builder

ENV UV_LINK_MODE=copy

WORKDIR /workspace/python_rag

COPY --from=uv /uv /uvx /usr/local/bin/
COPY python_rag/pyproject.toml python_rag/uv.lock ./

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

RUN uv sync \
    --frozen \
    --no-dev \
    --no-editable \
    --package hawki-scraper-worker \
    && find .venv -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find .venv -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete

FROM python:3.13.14-slim AS runtime

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

RUN groupadd --gid 10001 hawki-rag \
    && useradd --uid 10001 --gid hawki-rag --create-home --no-log-init hawki-rag

COPY --from=builder /workspace/python_rag/.venv/lib/python3.13/site-packages /usr/local/lib/python3.13/site-packages
RUN find /usr/local/lib/python3.13 -depth -type d \( -name __pycache__ -o -name .pytest_cache -o -name tests -o -name test -o -name build -o -name dist -o -name '*.egg-info' \) -exec rm -rf '{}' + \
    && find /usr/local/lib/python3.13 -type f \( -name '*.pyc' -o -name '*.pyo' -o -name 'test_*.py' -o -name '*_test.py' \) -delete

WORKDIR /app
USER hawki-rag

CMD ["python", "-m", "hawki_scraper_worker.main"]
