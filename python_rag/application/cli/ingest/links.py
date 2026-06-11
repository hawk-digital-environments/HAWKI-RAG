from __future__ import annotations

import re


def extract_pdf_links(text: str) -> list[str]:
    if not text:
        return []
    pattern = re.compile(r"https?://[^\s)>\"]+?\.pdf", re.IGNORECASE)
    links = pattern.findall(text)
    seen: set[str] = set()
    out: list[str] = []
    for link in links:
        clean = link.rstrip(").,;\"'")
        if clean not in seen:
            seen.add(clean)
            out.append(clean)
    return out
