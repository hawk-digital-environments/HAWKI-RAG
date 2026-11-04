#!/bin/bash
# Generates fresh values for the required secrets of the production stack
# (the "Required Settings" section at the top of .env.example) and prints
# them as a ready-to-paste env block:
#
#   ./generate-env-secrets.sh
#
# Copy the printed block into .env, overwriting the empty values of the same
# names. The script only prints - it never writes files - and running it
# again produces NEW values; it cannot recover the ones already in use.
#
# Meant for the FIRST start of a deployment: a changed APP_KEY makes
# encrypted sessions and stored values unreadable, and new database
# passwords do not rotate the accounts inside existing PostgreSQL/Neo4j
# volumes. To rotate on a running deployment, change the credentials in the
# databases first and only then the matching .env values.

set -euo pipefail

if ! command -v openssl >/dev/null 2>&1; then
    echo "generate-env-secrets: 'openssl' is required but was not found on PATH." >&2
    exit 1
fi

# Same recipes as the installation documentation: the Laravel APP_KEY is the
# base64 encoding of 32 random bytes behind the literal "base64:" prefix, the
# database passwords are plain hex tokens (hex keeps them safe for the
# NEO4J_AUTH interpolation), and the callback secret is a longer hex token
# shared by Laravel and the activity workers.
app_key="base64:$(openssl rand -base64 32)"
db_password="$(openssl rand -hex 24)"
neo4j_password="$(openssl rand -hex 24)"
worker_callback_secret="$(openssl rand -hex 32)"

# The instructions go to stderr so stdout stays a clean, redirectable block.
echo "Paste the following block into .env (Required Settings section):" >&2

# The comment line keeps the block valid env syntax even when pasted
# wholesale, and records when the values were generated.
cat <<EOF
# Required settings, generated $(date '+%Y-%m-%d %H:%M:%S %Z') by generate-env-secrets.sh
APP_KEY=$app_key
DB_PASSWORD=$db_password
NEO4J_PASSWORD=$neo4j_password
HAWKI_RAG_WORKER_CALLBACK_SECRET=$worker_callback_secret
EOF
