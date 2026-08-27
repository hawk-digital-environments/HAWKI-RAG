# HAWKI artifact store

`hawki-artifact-store` is the small shared-filesystem boundary used by the
scraper, converter, and indexer workers. It is a Python library bundled into
those worker images; it is not a database, container, metadata repository, or
object-storage client.

## Responsibility boundary

Laravel owns the control-plane concerns:

- validating and storing uploads;
- allocating source, raw, Markdown, and manifest paths;
- passing the canonical shared root in the Temporal workflow input;
- authorizing operations and storing task, job, and source metadata;
- processing signed worker callbacks; and
- deleting task and source workspaces at the end of their lifecycle.

Python workers own only activity-local artifact work:

- copying a Laravel-owned upload into its raw stage directory;
- resetting the specific retry-local stage directories Laravel supplied;
- listing and reading converted files;
- writing the manifest that describes what the indexer actually processed.

## Supported storage

Only a mounted local shared volume is supported. Every `LocalArtifactStore`
instance requires the `storage.shared_root` supplied by Laravel, normally
`/shared`. The root must be an existing directory and cannot be `/`. Every path
is resolved, including symlinks, and must remain below that root.

Accepted locations are absolute paths and local `file:///...` URIs. Relative
paths, remote URI schemes, and file URIs with a host are rejected. There is no
S3 adapter or fallback behavior in this package.

## Public modules

- `hawki_artifact_store.local.LocalArtifactStore` performs root-confined
  resolution, Markdown enumeration, exact byte/text reads, safe stage-directory
  recreation, and atomic JSON manifest replacement. Its mutation preflight also
  rejects symlink components so one source cannot redirect a write or deletion
  into another source workspace.
Storage reads never normalize or otherwise alter content. Converter-specific
Markdown cleanup must be called explicitly by the converter or indexer.

```python
from hawki_artifact_store.local import LocalArtifactStore

store = LocalArtifactStore("/shared")
markdown_files = store.list_markdown("/shared/sources/source-a/markdown")
text = store.read_text(markdown_files[0])
relative_path = store.relative_path(
    markdown_files[0],
    "/shared/sources/source-a/markdown",
)
```

Stable content hashes and document IDs are pipeline policy and live in
`hawki_rag_contracts.pipeline.identity`, not in this storage adapter.

Manifests are serialized with sorted object keys and a final newline, written to
a unique temporary file beside the destination, and committed with
`os.replace()`. A reader therefore sees either the previous complete manifest or
the new complete manifest, never a partially written file.

## Tests

From `python_rag`, run `uv run --group test --package hawki-artifact-store pytest
packages/artifact_store/tests`.
