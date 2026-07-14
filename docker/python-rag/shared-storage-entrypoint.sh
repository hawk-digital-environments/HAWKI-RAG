#!/bin/sh

set -eu

shared_root="${HAWKI_RAG_TEMPORAL_SHARED_ROOT:-/shared}"
shared_gid="${PIPELINE_SHARED_STORAGE_GID:-1000}"

case "$shared_gid" in
    ''|*[!0-9]*)
        echo "PIPELINE_SHARED_STORAGE_GID must be a numeric group id; received: $shared_gid" >&2
        exit 64
        ;;
esac

if [ -d "$shared_root" ]; then
    mkdir -p "$shared_root/sources" "$shared_root/logs"

    # Laravel and the Python processes use different users. A shared numeric
    # group plus setgid directories keeps every new workspace removable by
    # PHP-FPM without making the volume world-writable.
    find "$shared_root" -type d -exec chgrp "$shared_gid" {} +
    find "$shared_root" -type d -exec chmod g+rwx,g+s {} +

    umask 0002
fi

exec "$@"
