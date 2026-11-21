"""Export and patch the pinned crawler without changing its external checkout."""

import argparse
import hashlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
REVISION = "1c327244fed3b6f99ad08ad9203814942bc10f26"


def prepare(repository: Path) -> Path:
    """Produce an ignored, reproducible build context from a local Git object."""
    archive = subprocess.run(
        ["git", "-C", str(repository), "archive", REVISION],
        capture_output=True,
        check=True,
    ).stdout
    patch = ROOT / "docker/crawler/permanent-job-v2.patch"
    digest = hashlib.sha256(patch.read_bytes()).hexdigest()
    tests = ROOT / "docker/crawler/tests/test_permanent_job_v2.py"
    contracts = ROOT / "python_rag/packages/contracts/src"
    consumer_bytes = b"".join(
        path.read_bytes() for path in sorted(contracts.rglob("*.py"))
    )
    context_digest = hashlib.sha256(
        patch.read_bytes() + tests.read_bytes() + consumer_bytes
    ).hexdigest()
    directory = ROOT / ".local-runtime"
    directory.mkdir(exist_ok=True)
    destination = directory / f"crawler-{REVISION[:12]}-{context_digest[:12]}"
    if not destination.exists():
        with tempfile.TemporaryDirectory(
            dir=directory, prefix="crawler-export-"
        ) as temporary:
            export = Path(temporary)
            with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
                bundle.extractall(export, filter="data")
            arguments = [
                "git",
                "apply",
                "--directory",
                str(export.relative_to(ROOT)),
                str(patch),
            ]
            subprocess.run(
                arguments[:2] + ["--check"] + arguments[2:], cwd=ROOT, check=True
            )
            subprocess.run(arguments, cwd=ROOT, check=True)
            shutil.copyfile(tests, export / "tests/test_permanent_job_v2.py")
            shutil.copytree(
                contracts,
                export / "consumer_contracts",
                ignore=shutil.ignore_patterns("__pycache__", "*.pyc"),
            )
            (export / "RAWKI_SOURCE.json").write_text(
                json.dumps({"revision": REVISION, "patch_sha256": digest}) + "\n",
                encoding="utf-8",
            )
            export.rename(destination)
    link = directory / "crawler"
    if link.exists() and not link.is_symlink():
        raise ValueError("The build context path must be an owned symbolic link")
    link.unlink(missing_ok=True)
    link.symlink_to(destination.name, target_is_directory=True)
    return link


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "repository", type=Path, help="Existing local CustomCrawler checkout"
    )
    context = prepare(parser.parse_args().repository.resolve())
    print(f"Pinned local crawler context ready: {context.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
