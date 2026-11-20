"""Constraint inclusion for the actual URL authority's identity domain."""

import pytest
from hawki_rag_contracts.pipeline.crawler import require_crawler_api
from .crawler_fixtures import api_schema


@pytest.mark.parametrize(
    "field,constraint",
    [
        ("job_id", {"pattern": "^[0-9]+$"}),
        ("job_id", {"pattern": "^legacy_.*$"}),
        ("job_id", {"maxLength": 30}),
        ("source_id", {"pattern": "^[0-9]+$"}),
        ("source_id", {"minLength": 40}),
        ("task_id", {"format": "uuid"}),
        ("dataset_id", {"enum": ["one-dataset"]}),
        ("url_ingestion_operation_id", {"maxLength": 63}),
        ("generation", {"minimum": 100}),
        ("generation", {"maximum": 100}),
        ("generation", {"type": "string"}),
        ("generation", {"multipleOf": 2}),
        ("source_url", {"format": "email"}),
        ("source_url", {"format": "uri"}),
        ("generation", {"minimum": float("nan")}),
        ("sitemap", {"type": "array", "items": {"type": "string"}}),
        ("sitemap_only", {"enum": [True]}),
        ("sitemap_base", {"type": "object"}),
    ],
)
def test_narrowed_request_domain_refuses(field, constraint):
    schema = api_schema()
    schema["components"]["schemas"]["CrawlConfig"]["properties"][field].update(
        constraint
    )
    with pytest.raises(ValueError):
        require_crawler_api(schema)


@pytest.mark.parametrize(
    "fault",
    [
        "pattern",
        "required_sitemap",
        "body_predicate",
        "receipt_identity",
        "receipt_generation",
    ],
)
def test_route_body_and_receipt_constraints_refuse(fault):
    schema = api_schema()
    if fault == "pattern":
        schema["paths"]["/status/{job_id}"]["get"]["parameters"][0]["schema"] = {
            "type": "string",
            "pattern": "^[0-9]+$",
        }
    elif fault == "required_sitemap":
        schema["components"]["schemas"]["CrawlConfig"]["required"].append("sitemap")
    elif fault == "body_predicate":
        schema["components"]["schemas"]["CrawlConfig"]["not"] = {
            "required": ["dataset_id"]
        }
    else:
        fields = schema["paths"]["/status/{job_id}"]["get"]["responses"]["200"][
            "content"
        ]["application/json"]["schema"]["properties"]["metadata"]["properties"]
        fields["generation" if fault == "receipt_generation" else "source_id"].update(
            {"minimum": 100}
            if fault == "receipt_generation"
            else {"pattern": "^[0-9]+$"}
        )
    with pytest.raises(ValueError):
        require_crawler_api(schema)


@pytest.mark.parametrize(
    "generation",
    [{"minimum": 1}, {"minimum": 1, "maximum": 2**31 - 1, "format": "int32"}],
)
def test_reviewed_supported_domain_passes(generation):
    schema = api_schema()
    props = schema["components"]["schemas"]["CrawlConfig"]["properties"]
    props["generation"].update(generation)
    props["job_id"] = {
        "type": "string",
        "pattern": "^ingest_[a-f0-9]{24}$",
        "minLength": 31,
        "maxLength": 31,
    }
    props["source_id"] = {"type": "string", "pattern": "^source_[a-f0-9]{32}$"}
    require_crawler_api(schema)


@pytest.mark.parametrize("route", ["/status/{job_id}", "/jobs/{job_id}/cancel"])
def test_additional_route_constraints_refuse(route):
    schema = api_schema()
    schema["paths"][route]["parameters"] = [
        {
            "name": "job_id",
            "in": "path",
            "required": True,
            "schema": {"type": "string", "pattern": "^[0-9]+$"},
        }
    ]
    with pytest.raises(ValueError):
        require_crawler_api(schema)
