# HAWKI bridge compatibility package

This package preserves frozen query/graph wire behavior and test dependencies. It is not deployed as a Compose service or image target. Current API/query/graph operations belong to `hawki_backend`; use the [deployment guide](../../../_documentation/Operations/python_runtime.md).

Historical contracts and test fixtures may use Laravel-era names or signed control bytes. Keep those identifiers stable for compatibility/security regression tests. They do not create a human identity model or authorize live execution. Current workers use direct signed Python callbacks.

Run applicable package characterization tests through the [workspace testing guide](../../../_documentation/Developer/testing.md). Do not start a separate bridge service for current ingestion.
