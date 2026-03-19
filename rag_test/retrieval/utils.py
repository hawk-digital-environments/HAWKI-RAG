from __future__ import annotations

import csv
import hashlib
import json
import logging
import math
import os
import random
import shutil
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Iterator

logger = logging.getLogger(__name__)

SUPPORTED_TEXT_EXTENSIONS = {
    ".md",
    ".markdown",
    ".txt",
    ".html",
    ".htm",
    ".json",
    ".csv",
    ".xml",
    ".yaml",
    ".yml",
}


@dataclass(slots=True)
class BenchmarkPaths:
    root_dir: Path
    data_test: Path
    results: Path
    logs: Path
    benchmark_queries: Path
    benchmark_gold: Path


@dataclass(slots=True)
class DocumentRecord:
    doc_id: str
    folder_name: str
    relative_path: str
    absolute_path: str
    text: str


@dataclass(slots=True)
class ChunkRecord:
    point_id: str
    doc_id: str
    folder_name: str
    relative_path: str
    chunk_index: int
    text: str


def project_root() -> Path:
    """Return the rag_test package root so scripts can build paths consistently."""
    root = Path(__file__).resolve().parents[1]
    logger.info("utils.project_root resolved root=%s", root)
    return root


def load_env_file(env_path: Path | None = None) -> None:
    """Load rag_test env overrides into the current process without overwriting existing env."""
    path = env_path or project_root() / ".env"
    logger.info("utils.load_env_file start path=%s", path)
    try:
        if not path.is_file():
            logger.info("utils.load_env_file skipped missing path=%s", path)
            return

        for line in path.read_text(encoding="utf-8").splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith("#") or "=" not in stripped:
                continue
            key, value = stripped.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip())
        logger.info("utils.load_env_file success path=%s", path)
    except Exception as exc:
        logger.exception("utils.load_env_file failed path=%s error=%s", path, exc)
        raise


def load_benchmark_config() -> dict[str, Any]:
    """Load the central PHP benchmark config and normalize all configured paths."""
    logger.info("utils.load_benchmark_config start")
    try:
        load_env_file()
        config_path = project_root() / "config" / "benchmark.php"
        command = [
            "php",
            "-r",
            f"$cfg = require {json.dumps(str(config_path))}; echo json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);",
        ]
        try:
            completed = subprocess.run(
                command,
                cwd=str(project_root()),
                check=True,
                capture_output=True,
                text=True,
            )
        except FileNotFoundError as exc:
            logger.exception("utils.load_benchmark_config missing php CLI error=%s", exc)
            raise RuntimeError("php CLI is required to load rag_test/config/benchmark.php") from exc
        except subprocess.CalledProcessError as exc:
            logger.exception("utils.load_benchmark_config php execution failed error=%s", exc)
            raise RuntimeError(f"Failed to load benchmark.php: {exc.stderr.strip()}") from exc

        payload = json.loads(completed.stdout)
        payload["paths"] = {
            key: str(Path(value).resolve()) for key, value in payload["paths"].items()
        }
        logger.info("utils.load_benchmark_config success models=%s", list(payload.get("models", {}).keys()))
        return payload
    except Exception as exc:
        logger.exception("utils.load_benchmark_config failed error=%s", exc)
        raise


def get_paths(config: dict[str, Any]) -> BenchmarkPaths:
    """Convert the raw config path dictionary into a typed helper object."""
    paths = config["paths"]
    return BenchmarkPaths(
        root_dir=Path(paths["root_dir"]),
        data_test=Path(paths["data_test"]),
        results=Path(paths["results"]),
        logs=Path(paths["logs"]),
        benchmark_queries=Path(paths["benchmark_queries"]),
        benchmark_gold=Path(paths["benchmark_gold"]),
    )


def utc_timestamp() -> str:
    """Return a UTC ISO timestamp for manifests, summaries, and logs."""
    return datetime.now(timezone.utc).isoformat()


def create_run_id(prefix: str = "run") -> str:
    """Build a deterministic-looking UTC run id prefix for one benchmark execution."""
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return f"{prefix}_{stamp}"


def ensure_dir(path: Path) -> Path:
    """Create a directory tree if needed and return the same path for chaining."""
    path.mkdir(parents=True, exist_ok=True)
    return path


def json_dump(path: Path, payload: Any) -> None:
    """Write JSON output files used by benchmark manifests and summaries."""
    logger.info("utils.json_dump start path=%s", path)
    try:
        ensure_dir(path.parent)
        path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        logger.info("utils.json_dump success path=%s", path)
    except Exception as exc:
        logger.exception("utils.json_dump failed path=%s error=%s", path, exc)
        raise


