from __future__ import annotations

import os
from pathlib import Path


def discover_page_dirs(root: Path) -> list[Path]:
    """Return folders that look like a page/document ingest unit.

    Policy:
      - Treat `converted_*` directories with `conversion_meta.json` as document units.
      - Skip children of those converted trees to avoid duplicate ingestion.
      - Count a directory as ingestable when it has non-conversion JSON metadata,
        an eligible markdown source (`content.md` or `converted.md`, but not `*_converted.md`),
        or a `.txt` file for legacy JSON-text fallback detection.
    """
    out: list[Path] = []
    for dp, dn, fn in os.walk(root):
        p = Path(dp)
        # Converted output folders are their own document units when they have
        # conversion metadata; skip their children to avoid duplicate ingestion.
        try:
            relative_parts = p.relative_to(root).parts
        except ValueError:
            relative_parts = p.parts
        parts = [part.lower() for part in relative_parts]
        in_converted_tree = any(part.startswith("converted_") for part in parts)
        has_conversion_meta = "conversion_meta.json" in fn
        if in_converted_tree:
            if has_conversion_meta:
                out.append(p)
            dn[:] = []
            continue

        has_non_conversion_json = any(
            name.lower().endswith(".json") and name != "conversion_meta.json"
            for name in fn
        )
        has_eligible_markdown = any(
            name.lower().endswith(".md") and not name.lower().endswith("_converted.md")
            for name in fn
        )
        has_txt = any(name.lower().endswith(".txt") for name in fn)
        if has_non_conversion_json or has_eligible_markdown or has_txt:
            out.append(p)
    return out
