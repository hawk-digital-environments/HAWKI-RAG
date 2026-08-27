from __future__ import annotations

import ast
import asyncio
from contextlib import nullcontext
from pathlib import Path
from types import SimpleNamespace

import tomllib

from hawki_rag_contracts.pipeline.status import PipelineStageStatus
from hawki_rag_contracts.pipeline.temporal import INDEX_MARKDOWN_ACTIVITY
from hawki_indexer_worker.adapters import status_callback
from hawki_indexer_worker import main as indexer_main
from hawki_indexer_worker.settings import IndexerSettings

PYTHON_RAG = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
SERVICE = PYTHON_RAG / "services" / "hawki_indexer_worker"
SOURCE = SERVICE / "src" / "hawki_indexer_worker"


def _imports(path: Path) -> list[tuple[int, str]]:
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    imports: list[tuple[int, str]] = []
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.extend((node.lineno, alias.name) for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module:
            imports.append((node.lineno, node.module))
    return imports


def test_production_imports_never_cross_legacy_or_service_boundaries() -> None:
    forbidden = {
        "api",
        "application",
        "common",
        "domain",
        "infrastructure",
        "services",
        "temporal_rag",
        "fastapi",
        "psycopg",
    }
    violations: list[str] = []
    for path in sorted(SOURCE.rglob("*.py")):
        source = path.read_text(encoding="utf-8")
        tree = ast.parse(source, filename=str(path))
        for node in ast.walk(tree):
            names: list[str] = []
            if isinstance(node, ast.Import):
                names = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                names = [node.module]
            for name in names:
                if name.split(".", 1)[0] in forbidden:
                    violations.append(f"{path}:{node.lineno}: {name}")
        assert "POST /ingest" not in source
        assert "api.main" not in source
        assert "bridge_url" not in source
    assert violations == []


def test_indexing_application_depends_on_ports_not_store_packages() -> None:
    forbidden_roots = {"hawki_vector_store", "hawki_graph_store"}
    violations: list[str] = []
    for layer in (SOURCE / "indexing", SOURCE / "domain"):
        for path in sorted(layer.rglob("*.py")):
            for line, name in _imports(path):
                if name.split(".", 1)[0] in forbidden_roots:
                    violations.append(f"{path}:{line}: {name}")
    assert violations == []


def test_temporal_activity_module_contains_only_transport_wrappers() -> None:
    activity_module = SOURCE / "activities" / "index.py"
    tree = ast.parse(
        activity_module.read_text(encoding="utf-8"),
        filename=str(activity_module),
    )
    functions = {node.name for node in tree.body if isinstance(node, ast.FunctionDef)}

    assert functions == {"ingest_markdown_files", "mark_source_ready"}
    assert len(activity_module.read_text(encoding="utf-8").splitlines()) < 100

    temporal_imports = []
    for path in sorted((SOURCE / "application").rglob("*.py")):
        for line, name in _imports(path):
            if name.split(".", 1)[0] == "temporalio":
                temporal_imports.append(f"{path}:{line}: {name}")
    assert temporal_imports == []


def test_indexer_store_packages_are_visible_only_to_database_adapters() -> None:
    allowed = {
        SOURCE / "adapters" / "qdrant_writer.py": {"hawki_vector_store"},
        SOURCE / "adapters" / "neo4j_writer.py": {"hawki_graph_store", "neo4j"},
        SOURCE / "adapters" / "neo4j_cleanup.py": {"neo4j"},
        SOURCE / "indexing" / "graph_cleanup.py": {"neo4j"},
    }
    violations: list[str] = []
    for path in sorted(SOURCE.rglob("*.py")):
        for line, name in _imports(path):
            root = name.split(".", 1)[0]
            if root not in {"hawki_vector_store", "hawki_graph_store", "neo4j"}:
                continue
            if root not in allowed.get(path, set()):
                violations.append(f"{path}:{line}: {name}")
    assert violations == []


def test_all_indexer_source_and_tests_are_below_600_lines() -> None:
    paths = list(SOURCE.rglob("*.py")) + list((SERVICE / "tests").rglob("*.py"))
    oversized = {
        str(path.relative_to(PYTHON_RAG)): len(
            path.read_text(encoding="utf-8").splitlines()
        )
        for path in paths
        if len(path.read_text(encoding="utf-8").splitlines()) >= 600
    }
    assert oversized == {}


def test_manifest_has_exact_versions_and_cpu_cuda_variants() -> None:
    manifest = tomllib.loads((SERVICE / "pyproject.toml").read_text(encoding="utf-8"))
    project = manifest["project"]
    assert project["requires-python"] == "==3.13.14"
    dependencies = project["dependencies"]
    assert "av==13.1.0" in dependencies
    assert "numpy==2.5.1" in dependencies
    assert "raganything[all]==1.3.1" in dependencies
    assert "temporalio==1.30.0" in dependencies
    assert all("==" in dependency for dependency in dependencies)
    assert manifest["tool"]["uv"]["sources"]["lightrag-hku"] == {
        "git": "https://github.com/HKUDS/LightRAG.git",
        "rev": "c5bf73dbf6139f1b03f738a2fec4e47d5e66f3ab",
    }
    optional = project["optional-dependencies"]
    assert optional["cpu"] == ["torch==2.13.0", "torchvision==0.28.0"]
    assert optional["gpu"] == ["torch==2.13.0", "torchvision==0.28.0"]
    assert manifest["build-system"]["requires"] == ["uv_build==0.11.26"]


def test_status_callback_builds_indexer_event_and_redacts_secrets(monkeypatch) -> None:
    sent = []

    class RecordingClient:
        def __init__(self, settings) -> None:
            self.settings = settings

        def __enter__(self):
            return self

        def __exit__(self, *_args):
            return None

        def send(self, event):
            sent.append(event)
            return {"accepted": True}

    monkeypatch.setattr(status_callback, "LaravelCallbackClient", RecordingClient)
    settings = IndexerSettings(
        temporal_address="temporal:7233",
        temporal_namespace="default",
        task_queue="rag-ingestion-task-queue",
        activity_worker_threads=1,
        callback_url="http://laravel.test/internal/events",
        callback_secret="secret",
        callback_timeout_seconds=2.0,
        callback_retry_attempts=2,
        rag_working_dir=Path("/tmp/rag"),
    )
    status_callback.report_status(
        settings,
        {"source_id": "source-1", "job_id": "job-1"},
        activity_id=INDEX_MARKDOWN_ACTIVITY,
        status=PipelineStageStatus.FAILED,
        error=RuntimeError("Authorization: Bearer do-not-persist-token"),
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=2
        ),
    )
    event = sent[0]
    assert event.producer.value == "indexer"
    assert event.activity_id == "ingest_markdown_files"
    assert event.attempt == 2
    assert "do-not-persist" not in (event.error_details or "")
    assert "<redacted>" in (event.error_details or "")

    status_callback.report_status(
        settings,
        {"source_id": "source-1", "job_id": "job-1"},
        activity_id=INDEX_MARKDOWN_ACTIVITY,
        status=PipelineStageStatus.FAILED,
        error=RuntimeError("x" * 3000),
        activity_info=SimpleNamespace(
            workflow_id="workflow-1", workflow_run_id="run-1", attempt=3
        ),
    )
    assert len(sent[1].error_details or "") == 2048
    assert len(sent[1].errors[0].message) == 2048


def test_worker_polls_new_and_legacy_queues_during_cutover(monkeypatch) -> None:
    settings = SimpleNamespace(
        temporal_address="temporal:7233",
        temporal_namespace="default",
        task_queue="rag-indexer-task-queue",
        legacy_task_queue="rag-ingestion-task-queue",
        activity_worker_threads=1,
    )
    queues: list[str] = []

    class FakeClient:
        @staticmethod
        async def connect(*_args, **_kwargs):
            return object()

    class FakeWorker:
        def __init__(self, _client, *, task_queue, **_kwargs) -> None:
            queues.append(task_queue)

        async def run(self) -> None:
            return None

    monkeypatch.setattr(indexer_main.IndexerSettings, "from_env", lambda: settings)
    monkeypatch.setattr(indexer_main, "Client", FakeClient)
    monkeypatch.setattr(indexer_main, "Worker", FakeWorker)
    monkeypatch.setattr(
        indexer_main,
        "create_activity_executor",
        lambda _threads: nullcontext(object()),
    )
    monkeypatch.setattr(indexer_main, "configure_logging", lambda _role: None)

    asyncio.run(indexer_main.serve())

    assert queues == ["rag-indexer-task-queue", "rag-ingestion-task-queue"]
