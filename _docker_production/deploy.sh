#!/bin/bash
# Deploys the HAWKI RAG production stack: pulls the images pinned through
# HAWKI_RAG_VERSION in .env, runs the database migrations through the one-shot
# migrator service, and starts every service in dependency order.
#
# NVIDIA GPUs: export COMPOSE_FILE with the GPU override appended so the
# reranker, the indexer worker and Ollama get NVIDIA device access:
#   COMPOSE_FILE=docker-compose.yml:docker-compose.gpu-override.yml ./deploy.sh

docker compose up --pull always -d

# Stop if the previous command failed
if [ $? -ne 0 ]; then
    echo "Docker compose up failed"
    exit 1
fi