FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# Install LightRAG and supplemental dependencies
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt

# The server reads configuration from environment variables (.env via env_file)
EXPOSE 9621

COPY entry.sh /app/entry.sh
RUN chmod +x /app/entry.sh

CMD ["/app/entry.sh"]
