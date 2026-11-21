from .conftest import call


def test_runtime_metadata_is_safe_and_independent_of_query_selection(env, monkeypatch):
    monkeypatch.setenv("OPENAI_API_KEY", "never-expose-this")
    response = call(env, "config", token="manager")
    assert response.status_code == 200
    runtime = response.json()["runtime"]
    assert runtime["engine"] == "raganything"
    assert runtime["latest_dataset"]
    assert runtime["embedding_model"]
    assert set(runtime) == {
        "engine",
        "query_provider",
        "query_model",
        "embedding_model",
        "latest_dataset",
    }
    assert "never-expose-this" not in response.text
