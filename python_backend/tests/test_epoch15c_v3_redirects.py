"""Non-2xx bodies never establish reranker readiness; redirects are unsupported."""

import json
import pytest
import requests
from hawki_backend import readiness


@pytest.mark.parametrize("status", [200, 201, 302, 307, 401, 404, 500])
def test_http_status_precedes_body_validation(monkeypatch, status):
    calls = []

    def post(url, **kwargs):
        assert kwargs["allow_redirects"] is False
        calls.append(url)
        response = requests.Response()
        response.status_code = status
        response.headers["Location"] = "http://redirect-target/v1/rerank"
        response._content = json.dumps(
            {
                "model": "reviewed",
                "results": [
                    {
                        "index": 0,
                        "document": "RAWKI readiness document.",
                        "relevance_score": 0.9,
                    }
                ],
            }
        ).encode()
        return response

    monkeypatch.setattr(requests, "post", post)
    env = {
        "RAG_BACKEND_QUERY_RERANKER": "external",
        "RERANKER_API_URL": "http://canonical/v1/rerank",
        "RERANKER_MODEL": "reviewed",
    }
    if 200 <= status < 300:
        readiness._reranker(env)
    else:
        with pytest.raises(ValueError):
            readiness._reranker(env)
    assert calls == ["http://canonical/v1/rerank"]


@pytest.mark.parametrize("target_status", [200, 503])
def test_redirect_target_is_never_contacted(monkeypatch, target_status):
    seen = []

    class Adapter(requests.adapters.BaseAdapter):
        def send(self, request, **kwargs):
            seen.append(request.url)
            response = requests.Response()
            response.request = request
            response.url = request.url
            response.status_code = (
                302 if request.url.startswith("http://canonical/") else target_status
            )
            response.headers["Location"] = "http://target/v1/rerank"
            response._content = json.dumps(
                {
                    "model": "reviewed",
                    "results": [
                        {
                            "index": 0,
                            "document": "RAWKI readiness document.",
                            "relevance_score": 0.9,
                        }
                    ],
                }
            ).encode()
            return response

        def close(self):
            pass

    monkeypatch.setattr(requests.Session, "get_adapter", lambda *_, **kw: Adapter())
    with pytest.raises(ValueError):
        readiness._reranker(
            {
                "RAG_BACKEND_QUERY_RERANKER": "external",
                "RERANKER_API_URL": "http://canonical/v1/rerank",
                "RERANKER_MODEL": "reviewed",
            }
        )
    assert seen == ["http://canonical/v1/rerank"]