def csv_dump(path: Path, rows: list[dict[str, Any]]) -> None:
    """Write flat tabular benchmark rows to CSV with a union of all row keys."""
    logger.info("utils.csv_dump start path=%s rows=%s", path, len(rows))
    try:
        ensure_dir(path.parent)
        if not rows:
            path.write_text("", encoding="utf-8")
            logger.info("utils.csv_dump wrote empty file path=%s", path)
            return
        fieldnames = sorted({key for row in rows for key in row.keys()})
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(rows)
        logger.info("utils.csv_dump success path=%s", path)
    except Exception as exc:
        logger.exception("utils.csv_dump failed path=%s error=%s", path, exc)
        raise


def setup_logger(log_path: Path, name: str) -> logging.Logger:
    """Create a file+stdout logger for one script or benchmark phase."""
    ensure_dir(log_path.parent)
    logger = logging.getLogger(name)
    logger.setLevel(logging.INFO)
    logger.handlers.clear()
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s")

    file_handler = logging.FileHandler(log_path, encoding="utf-8")
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)

    stream_handler = logging.StreamHandler(sys.stdout)
    stream_handler.setFormatter(formatter)
    logger.addHandler(stream_handler)
    return logger


def stable_hash(text: str) -> str:
    """Generate stable ids for documents and chunks from relative-path-like inputs."""
    return hashlib.sha1(text.encode("utf-8")).hexdigest()


def cosine_similarity(a: list[float], b: list[float]) -> float:
    """Compute cosine similarity for embedding vectors used in offline graph tasks."""
    if not a or not b or len(a) != len(b):
        return 0.0
    numerator = sum(x * y for x, y in zip(a, b))
    denom_a = math.sqrt(sum(x * x for x in a))
    denom_b = math.sqrt(sum(y * y for y in b))
    if denom_a == 0.0 or denom_b == 0.0:
        return 0.0
    return numerator / (denom_a * denom_b)


def chunk_text(text: str, chunk_size: int, overlap: int) -> list[str]:
    """Split text into overlapping character windows for deterministic local chunking."""
    stripped = " ".join(text.split())
    if not stripped:
        return []
    if len(stripped) <= chunk_size:
        return [stripped]

    chunks: list[str] = []
    start = 0
    step = max(1, chunk_size - overlap)
    while start < len(stripped):
        end = min(len(stripped), start + chunk_size)
        chunks.append(stripped[start:end])
        if end == len(stripped):
            break
        start += step
    return chunks


def read_text_file(path: Path) -> str:
    """Read supported text-like files and normalize JSON files into string content."""
    suffix = path.suffix.lower()
    if suffix not in SUPPORTED_TEXT_EXTENSIONS:
        return ""

    try:
        raw = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return ""

    if suffix == ".json":
        try:
            payload = json.loads(raw)
            return json.dumps(payload, ensure_ascii=False)
        except json.JSONDecodeError:
            return raw
    return raw


def iter_folder_directories(source_root: Path) -> Iterator[Path]:
    """Yield only directory entries from a source corpus root."""
    for path in sorted(source_root.iterdir()):
        if path.is_dir():
            yield path


def collect_documents(
    data_root: Path,
    max_files_per_folder: int,
) -> list[DocumentRecord]:
    """Collect benchmarkable text documents from sampled folders under data_test."""
    logger.info("utils.collect_documents start data_root=%s max_files_per_folder=%s", data_root, max_files_per_folder)
    documents: list[DocumentRecord] = []
    try:
        for folder in iter_folder_directories(data_root):
            logger.info("utils.collect_documents folder_start folder=%s", folder)
            count = 0
            for file_path in sorted(folder.rglob("*")):
                if not file_path.is_file():
                    continue
                text = read_text_file(file_path)
                if not text.strip():
                    continue
                rel = file_path.relative_to(data_root)
                doc_id = stable_hash(str(rel))
                documents.append(
                    DocumentRecord(
                        doc_id=doc_id,
                        folder_name=folder.name,
                        relative_path=str(rel),
                        absolute_path=str(file_path),
                        text=text,
                    )
                )
                count += 1
                if count >= max_files_per_folder:
                    logger.info("utils.collect_documents folder_limit_reached folder=%s count=%s", folder, count)
                    break
        logger.info("utils.collect_documents success documents=%s", len(documents))
        return documents
    except Exception as exc:
        logger.exception("utils.collect_documents failed data_root=%s error=%s", data_root, exc)
        raise


