from __future__ import annotations

import argparse
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from rag_test.retrieval.utils import (
    copy_tree,
    ensure_dir,
    iter_folder_directories,
    json_dump,
    load_benchmark_config,
    seeded_random,
    setup_logger,
    utc_timestamp,
)


def build_parser() -> argparse.ArgumentParser:
    """Define CLI arguments for reproducible corpus sampling into data_test."""
    parser = argparse.ArgumentParser(description="Copy a reproducible random subset of source folders into rag_test/data_test.")
    parser.add_argument("--source-path", help="Override source Docker volume path from config.")
    parser.add_argument("--count", type=int, help="Override number of folders to copy.")
    parser.add_argument("--seed", type=int, help="Override random seed.")
    parser.add_argument("--manifest-name", default="copied_manifest.json", help="Manifest filename to write into data_test.")
    return parser


def main() -> int:
    """Sample folders from the source corpus and materialize a benchmark subset."""
    log_path = ROOT / "rag_test" / "logs" / "prepare_test_data.log"
    logger = setup_logger(log_path, "rag_test.prepare")
    logger.info("prepare_test_data.main start")
    try:
        args = build_parser().parse_args()
        config = load_benchmark_config()
        source_root = Path(args.source_path or config["benchmark"]["source_volume_path"]).expanduser()
        data_test = Path(config["paths"]["data_test"])

        count = args.count or int(config["benchmark"]["random_folder_count"])
        seed = args.seed or int(config["benchmark"]["random_seed"])
        ensure_dir(data_test)
        logger.info("prepare_test_data.main resolved source_root=%s count=%s seed=%s", source_root, count, seed)

        if not source_root.is_dir():
            logger.error("Source path does not exist or is not a directory: %s", source_root)
            return 1

        folders = [path for path in iter_folder_directories(source_root)]
        if not folders:
            logger.error("No folders found under source path: %s", source_root)
            return 1

        rng = seeded_random(seed)
        sample_size = min(count, len(folders))
        chosen = rng.sample(folders, sample_size)
        logger.info("prepare_test_data.main selected_folders=%s available_folders=%s", sample_size, len(folders))

        copied: list[dict[str, str]] = []
        skipped: list[dict[str, str]] = []
        for folder in chosen:
            destination = data_test / folder.name
            try:
                copy_tree(folder, destination)
                copied.append(
                    {
                        "folder_name": folder.name,
                        "source_path": str(folder),
                        "destination_path": str(destination),
                    }
                )
                logger.info("Copied %s -> %s", folder, destination)
            except Exception as exc:
                skipped.append({"folder_name": folder.name, "reason": str(exc)})
                logger.warning("Skipping broken folder %s: %s", folder, exc)

        manifest = {
            "generated_at": utc_timestamp(),
            "source_root": str(source_root),
            "requested_count": count,
            "copied_count": len(copied),
            "skipped_count": len(skipped),
            "seed": seed,
            "folders": copied,
            "skipped": skipped,
        }
        json_dump(data_test / args.manifest_name, manifest)
        logger.info("prepare_test_data.main success manifest=%s", data_test / args.manifest_name)
        return 0
    except Exception as exc:
        logger.exception("prepare_test_data.main failed error=%s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
