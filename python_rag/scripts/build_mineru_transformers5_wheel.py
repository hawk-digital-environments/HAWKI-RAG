"""Build RAWKI's guarded MinerU 3.4.4 compatibility wheel.

MinerU 3.4.4 is the release required by RAG-Anything 1.3.1, but its upstream
wheel constrains Transformers to version 4. The latest MinerU prerelease keeps
the same constraint and is not compatible with RAG-Anything's parser API.

This builder accepts only the known upstream MinerU wheel, verifies its SHA-256
digest, applies the layout and formula-model Transformers 5 compatibility
changes exercised by RAWKI's pipeline-parser test, and emits a PEP 440
local-version wheel. The local
``core`` extra is deliberately narrowed to the pipeline extra used by RAWKI;
the unvalidated VLM and Gradio extras remain opt-in and are not advertised as
Transformers 5 compatible. No third-party wheel is stored in this repository.
"""

from __future__ import annotations

import argparse
import base64
import csv
from dataclasses import dataclass
from hashlib import sha256
import io
from pathlib import Path
import tempfile
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


UPSTREAM_WHEEL_NAME = "mineru-3.4.4-py3-none-any.whl"
UPSTREAM_WHEEL_SHA256 = "d4d678539782a7683d998e2914a52d96b5720676ce65658b29666b1f4d9dfd13"
PATCHED_WHEEL_NAME = "mineru-3.4.4+rawki.1-py3-none-any.whl"
UPSTREAM_DIST_INFO = "mineru-3.4.4.dist-info"
PATCHED_DIST_INFO = "mineru-3.4.4+rawki.1.dist-info"
SOURCE_PATH = Path("mineru/model/layout/pp_doclayoutv2.py")
UNIMER_SOURCE_PATH = Path(
    "mineru/model/mfr/unimernet/unimernet_hf/unimer_swin/modeling_unimer_swin.py"
)
VERSION_MODULE_PATH = Path("mineru/version.py")


@dataclass(frozen=True)
class TextPatch:
    """One exact, reviewable source transformation."""

    label: str
    before: str
    after: str


SOURCE_PATCHES = (
    TextPatch(
        label="initialize reading-order config before Transformers validates it",
        before=r'''        super().__init__(
            backbone_config=backbone_config,
            class_thresholds=class_thresholds or list(DEFAULT_CLASS_THRESHOLDS),
            class_order=class_order or list(DEFAULT_CLASS_ORDER),
            **kwargs,
        )
        self.class_thresholds = list(class_thresholds or DEFAULT_CLASS_THRESHOLDS)
        self.class_order = list(class_order or DEFAULT_CLASS_ORDER)
        self.reading_order_config = reading_order
''',
        after=r'''        self.reading_order_config = reading_order
        super().__init__(
            backbone_config=backbone_config,
            class_thresholds=class_thresholds or list(DEFAULT_CLASS_THRESHOLDS),
            class_order=class_order or list(DEFAULT_CLASS_ORDER),
            **kwargs,
        )
        self.class_thresholds = list(class_thresholds or DEFAULT_CLASS_THRESHOLDS)
        self.class_order = list(class_order or DEFAULT_CLASS_ORDER)
''',
    ),
    TextPatch(
        label="retain RT-DETR prediction heads after their Transformers 5 move",
        before=r'''        super().__init__(config)
        self.model = PPDocLayoutV2Model(config)
''',
        after=r'''        super().__init__(config)
        if not hasattr(self, "class_embed"):
            self.class_embed = self.model.decoder.class_embed
        if not hasattr(self, "bbox_embed"):
            self.bbox_embed = self.model.decoder.bbox_embed
        self.model = PPDocLayoutV2Model(config)
''',
    ),
    TextPatch(
        label="map the renamed RT-DETR encoder checkpoint weights",
        before=r'''        self.model = PPDocLayoutV2ForObjectDetection.from_pretrained(self.model_dir, config=self.config)
''',
        after=r'''        self.model = PPDocLayoutV2ForObjectDetection.from_pretrained(
            self.model_dir,
            config=self.config,
            key_mapping={r"model\.encoder\.encoder": "model.encoder.aifi"},
        )
''',
    ),
)

