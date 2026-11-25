#!/bin/bash
# Print the locally generated secrets for a new deployment. Existing database
# volumes retain their account passwords until those accounts are rotated.

set -euo pipefail

if [ "$#" -ne 0 ]; then
    printf 'Usage: %s\n' "${0##*/}" >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    printf '%s\n' 'generate-env-secrets: openssl is required.' >&2
    exit 1
fi

# Hex is safe in dotenv and Neo4j auth. Generate every value before emitting
# the block so a random-source failure cannot produce a partial credential set.
db_password="$(openssl rand -hex 24)"
neo4j_password="$(openssl rand -hex 24)"
callback_secret="$(openssl rand -hex 32)"
converter_token="$(openssl rand -hex 24)"

printf '%s\n' 'Set these values in .env for a new deployment. Keep existing database passwords on upgrades.' >&2
printf '%s\n' 'Provision the restricted runtime DSN, issuer credentials and provider keys separately.' >&2
printf '%s\n' \
    "DB_PASSWORD=$db_password" \
    "NEO4J_PASSWORD=$neo4j_password" \
    "HAWKI_RAG_WORKER_CALLBACK_SECRET=$callback_secret" \
    "FILE_CONVERTER_TOKEN=$converter_token"
