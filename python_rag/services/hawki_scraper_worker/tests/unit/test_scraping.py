"""Characterization tests for extracted scraper helpers."""

from __future__ import annotations

from pathlib import Path
from tempfile import TemporaryDirectory

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_scraper_worker.scraping.requests import build_scraper_start_payload
from hawki_scraper_worker.scraping.responses import (
    SCRAPER_BOOKKEEPING_FILENAMES,
    crawled_output_file_count,
    normalize_scrape_result,
    shared_worker_path,
)


def test_start_payload_preserves_the_external_crawler_contract() -> None:
    payload = build_scraper_start_payload(
        {
            "job_id": "ingest_lubeck",
            "source_url": "https://uni-luebeck.de",
            "metadata": {
                "request": {
                    "sitemapUrl": "https://uni-luebeck.de/sitemap.xml",
                    "metadata": {
                        "site_profile_path": "/profiles/Lubeck.json",
                        "max_pages": 25,
                        "max_concurrency": 1,
                        "max_rpm": 60,
                        "skip_images": True,
                        "discovery_mode": False,
                    },
                }
            },
        },
        "source_lubeck",
        "/shared/sources/source_lubeck/raw/",
    )

    assert payload == {
        "job_id": "ingest_lubeck",
        "url": "https://uni-luebeck.de",
        "output_dir": "/shared/sources/source_lubeck/raw/",
        "source_id": "source_lubeck",
        "source_url": "https://uni-luebeck.de",
        "site_profile_path": "/profiles/Lubeck.json",
        "sitemap": True,
        "sitemap_base": "https://uni-luebeck.de/sitemap.xml",
        "max_pages": 25,
        "max_concurrency": 1,
        "max_rpm": 60,
        "skip_images": True,
        "discovery_mode": False,
    }


def test_crawler_mount_paths_map_to_the_shared_worker_mount() -> None:
    assert (
        shared_worker_path("/var/www/html/shared/ingest_lubeck")
        == "/shared/ingest_lubeck"
    )
    assert (
        shared_worker_path("/app/shared/sources/source/raw")
        == "/shared/sources/source/raw"
    )
    assert (
        shared_worker_path("/shared/sources/source/raw") == "/shared/sources/source/raw"
    )
    assert (
        shared_worker_path(
            "/app/shared/sources/source/raw",
            shared_root="/custom-shared",
        )
        == "/custom-shared/sources/source/raw"
    )


def test_success_without_content_files_is_reported_as_failed() -> None:
    with TemporaryDirectory() as temporary:
        raw_dir = Path(temporary)
        for name in SCRAPER_BOOKKEEPING_FILENAMES:
            (raw_dir / name).write_text("{}", encoding="utf-8")

        result = normalize_scrape_result(
            {
                "status": "completed",
                "output_directory": str(raw_dir),
                "external_job_id": "scrape-empty",
            },
            {"max_pages": 300},
            "source-empty",
            str(raw_dir),
            LocalArtifactStore(raw_dir),
        )

    assert result["status"] == "failed"
    assert result["pages_crawled"] == 0
    assert result["raw_files_found"] == 0
    assert "without crawled page files" in result["error_details"]


def test_real_files_are_counted_and_page_limit_is_enforced() -> None:
    with TemporaryDirectory() as temporary:
        raw_dir = Path(temporary)
        page = raw_dir / "pages" / "home.md"
        page.parent.mkdir()
        page.write_text("# Home", encoding="utf-8")

        complete = normalize_scrape_result(
            {"status": "completed", "output_directory": str(raw_dir)},
            {"max_pages": 1},
            "source-one",
            str(raw_dir),
            LocalArtifactStore(raw_dir),
        )
        incomplete = normalize_scrape_result(
            {"status": "completed", "output_directory": str(raw_dir)},
            {"max_pages": 300},
            "source-short",
            str(raw_dir),
            LocalArtifactStore(raw_dir),
        )

    assert complete["status"] == "success"
    assert complete["pages_crawled"] == 1
    assert incomplete["status"] == "failed"
    assert "1/300 pages" in incomplete["error_details"]


def test_crawled_file_count_rejects_symlinks_outside_the_raw_directory(
    tmp_path: Path,
) -> None:
    shared_root = tmp_path / "shared"
    raw_dir = shared_root / "raw"
    raw_dir.mkdir(parents=True)
    outside = tmp_path / "outside.md"
    outside.write_text("secret", encoding="utf-8")
    (raw_dir / "escape.md").symlink_to(outside)

    with pytest.raises(ValueError, match="shared root"):
        crawled_output_file_count(str(raw_dir), LocalArtifactStore(shared_root))
