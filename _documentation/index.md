# HAWKI-RAG documentation

FastAPI serves the HAWKI-RAG API. Python workers convert and index documents
under Temporal. Client applications authenticate their users and send service
JWTs to the API. Workers report ingestion events through signed callbacks.

- [Current deployment and architecture](Operations/python_runtime.md)
- [Python testing](Developer/testing.md)

The deployment guide covers database provisioning, service authentication, and
readiness. URL ingestion also requires a crawler that implements
`crawler-api`. See [external crawler setup](Operations/crawler_v2_local_upgrade.md).
