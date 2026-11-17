#!/bin/bash
# Deploys the HAWKI RAG production stack: pulls the images pinned through
# HAWKI_RAG_VERSION in .env, runs the database migrations through the one-shot
# migrator service, and starts every service in dependency order. The Ollama
# models are pulled by the ollama-entrypoint.sh when the ollama addon service
# starts, so no model handling happens here.
#
# Optional services are additive addon files in this directory - inclusion
# through COMPOSE_FILE is activation. Run models locally by adding the
# Ollama addon; accelerate them on an NVIDIA GPU by adding the GPU addon
# after it (it requires the Ollama addon):
#   COMPOSE_FILE=docker-compose.yml:docker-compose.with-ollama.yml ./deploy.sh
#   COMPOSE_FILE=docker-compose.yml:docker-compose.with-ollama.yml:docker-compose.with-ollama-and-gpu.yml ./deploy.sh
# The gateway (docker-compose.with-litellm.yml), the Temporal UI
# (docker-compose.with-temporal-ui.yml) and the scraper
# (docker-compose.scraper.yml) chain the same way.

docker compose up --pull always -d

# Stop if the previous command failed
if [ $? -ne 0 ]; then
    echo "Docker compose up failed"
    exit 1
fi