UNIMER_SOURCE_PATCHES = (
    TextPatch(
        label="vendor the pruning-index helper removed from Transformers 5",
        before=(
            "from transformers.pytorch_utils import "
            "find_pruneable_heads_and_indices, meshgrid, prune_linear_layer\n"
        ),
        after="from transformers.pytorch_utils import meshgrid, prune_linear_layer\n",
    ),
    TextPatch(
        label="define the removed pruning-index helper for UniMERNet",
        before='logger = logging.get_logger(__name__)\n\n# General docstring\n',
        after=r'''logger = logging.get_logger(__name__)


def find_pruneable_heads_and_indices(
    heads: list[int], n_heads: int, head_size: int, already_pruned_heads: set[int]
) -> tuple[set[int], torch.LongTensor]:
    """Return attention heads to prune and flattened indices to retain."""

    mask = torch.ones(n_heads, head_size)
    heads = set(heads) - already_pruned_heads
    for head in heads:
        head -= sum(1 if pruned_head < head else 0 for pruned_head in already_pruned_heads)
        mask[head] = 0
    mask = mask.view(-1).contiguous().eq(1)
    index: torch.LongTensor = torch.arange(len(mask))[mask].long()
    return heads, index


# General docstring
''',
    ),
)

VERSION_PATCH = TextPatch(
    label="mark the compatibility build with a PEP 440 local version",
    before="Version: 3.4.4\n",
    after="Version: 3.4.4+rawki.1\n",
)
VERSION_MODULE_PATCH = TextPatch(
    label="expose the compatibility build through MinerU's runtime version",
    before='__version__ = "3.4.4"',
    after='__version__ = "3.4.4+rawki.1"',
)
TRANSFORMERS_REQUIREMENT_PATCH = TextPatch(
    label="declare the exact Transformers release validated for the pipeline extra",
    before='Requires-Dist: transformers<5.0.0,>=4.57.3; extra == "pipeline"',
    after='Requires-Dist: transformers==5.14.1; extra == "pipeline"',
)
CORE_VLM_REQUIREMENT_PATCH = TextPatch(
    label="remove the unvalidated VLM backend from RAWKI's local core extra",
    before='Requires-Dist: mineru[vlm]; extra == "core"\n',
    after="",
)
CORE_GRADIO_REQUIREMENT_PATCH = TextPatch(
    label="remove the unused Gradio UI from RAWKI's local core extra",
    before='Requires-Dist: mineru[gradio]; extra == "core"\n',
    after="",
)


def _apply_exact_patch(content: str, patch: TextPatch, *, expected_count: int = 1) -> str:
    actual_count = content.count(patch.before)
    if actual_count != expected_count:
        raise RuntimeError(
            f"Cannot {patch.label}: expected {expected_count} exact match(es), "
            f"found {actual_count}. Refusing to patch an unknown artifact."
        )
    return content.replace(patch.before, patch.after)


def patch_source(content: str) -> str:
    """Apply the guarded MinerU source transformations."""

    for patch in SOURCE_PATCHES:
        content = _apply_exact_patch(content, patch)
    return content


def patch_unimer_source(content: str) -> str:
    """Restore the one Transformers 4 helper UniMERNet still consumes."""

    for patch in UNIMER_SOURCE_PATCHES:
        content = _apply_exact_patch(content, patch)
    return content


def patch_metadata(content: str) -> str:
    """Set local provenance and expose only the validated pipeline dependency set."""

    content = _apply_exact_patch(content, VERSION_PATCH)
    content = _apply_exact_patch(content, TRANSFORMERS_REQUIREMENT_PATCH)
    content = _apply_exact_patch(content, CORE_VLM_REQUIREMENT_PATCH)
    return _apply_exact_patch(content, CORE_GRADIO_REQUIREMENT_PATCH)


def _record_row(relative_path: str, data: bytes) -> list[str]:
    digest = base64.urlsafe_b64encode(sha256(data).digest()).rstrip(b"=").decode("ascii")
    return [relative_path, f"sha256={digest}", str(len(data))]