def build_chunks(
    documents: Iterable[DocumentRecord],
    chunk_size: int,
    chunk_overlap: int,
) -> list[ChunkRecord]:
    """Derive local chunk records from collected documents when chunk-level views are needed."""
    chunks: list[ChunkRecord] = []
    for document in documents:
        for chunk_index, piece in enumerate(chunk_text(document.text, chunk_size, chunk_overlap)):
            point_id = stable_hash(f"{document.doc_id}:{chunk_index}")
            chunks.append(
                ChunkRecord(
                    point_id=point_id,
                    doc_id=document.doc_id,
                    folder_name=document.folder_name,
                    relative_path=document.relative_path,
                    chunk_index=chunk_index,
                    text=piece,
                )
            )
    return chunks


def batched(items: list[Any], size: int) -> Iterator[list[Any]]:
    """Yield fixed-size batches for embedding or indexing operations."""
    for index in range(0, len(items), size):
        yield items[index:index + size]


def load_query_groups(query_dir: Path) -> list[dict[str, Any]]:
    """Load all benchmark query JSON files into one flat query list."""
    logger.info("utils.load_query_groups start query_dir=%s", query_dir)
    queries: list[dict[str, Any]] = []
    try:
        for path in sorted(query_dir.glob("*.json")):
            payload = json.loads(path.read_text(encoding="utf-8"))
            for item in payload.get("queries", []):
                cloned = dict(item)
                cloned.setdefault("group", path.stem)
                queries.append(cloned)
        logger.info("utils.load_query_groups success queries=%s", len(queries))
        return queries
    except Exception as exc:
        logger.exception("utils.load_query_groups failed query_dir=%s error=%s", query_dir, exc)
        raise


def load_gold_map(path: Path, key: str) -> dict[str, dict[str, Any]]:
    """Load gold annotations and index them by a caller-supplied key field."""
    logger.info("utils.load_gold_map start path=%s key=%s", path, key)
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        result = {item[key]: item for item in payload.get("cases", payload.get("queries", []))}
        logger.info("utils.load_gold_map success path=%s entries=%s", path, len(result))
        return result
    except Exception as exc:
        logger.exception("utils.load_gold_map failed path=%s error=%s", path, exc)
        raise


def resolve_collection_name(config: dict[str, Any], model_key: str) -> str:
    """Map a model key to its dedicated Qdrant collection name."""
    model = config["models"][model_key]
    prefix = config["collections"]["prefix"]
    suffix = model["collection_suffix"]
    return f"{prefix}_{suffix}"


def validate_fairness_rules(config: dict[str, Any]) -> list[str]:
    """Validate the benchmark's apples-to-apples rules before any run starts."""
    logger.info("utils.validate_fairness_rules start")
    errors: list[str] = []
    if not config.get("reranker", {}).get("enabled", False):
        errors.append("Reranker must remain enabled in config.")

    suffixes: dict[str, str] = {}
    for model_key, model in config["models"].items():
        if not model.get("enabled", False):
            continue
        suffix = model["collection_suffix"]
        if suffix in suffixes:
            errors.append(
                f"Collection suffix collision: {suffix!r} is shared by {suffixes[suffix]} and {model_key}."
            )
        suffixes[suffix] = model_key

        if model.get("embedding_mode") != "aligned":
            errors.append(f"Model {model_key} must use embedding_mode=aligned.")

        if not model.get("model_name"):
            errors.append(f"Model {model_key} is missing model_name.")

    logger.info("utils.validate_fairness_rules complete errors=%s", len(errors))
    return errors


def save_config_snapshot(config: dict[str, Any], run_dir: Path) -> None:
    """Persist a redacted config snapshot next to one run's results."""
    snapshot = json.loads(json.dumps(config))
    if "neo4j" in snapshot:
        snapshot["neo4j"]["password"] = "***"
    if "qdrant" in snapshot:
        snapshot["qdrant"]["api_key"] = "***" if snapshot["qdrant"].get("api_key") else ""
    for model in snapshot.get("models", {}).values():
        if model.get("api_key"):
            model["api_key"] = "***"
    if snapshot.get("reranker", {}).get("api_key"):
        snapshot["reranker"]["api_key"] = "***"
    json_dump(run_dir / "config_snapshot.json", snapshot)


def copy_tree(source: Path, destination: Path) -> None:
    """Replace a destination directory with a fresh copy of the chosen source folder."""
    if destination.exists():
        shutil.rmtree(destination)
    shutil.copytree(source, destination)


def seeded_random(seed: int) -> random.Random:
    """Return a dedicated RNG so sampling stays reproducible across runs."""
    return random.Random(seed)
