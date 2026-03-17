FROM neunerlei/python-nginx:3.14

LABEL org.opencontainers.image.authors="HAWKI Team <ki@hawk.de>"
LABEL org.opencontainers.image.description="The HAWKI RAG reranker service"

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Configure the base image to run the FastAPI app with Gunicorn and Uvicorn workers
# See: https://github.com/Neunerlei/docker-images/blob/main/docs/python-nginx.md#configuration-via-environment-variables
ENV PYTHON_APP_MODULE="app:app"
ENV GUNICORN_WORKER_CLASS="uvicorn.workers.UvicornWorker"

RUN apt-get update && apt-get install -y --no-install-recommends \
    libgomp1 \
    rm -rf /var/lib/apt/lists/*

COPY ../backend/requirements.txt /var/www/html/requirements.txt
RUN pip install --no-cache-dir --retries 10 --timeout 180 -r requirements.txt

COPY ../backend/rerank/local_reranker/app.py /var/www/html/app.py
