from __future__ import annotations

import argparse
from pathlib import Path
import sys
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.backend_runtime import BackendRuntime
from rag_test.retrieval.qdrant_client_wrapper import QdrantClientWrapper
from rag_test.retrieval.utils import (
    collect_documents,
    json_dump,
    load_benchmark_config,
    resolve_collection_name,
    setup_logger,
    utc_timestamp,
    validate_fairness_rules,
)


def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for collection building over one or more models."""
    parser = argparse.ArgumentParser(description="Build one Qdrant collection per embedding model using the current python_rag ingest pipeline.")
    parser.add_argument("--models", nargs="*", help="Optional subset of model keys to build.")
    return parser


def main() -> int:
    """Build clean per-model collections by reusing the current backend ingest flow."""
    bootstrap_logger = setup_logger(ROOT / "rag_test" / "logs" / "build_qdrant_collections.log", "rag_test.build.bootstrap")
    bootstrap_logger.info("build_qdrant_collections.main start")
    try:
        args = build_parser().parse_args()
        config = load_benchmark_config()
        errors = validate_fairness_rules(config)
        if errors:
            for error in errors:
                print(f"FAIRNESS ERROR: {error}")
            return 1

        logger = setup_logger(Path(config["paths"]["logs"]) / "build_qdrant_collections.log", "rag_test.build")
        logger.info("build_qdrant_collections.main config_loaded")
        runtime = BackendRuntime(config)
        documents = collect_documents(
            Path(config["paths"]["data_test"]),
            max_files_per_folder=int(config["benchmark"]["max_files_per_folder"]),
        )
        if not documents:
            logger.error("No documents found under %s", config["paths"]["data_test"])
            return 1
        logger.info("build_qdrant_collections.main documents_loaded count=%s", len(documents))

        ingest_docs = [
            SimpleNamespace(
                id=document.doc_id,
                text=document.text,
                payload={
                    "doc_id": document.doc_id,
                    "folder_name": document.folder_name,
                    "relative_path": document.relative_path,
                    "title": Path(document.relative_path).name,
                    "source_format": Path(document.relative_path).suffix.lstrip(".") or "text",
                },
            )
            for document in documents
        ]

        qdrant = QdrantClientWrapper(
            base_url=config["qdrant"]["url"],
            api_key=config["qdrant"].get("api_key", ""),
            timeout_seconds=int(config["qdrant"]["timeout_seconds"]),
        )
        selected_models = args.models or [
            key for key, model in config["models"].items() if model.get("enabled", False)
        ]
        logger.info("build_qdrant_collections.main selected_models=%s", selected_models)
        build_summary: list[dict[str, object]] = []

        for model_key in selected_models:
            logger.info("build_qdrant_collections.main model_start model_key=%s", model_key)
            try:
                collection_name = resolve_collection_name(config, model_key)
                qdrant.delete_collection(collection_name, ignore_missing=True)
                result = runtime.ingest_docs(
                    docs=ingest_docs,
                    model_key=model_key,
                    collection_name=collection_name,
                    distance=config["collections"]["distance"],
                    graph=False,
                    graph_only=False,
                )
                collection_count = qdrant.collection_count(collection_name)
                build_summary.append(
                    {
                        "generated_at": utc_timestamp(),
                        "model_key": model_key,
                        "collection_name": collection_name,
                        "document_count": len(documents),
                        "chunk_count": int(result.get("summary", {}).get("total_chunks", 0)),
                        "collection_count": collection_count,
                        "backend_summary": result.get("summary", {}),
                    }
                )
                logger.info(
                    "build_qdrant_collections.main model_success model_key=%s collection=%s chunks=%s count=%s",
                    model_key,
                    collection_name,
                    int(result.get("summary", {}).get("total_chunks", 0)),
                    collection_count,
                )
            except Exception as exc:
                logger.exception("build_qdrant_collections.main model_failed model_key=%s error=%s", model_key, exc)
                raise

        manifest = {
            "generated_at": utc_timestamp(),
            "documents": [
                {
                    "doc_id": document.doc_id,
                    "folder_name": document.folder_name,
                    "relative_path": document.relative_path,
                }
                for document in documents
            ],
            "collections": build_summary,
        }
        json_dump(Path(config["paths"]["logs"]) / "collection_build_manifest.json", manifest)
        logger.info("build_qdrant_collections.main success")
        return 0
    except Exception as exc:
        bootstrap_logger.exception("build_qdrant_collections.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
