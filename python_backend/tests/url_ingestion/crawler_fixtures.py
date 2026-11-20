"""External crawler v2 OpenAPI fixture."""


def api_schema():
    identities = {
        key: {"type": "string"}
        for key in (
            "dataset_id",
            "task_id",
            "source_id",
            "source_url",
            "url_ingestion_operation_id",
        )
    }
    identities["generation"] = {"type": "integer", "minimum": 1}

    def obj(properties):
        return {
            "type": "object",
            "properties": properties,
            "required": list(properties),
        }

    def response(schema):
        return {"200": {"content": {"application/json": {"schema": schema}}}}

    patterned = {"type": "string", "pattern": "^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$"}
    states = [
        "pending",
        "running",
        "paused",
        "cancelling",
        "unknown",
        "completed",
        "failed",
        "cancelled",
    ]
    receipt = obj(
        {
            **{
                k: {"type": "string"}
                for k in ("job_id", "external_job_id", "output_directory")
            },
            **{
                k: {"type": "boolean"}
                for k in ("terminal", "sealed", "quiescent", "writer_admission_closed")
            },
            "status": {"type": "string", "enum": states},
            "state": {"type": "string", "enum": states},
            "metadata": obj(identities),
            "pages_crawled": {"type": "integer", "minimum": 0},
            "sealed_at": {
                "anyOf": [{"type": "string", "format": "date-time"}, {"type": "null"}]
            },
        }
    )
    request = obj(
        {
            **identities,
            "job_id": patterned,
            **{k: {"type": "string"} for k in ("url", "output_dir", "sitemap_base")},
            "sitemap": {"type": "boolean"},
            "sitemap_only": {"type": "boolean"},
        }
    )
    request["required"] = [
        name
        for name in request["required"]
        if name not in {"sitemap", "sitemap_only", "sitemap_base"}
    ]
    return {
        "x-hawki-crawler-contract": "permanent-job-v2",
        "paths": {
            "/crawl": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {"$ref": "#/components/schemas/CrawlConfig"}
                            }
                        }
                    },
                    "responses": response(
                        obj(
                            {
                                "job_id": patterned,
                                "event": {
                                    "type": "string",
                                    "enum": ["job_submitted", "job_exists"],
                                },
                                "data": obj({"sealed": {"type": "boolean"}}),
                            }
                        )
                    ),
                }
            },
            "/status/{job_id}": {
                "get": {
                    "parameters": [
                        {
                            "name": "job_id",
                            "in": "path",
                            "required": True,
                            "schema": patterned,
                        }
                    ],
                    "responses": response(receipt),
                }
            },
            "/jobs/{job_id}/cancel": {"post": {}},
        },
        "components": {"schemas": {"CrawlConfig": request}},
    }
