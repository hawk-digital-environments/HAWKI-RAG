from hawki_backend.main import create_app


def test_multipart_upload_fields_match_the_existing_manual_parser():
    paths = create_app().openapi()["paths"]
    for path, method, field in [
        ("/v1/documents", "post", "file"),
        ("/v1/documents/batch", "post", "files"),
        ("/v1/documents/{document_id}", "put", "file"),
    ]:
        operation = paths[path][method]
        schema = operation["requestBody"]["content"]["multipart/form-data"]["schema"]
        assert field in schema["required"]
        assert ("dataset_id" in schema["required"]) == (method == "post")
        file_schema = schema["properties"][field]
        if field == "files":
            assert file_schema["type"] == "array"
            file_schema = file_schema["items"]
        assert file_schema == {"type": "string", "format": "binary"}
        assert {"dataset_id", "graph", "converter_mode"} <= schema["properties"].keys()
        assert schema["properties"]["metadata_json"]["type"] == "string"
        assert schema["properties"]["converter_token"]["format"] == "password"
        assert any(p["name"] == "Idempotency-Key" for p in operation["parameters"])
