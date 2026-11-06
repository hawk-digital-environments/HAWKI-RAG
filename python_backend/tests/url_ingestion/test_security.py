from dataclasses import replace

import pytest
import sqlalchemy as sa
from pydantic import ValidationError

from hawki_backend.persistence.schema import dataset_grants, datasets
from hawki_backend.url_ingestion.errors import UrlIngestionError
from hawki_backend.url_ingestion.identity import normalize_url, source_identity
from hawki_backend.url_ingestion.schema import requests
from hawki_backend.url_ingestion.settings import load_url_ingestion_settings
from hawki_backend.url_ingestion.validation import (
    ExternalCrawlerBoundary,
    UrlSafetyValidator,
    UrlStartInput,
    site_profile,
)

from .conftest import create, request


@pytest.mark.parametrize(
    "address",
    [
        "127.0.0.1",
        "127.42.1.1",
        "::1",
        "10.0.0.1",
        "172.16.0.1",
        "172.31.255.254",
        "192.168.1.1",
        "169.254.169.254",
        "169.254.2.1",
        "fe80::1",
        "fc00::1",
        "ff02::1",
        "0.0.0.0",
        "::",
        "192.0.2.1",
        "198.18.0.1",
        "100.64.0.1",
        "224.0.0.1",
        "::ffff:127.0.0.1",
    ],
)
def test_nonpublic_resolution_rejected(address):
    with pytest.raises(UrlIngestionError):
        UrlSafetyValidator(lambda host, port: (address,)).validate(
            "https://example.org"
        )


@pytest.mark.parametrize(
    "url",
    [
        "http://localhost",
        "http://sub.localhost",
        "http://metadata.google.internal",
        "http://metadata",
        "file:///etc/passwd",
        "ftp://example.org",
        "gopher://example.org",
        "javascript:alert(1)",
        "data:text/html,hi",
        "http://user:pass@example.org",
        "http://example.org\\@127.0.0.1",
        "http://[fe80::1%25en0]",
        "https://example.org\nprivate",
    ],
)
def test_unsafe_syntax_or_named_targets_rejected_before_resolution(url):
    with pytest.raises(UrlIngestionError):
        UrlSafetyValidator(lambda host, port: ("93.184.215.14",)).validate(url)


def test_every_dns_answer_must_be_public():
    with pytest.raises(UrlIngestionError):
        UrlSafetyValidator(lambda host, port: ("93.184.215.14", "10.0.0.1")).validate(
            "https://example.org"
        )


def test_external_boundary_never_claims_redirect_or_rebinding_protection():
    public = UrlSafetyValidator(lambda host, port: ("93.184.215.14",)).validate(
        "https://example.org"
    )
    with pytest.raises(UrlIngestionError, match="pinned DNS and redirect"):
        ExternalCrawlerBoundary().require_safe_fetch([public])


def test_production_crawler_gate_precedes_all_persistence_and_dispatch(env):
    env.service.crawler = ExternalCrawlerBoundary()
    with pytest.raises(UrlIngestionError) as caught:
        create(env)
    assert caught.value.status == 503
    with env.engine.connect() as connection:
        assert connection.scalar(sa.select(sa.func.count()).select_from(requests)) == 0
    env.port.start_ingest_workflow.assert_not_called()


@pytest.mark.parametrize("mode", ["no_grant", "inactive", "tenant", "user"])
def test_exact_authorization_before_dns_or_claim(env, mode):
    if mode in {"no_grant", "inactive"}:
        with env.engine.begin() as connection:
            if mode == "no_grant":
                connection.execute(dataset_grants.delete())
            else:
                connection.execute(datasets.update().values(status="archived"))
    else:
        env.principal = replace(
            env.principal,
            **({"tenant_id": "other"} if mode == "tenant" else {"subject": "other"}),
        )
    with pytest.raises(UrlIngestionError) as caught:
        create(env)
    assert caught.value.status == 404
    env.service.crawler.require_safe_fetch.assert_not_called()
    with env.engine.connect() as connection:
        assert connection.scalar(sa.select(sa.func.count()).select_from(requests)) == 0


def test_anonymous_python_mutation_denied(env):
    assert (
        request(
            env,
            "/v1/pipeline/tasks/start",
            {"dataset_id": "web", "source_url": "example.org"},
            headers={},
        ).status_code
        == 401
    )


