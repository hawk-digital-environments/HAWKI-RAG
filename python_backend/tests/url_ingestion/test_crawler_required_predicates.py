"""Every required admission/receipt scalar must validate all of its predicates."""

import pytest
from hawki_rag_contracts.pipeline.crawler import require_crawler_api, STATES
from .crawler_fixtures import api_schema


def field(schema, name):
    admission = schema["paths"]["/crawl"]["post"]["responses"]["200"]["content"][
        "application/json"
    ]["schema"]["properties"]
    receipt = schema["paths"]["/status/{job_id}"]["get"]["responses"]["200"]["content"][
        "application/json"
    ]["schema"]["properties"]
    if name == "admission_sealed":
        return admission["data"]["properties"]["sealed"]
    return admission["event"] if name == "event" else receipt[name]


@pytest.mark.parametrize(
    "name,predicate,passes",
    [
        ("status", {"not": {}}, False),
        ("admission_sealed", {"const": True}, False),
        ("admission_sealed", {"const": False}, True),
        ("admission_sealed", {"enum": [False]}, True),
        ("event", {"oneOf": [{"const": "unrelated"}]}, False),
        ("status", {"enum": sorted(STATES)}, True),
        ("status", {"allOf": [{"type": "string"}, {"const": "completed"}]}, False),
        ("status", {"anyOf": [{"enum": sorted(STATES)}, {"const": "other"}]}, True),
        ("status", {"oneOf": [{"type": "string"}, {"enum": sorted(STATES)}]}, False),
        ("status", {"customPredicate": True}, False),
        ("admission_sealed", {"allOf": [{"not": {"const": False}}]}, False),
        ("pages_crawled", {"not": {}}, False),
        ("pages_crawled", {"minimum": 1}, False),
        ("sealed_at", {"not": {}}, False),
    ],
)
def test_required_predicates(name, predicate, passes):
    schema = api_schema()
    field(schema, name).update(predicate)
    if passes:
        require_crawler_api(schema)
    else:
        with pytest.raises(ValueError):
            require_crawler_api(schema)


@pytest.mark.parametrize(
    "name",
    [
        "status",
        "state",
        "terminal",
        "sealed",
        "quiescent",
        "writer_admission_closed",
        "pages_crawled",
        "job_id",
        "external_job_id",
        "output_directory",
    ],
)
def test_unknown_predicate_never_bypasses_required_receipt_fields(name):
    schema = api_schema()
    field(schema, name)["unhandled"] = True
    with pytest.raises(ValueError):
        require_crawler_api(schema)


def test_existing_v2_contract_still_passes():
    require_crawler_api(api_schema())
