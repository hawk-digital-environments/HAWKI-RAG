#!/usr/bin/env bash
set -euo pipefail

CONTAINER="${CONTAINER:-hawki_rag_neo4j}"
DATABASE="${NEO4J_DATABASE:-neo4j}"
BACKUP_DIR="${BACKUP_DIR:-storage/app/Neo4j_backup}"
NEO4J_IMAGE="${NEO4J_IMAGE:-neo4j:5.22}"
TIMESTAMP="$(date -u +"%Y%m%dT%H%M%SZ")"

if ! command -v docker >/dev/null 2>&1; then
  echo "[!] docker CLI not found. Install Docker or run this script on the host that manages the container." >&2
  exit 1
fi

if ! docker inspect "${CONTAINER}" >/dev/null 2>&1; then
  echo "[!] Container '${CONTAINER}' not found. Set CONTAINER=<name> before running the script." >&2
  exit 1
fi

mkdir -p "${BACKUP_DIR}"
# Ensure the helper container can write into the backup folder.
chmod 777 "${BACKUP_DIR}"
BACKUP_PATH="$(cd "${BACKUP_DIR}" && pwd)"

was_running="$(docker inspect -f '{{.State.Running}}' "${CONTAINER}")"
restart_needed=0

cleanup() {
  if (( restart_needed )); then
    echo "[+] Restarting ${CONTAINER}…"
    docker start "${CONTAINER}" >/dev/null || {
      echo "[!] Failed to restart ${CONTAINER}. Please start it manually." >&2
      return
    }
    echo "[✓] ${CONTAINER} is back online."
  fi
}
trap cleanup EXIT

if [[ "${was_running}" == "true" ]]; then
  echo "[+] Stopping ${CONTAINER} to take an offline dump…"
  docker stop "${CONTAINER}" >/dev/null
  restart_needed=1
else
  echo "[i] ${CONTAINER} is already stopped; proceeding with offline dump."
fi

dump_cmd=$'set -euo pipefail\nexport PATH=/var/lib/neo4j/bin:$PATH\nneo4j-admin database dump '"${DATABASE}"$' --to-path=/backups --overwrite-destination=true'

echo "[+] Running neo4j-admin dump for '${DATABASE}' using ${NEO4J_IMAGE}…"
docker run --rm \
  --volumes-from "${CONTAINER}" \
  -v "${BACKUP_PATH}:/backups" \
  "${NEO4J_IMAGE}" \
  bash -lc "${dump_cmd}"

SOURCE_FILE="${BACKUP_PATH}/${DATABASE}.dump"
if [[ ! -f "${SOURCE_FILE}" ]]; then
  echo "[!] Dump command completed but '${SOURCE_FILE}' was not created." >&2
  exit 1
fi

TARGET_FILE="${BACKUP_PATH}/${DATABASE}-${TIMESTAMP}.dump"
mv -f "${SOURCE_FILE}" "${TARGET_FILE}"
echo "[✓] Backup saved to ${TARGET_FILE}"

if (( restart_needed )); then
  echo "[+] Restarting ${CONTAINER}…"
  docker start "${CONTAINER}" >/dev/null
  restart_needed=0
  echo "[✓] ${CONTAINER} is back online."
fi