@pytest.mark.parametrize(
    "field,value,status",
    [
        ("sitemap_url", "https://example.org/sitemap.xml", 503),
        ("sitemap_path", "/etc/passwd", 422),
    ],
)
def test_sitemap_fails_closed_without_fetch_or_claim(env, field, value, status):
    with pytest.raises(UrlIngestionError) as caught:
        create(env, **{field: value})
    assert caught.value.status == status
    env.port.start_ingest_workflow.assert_not_called()


def test_sitemap_same_public_network_policy(env):
    env.service.safety = UrlSafetyValidator(lambda host, port: ("169.254.169.254",))
    with pytest.raises(UrlIngestionError) as caught:
        create(env, sitemap_url="https://example.org/map")
    assert caught.value.status == 422


def test_profile_root_rejects_traversal_outside_and_symlinks(tmp_path):
    allowed = tmp_path / "profiles"
    allowed.mkdir()
    (allowed / "good.json").write_text("{}")
    outside = tmp_path / "outside.json"
    outside.write_text("{}")
    (allowed / "escape.json").symlink_to(outside)
    assert site_profile("good.json", str(allowed)) == str(allowed / "good.json")
    for value in ("../outside.json", str(outside), "escape.json"):
        with pytest.raises(UrlIngestionError):
            site_profile(value, str(allowed))


def test_scraper_alias_composes_once_and_canonical_wins():
    assert (
        load_url_ingestion_settings({"CUSTOM_CRAWLER_URL": "http://legacy"}).scraper_url
        == "http://legacy"
    )
    assert (
        load_url_ingestion_settings(
            {
                "CUSTOM_CRAWLER_URL": "http://legacy",
                "EXTERNAL_SCRAPER_URL": "http://canonical",
            }
        ).scraper_url
        == "http://canonical"
    )


@pytest.mark.parametrize(
    "key",
    [
        "scraper_url",
        "scraper_token",
        "external_services",
        "owner",
        "managed_document_id",
    ],
)
def test_caller_cannot_choose_scraper_or_owner(key):
    with pytest.raises(ValidationError):
        UrlStartInput.model_validate(
            {"dataset_id": "web", "metadata": {key: "override"}}
        )


def test_identity_keeps_legacy_distinct_spellings():
    base = "https://Example.org/a?b=1&c=2#f"
    assert normalize_url("  Example.org/a?b=1&c=2#f  ") == base
    urls = [
        base,
        base.replace("Example", "example"),
        base + "/",
        base.replace("#f", "#g"),
        base.replace("b=1&c=2", "c=2&b=1"),
        base.replace(".org", ".org:443"),
    ]
    assert len({source_identity("t", "d", url) for url in urls}) == len(urls)
    assert source_identity("t", "d", base) != source_identity("t", "other", base)


def test_schedule_stores_cadence_without_frozen_temporal_input(env):
    payload = create(env, cadence="daily")
    job = payload["task"]["jobs"][0]
    row = env.repository.source("default", job["source_id"])
    assert row["refresh_cadence"] == "daily"
    assert job["temporal_schedule_id"] is None


def test_readiness_distinguishes_configuration_and_safety(env):
    response = request(
        env, "/internal/url-ingestion/readiness", method="GET", headers={}
    )
    assert response.json() == {
        "enabled": True,
        "scraper_configured": True,
        "workflow_control_configured": True,
        "scraper_healthy": None,
        "safe_fetch_supported": False,
        "schedule_generations_supported": True,
        "ready": False,
    }


def test_dns_rebinding_cannot_reach_a_second_resolver_or_transport(env):
    answers = iter([("93.184.215.14",), ("127.0.0.1",)])
    seen = []

    def resolver(host, port):
        answer = next(answers)
        seen.append(answer)
        return answer

    env.service.safety = UrlSafetyValidator(resolver)
    env.service.crawler = ExternalCrawlerBoundary()
    with pytest.raises(UrlIngestionError) as caught:
        create(env)
    assert caught.value.status == 503
    assert seen == [("93.184.215.14",)]
    env.port.start_ingest_workflow.assert_not_called()
    # This proves fail-closed non-dispatch; it does not claim an available
    # network transport can safely follow redirects or use a pinned resolver.


@pytest.mark.parametrize(
    "target",
    [
        "http://127.0.0.1",
        "http://169.254.169.254",
        "http://10.0.0.1",
        "http://[::1]",
        "file:///etc/passwd",
    ],
)
def test_no_redirect_or_sitemap_fetch_can_bypass_unavailable_transport(env, target):
    env.service.crawler = ExternalCrawlerBoundary()
    with pytest.raises(UrlIngestionError):
        create(env, sitemap_url=target)
    env.port.start_ingest_workflow.assert_not_called()
