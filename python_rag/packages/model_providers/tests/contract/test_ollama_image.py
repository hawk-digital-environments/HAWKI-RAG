"""Compose contracts for the pinned Ollama image and GPU acceleration."""

from __future__ import annotations

from pathlib import Path
import re


PYTHON_ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
REPOSITORY_ROOT = PYTHON_ROOT.parent
BASE_COMPOSE_FILE = REPOSITORY_ROOT / "docker-compose.yml"
OLLAMA_ADDON_FILE = REPOSITORY_ROOT / "compose" / "with_ollama.yml"
OLLAMA_GPU_ADDON_FILE = REPOSITORY_ROOT / "compose" / "with_ollama_gpu.yml"


def _service_block(compose_file: Path, service_name: str) -> str:
    compose = compose_file.read_text(encoding="utf-8")
    match = re.search(
        rf"(?ms)^  {re.escape(service_name)}:\n"
        r"(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:\n|^networks:\n|\Z)",
        compose,
    )
    assert match is not None, service_name

    return match.group("body")


def test_ollama_addon_uses_the_pinned_official_image_without_a_source_build() -> None:
    ollama = _service_block(OLLAMA_ADDON_FILE, "ollama")

    assert "image: ollama/ollama:0.40.13" in ollama
    assert "build:" not in ollama
    assert "docker/ollama.Dockerfile" not in ollama


def test_ollama_gpu_addon_only_adds_nvidia_device_access() -> None:
    gpu_ollama = _service_block(OLLAMA_GPU_ADDON_FILE, "ollama")

    assert "driver: nvidia" in gpu_ollama
    assert "capabilities: [ gpu ]" in gpu_ollama
    for obsolete_setting in (
        "OLLAMA_NUM_GPU",
        "OLLAMA_GPU_LAYERS",
        "OLLAMA_LLM_LIBRARY",
        "OLLAMA_MMAP",
    ):
        assert obsolete_setting not in gpu_ollama


def test_ollama_lives_in_the_addon_not_the_base_compose() -> None:
    assert "ollama:" not in BASE_COMPOSE_FILE.read_text(encoding="utf-8")
