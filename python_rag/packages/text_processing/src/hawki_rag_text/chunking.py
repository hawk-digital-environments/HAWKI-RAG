"""Text chunking helpers."""

from __future__ import annotations


def split_text_into_chunks(text: str, target: int, overlap: int) -> list[str]:
    """Split text into overlapping chunks, preferring paragraph boundaries."""

    text = (text or "").strip()
    if not text:
        return []
    if len(text) <= target:
        return [text]

    chunks: list[str] = []
    start = 0
    length = len(text)
    while start < length:
        end = min(length, start + target)
        slice_ = text[start:end]
        cut = slice_.rfind("\n\n")
        if cut != -1 and cut > int(target * 0.6):
            end = start + cut
        chunk = text[start:end].strip()
        if chunk:
            chunks.append(chunk)
        if end >= length:
            break
        start = max(0, end - overlap)
    return chunks


__all__ = ["split_text_into_chunks"]
