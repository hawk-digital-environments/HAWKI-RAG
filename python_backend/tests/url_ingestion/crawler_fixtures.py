"""Current wire fields read from CustomCrawler's FastAPI models and runtime store."""


def api_schema():
    return {
        "paths": {
            "/crawl": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {"$ref": "#/components/schemas/CrawlConfig"}
                            }
                        }
                    }
                }
            },
            "/status/{job_id}": {
                "get": {
                    "parameters": [
                        {
                            "name": "job_id",
                            "schema": {"pattern": "^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$"},
                        }
                    ],
                    "responses": {
                        "200": {"content": {"application/json": {"schema": {}}}}
                    },
                }
            },
            "/jobs/{job_id}/cancel": {"post": {}},
        },
        "components": {
            "schemas": {
                "CrawlConfig": {
                    "properties": {
                        name: (
                            {"pattern": "^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$"}
                            if name == "job_id"
                            else {}
                        )
                        for name in (
                            "job_id",
                            "source_id",
                            "source_url",
                            "generation",
                            "url_ingestion_operation_id",
                            "url",
                            "output_dir",
                            "sitemap",
                            "sitemap_base",
                            "sitemap_only",
                        )
                    }
                }
            }
        },
    }
