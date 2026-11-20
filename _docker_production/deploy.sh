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

# Env assembly: the core .env is always loaded; provider/addon fragments
# (.env.ollama, .env.litellm, .env.scraper - copies of the matching
# .env.*.example files) are picked up automatically when present, both for
# ${...} interpolation here and for the services' env_file lists. Passing
# them explicitly matters for values the COMPOSE FILES interpolate
# (image tags like HAWKI_RAG_SCRAPER_VERSION or LITELLM_PORT), which resolve
# from the project environment only.
ENV_FILE_ARGS="--env-file .env"
for fragment in .env.ollama .env.litellm .env.scraper; do
    if [ -f "$fragment" ]; then
        ENV_FILE_ARGS="$ENV_FILE_ARGS --env-file $fragment"
    fi
done

docker compose $ENV_FILE_ARGS up --pull always -d

# Stop if the previous command failed
if [ $? -ne 0 ]; then
    echo "Docker compose up failed"
    exit 1
fi