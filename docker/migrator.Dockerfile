FROM python:3.11-slim AS python-rag
USER root

COPY --chmod=755 ./docker/migrator/migrator-entrypoint.sh /usr/local/bin/hawki-migrator-entrypoint

ENTRYPOINT ["/usr/local/bin/hawki-migrator-entrypoint"]
