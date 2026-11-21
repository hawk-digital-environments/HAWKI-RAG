# Local development upgrade of the installed, dependency-compatible crawler.
# The context is a pinned export prepared by scripts/prepare_local_crawler.py.
FROM crawl4ai-fastapi:latest AS runtime
LABEL org.rawki.crawler.upstream-revision="1c327244fed3b6f99ad08ad9203814942bc10f26"
ENV PYTHONPATH=/opt/rawki-crawler \
    PYTHONDONTWRITEBYTECODE=1
COPY hawki_crawler /opt/rawki-crawler/hawki_crawler
COPY RAWKI_SOURCE.json /opt/rawki-crawler/RAWKI_SOURCE.json

FROM runtime AS test
USER root
RUN apt-get update && apt-get install -y --no-install-recommends iproute2 libnss3-tools \
    && rm -rf /var/lib/apt/lists/*
RUN python -m pip install --no-cache-dir pytest==8.4.2 pytest-asyncio==1.3.0
COPY tests /opt/rawki-crawler/tests
COPY consumer_contracts /opt/rawki-consumer-contracts
ENV PYTHONPATH=/opt/rawki-crawler:/opt/rawki-consumer-contracts
ENTRYPOINT ["python", "-m", "pytest"]
CMD ["-q", "-p", "no:cacheprovider", "/opt/rawki-crawler/tests/test_permanent_job_v2.py", "/opt/rawki-crawler/tests/test_runtime_contract.py", "/opt/rawki-crawler/tests/test_safe_network.py", "/opt/rawki-crawler/tests/test_egress_broker.py"]
