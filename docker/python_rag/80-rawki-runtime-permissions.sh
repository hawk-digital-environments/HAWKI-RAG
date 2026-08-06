#!/bin/bash

shared_gid="${PIPELINE_SHARED_STORAGE_GID:-}"

if [ -n "$shared_gid" ]; then
    case "$shared_gid" in
        *[!0-9]*)
            echo "[RAWKI.entrypoint] PIPELINE_SHARED_STORAGE_GID must be numeric." >&2
            exit 1
            ;;
    esac

    if [ "$shared_gid" != "$(id -g www-data)" ]; then
        shared_group="$(getent group "$shared_gid" | cut -d: -f1)"
        if [ -z "$shared_group" ]; then
            shared_group="rawki-shared-$shared_gid"
            groupadd --gid "$shared_gid" "$shared_group"
        fi
        usermod --append --groups "$shared_group" www-data
    fi
fi

for writable_dir in /home/rawki /app/public /app/rag_storage; do
    if [ -d "$writable_dir" ]; then
        chown -R www-data:www-data "$writable_dir"
    fi
done
