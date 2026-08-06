"""Compose contracts for the pinned Ollama image and GPU acceleration."""

from __future__ import annotations

from pathlib import Path
import re


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
BASE_COMPOSE_FILE = REPOSITORY_ROOT / "docker-compose.yml"
GPU_COMPOSE_FILE = REPOSITORY_ROOT / "docker-compose-gpu-override.yml"


def _service_block(compose_file: Path, service_name: str) -> str:
    compose = compose_file.read_text(encoding="utf-8")
    match = re.search(
        rf"(?ms)^  {re.escape(service_name)}:\n"
        r"(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:\n|^networks:\n|\Z)",
        compose,
    )
    assert match is not None, service_name

    return match.group("body")


def test_gpu_ollama_uses_the_pinned_official_image_without_a_source_build() -> None:
    base_ollama = _service_block(BASE_COMPOSE_FILE, "ollama")
    gpu_ollama = _service_block(GPU_COMPOSE_FILE, "ollama")

    assert "image: ollama/ollama:0.32.1" in base_ollama
    assert "build:" not in gpu_ollama
    assert "image:" not in gpu_ollama
    assert "pull_policy:" not in gpu_ollama
    assert "docker/ollama.Dockerfile" not in gpu_ollama


def test_gpu_ollama_only_adds_nvidia_device_access() -> None:
    gpu_ollama = _service_block(GPU_COMPOSE_FILE, "ollama")

    assert "driver: nvidia" in gpu_ollama
    assert "capabilities: [ gpu ]" in gpu_ollama
    for obsolete_setting in (
        "OLLAMA_NUM_GPU",
        "OLLAMA_GPU_LAYERS",
        "OLLAMA_LLM_LIBRARY",
        "OLLAMA_MMAP",
    ):
        assert obsolete_setting not in gpu_ollama