def _write_record(root: Path) -> None:
    record_path = root / PATCHED_DIST_INFO / "RECORD"
    record_relative_path = record_path.relative_to(root).as_posix()
    rows: list[list[str]] = []

    for path in sorted(candidate for candidate in root.rglob("*") if candidate.is_file()):
        relative_path = path.relative_to(root).as_posix()
        if relative_path == record_relative_path:
            continue
        rows.append(_record_row(relative_path, path.read_bytes()))

    rows.append([record_relative_path, "", ""])
    output = io.StringIO(newline="")
    writer = csv.writer(output, lineterminator="\n")
    writer.writerows(rows)
    record_path.write_text(output.getvalue(), encoding="utf-8")


def _write_deterministic_wheel(root: Path, destination: Path) -> None:
    temporary_destination = destination.with_suffix(".whl.tmp")
    with ZipFile(temporary_destination, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(candidate for candidate in root.rglob("*") if candidate.is_file()):
            relative_path = path.relative_to(root).as_posix()
            info = ZipInfo(relative_path, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    temporary_destination.replace(destination)


def build_compatibility_wheel(upstream_wheel: Path, output_directory: Path) -> Path:
    """Verify, patch, and repack the supported upstream MinerU wheel."""

    upstream_wheel = upstream_wheel.resolve()
    if upstream_wheel.name != UPSTREAM_WHEEL_NAME:
        raise RuntimeError(
            f"Expected {UPSTREAM_WHEEL_NAME}, received {upstream_wheel.name}."
        )

    actual_digest = sha256(upstream_wheel.read_bytes()).hexdigest()
    if actual_digest != UPSTREAM_WHEEL_SHA256:
        raise RuntimeError(
            "MinerU wheel SHA-256 mismatch: "
            f"expected {UPSTREAM_WHEEL_SHA256}, received {actual_digest}."
        )

    output_directory.mkdir(parents=True, exist_ok=True)
    destination = output_directory.resolve() / PATCHED_WHEEL_NAME

    with tempfile.TemporaryDirectory(prefix="rawki-mineru-wheel-") as temporary_directory:
        root = Path(temporary_directory)
        with ZipFile(upstream_wheel) as archive:
            archive.extractall(root)

        upstream_dist_info = root / UPSTREAM_DIST_INFO
        patched_dist_info = root / PATCHED_DIST_INFO
        source_path = root / SOURCE_PATH
        unimer_source_path = root / UNIMER_SOURCE_PATH
        version_module_path = root / VERSION_MODULE_PATH
        metadata_path = upstream_dist_info / "METADATA"

        expected_files = (source_path, unimer_source_path, version_module_path, metadata_path)
        if not all(path.is_file() for path in expected_files):
            raise RuntimeError("The verified wheel does not contain the expected MinerU files.")
        if patched_dist_info.exists():
            raise RuntimeError(f"Unexpected pre-existing path: {patched_dist_info.name}")

        patched_source = patch_source(source_path.read_text(encoding="utf-8"))
        patched_unimer_source = patch_unimer_source(
            unimer_source_path.read_text(encoding="utf-8")
        )
        patched_version_module = _apply_exact_patch(
            version_module_path.read_text(encoding="utf-8"),
            VERSION_MODULE_PATCH,
        )
        patched_metadata = patch_metadata(metadata_path.read_text(encoding="utf-8"))
        source_path.write_text(patched_source, encoding="utf-8")
        unimer_source_path.write_text(patched_unimer_source, encoding="utf-8")
        version_module_path.write_text(patched_version_module, encoding="utf-8")
        metadata_path.write_text(patched_metadata, encoding="utf-8")
        upstream_dist_info.rename(patched_dist_info)

        _write_record(root)
        _write_deterministic_wheel(root, destination)

    return destination


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("upstream_wheel", type=Path)
    parser.add_argument("output_directory", type=Path)
    arguments = parser.parse_args()

    destination = build_compatibility_wheel(
        arguments.upstream_wheel,
        arguments.output_directory,
    )
    print(destination)


if __name__ == "__main__":
    main()
