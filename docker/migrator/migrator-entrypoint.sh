#!/bin/sh

set -eu

shared_root="${HAWKI_RAG_TEMPORAL_SHARED_ROOT:-/shared}"
shared_gid="${PIPELINE_SHARED_STORAGE_GID:-1000}"
initialize_storage="${HAWKI_RAG_SHARED_STORAGE_INIT:-0}"

case "$shared_gid" in
    ''|*[!0-9]*)
        echo "PIPELINE_SHARED_STORAGE_GID must be a numeric group id; received: $shared_gid" >&2
        exit 64
        ;;
esac

if [ "$initialize_storage" = "1" ]; then
    mkdir -p \
        "$shared_root/sources" \
        "$shared_root/logs" \
        "$shared_root/public" \
        "$shared_root/storage/logs"

    # This branch is reserved for the short-lived root init container. A shared
    # numeric group plus setgid directories keeps Python and PHP-FPM files
    # mutually writable without making the volume world-writable.
    find "$shared_root" -exec chgrp "$shared_gid" {} +
    find "$shared_root" -type d -exec chmod g+rwx,g+s {} +
    find "$shared_root" -type f -exec chmod g+rw {} +
elif [ ! -d "$shared_root" ] || [ ! -w "$shared_root" ]; then
    echo "Shared storage is not writable: $shared_root. Run the shared-storage init service first." >&2
    exit 73
fi

umask 0002

exec "$@"
