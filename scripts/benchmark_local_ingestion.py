"""Measure disposable local PDF/vector, PDF/graph and direct-text ingestion.

Run with python_backend/.venv/bin/python scripts/benchmark_local_ingestion.py.
Uses the local UI service boundary; no browser or CLI JWT is required. Synthetic
input is image-heavy, searchable text, not a representative PDF/OCR benchmark.
"""

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import time
from uuid import uuid4

import httpx

from local_api_client import api_url, service_headers


def synthetic_pdf(marker=""):
    """Build a six-page searchable PDF with a 3 MB uncompressed RGB illustration."""
    paragraphs = []
    objects = [b"", b"", b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]
    image = b"".join(
        bytes((x * 255 // 999, y * 255 // 999, 120))
        for y in range(1000)
        for x in range(1000)
    )
    objects.append(
        b"<< /Type /XObject /Subtype /Image /Width 1000 /Height 1000 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length "
        + str(len(image)).encode()
        + b" >>\nstream\n"
        + image
        + b"\nendstream"
    )
    pages = []
    for page in range(1, 7):
        lines = [
            f"Project Cedar technical report - section {page}",
            "Mira Vale leads Project Cedar at Aurora Institute in Berlin.",
            "Noah Reed maintains Atlas Gateway and designed Atlas Protocol.",
            "Cedar Sensor measures greenhouse temperature and soil moisture.",
            "Atlas Gateway receives Cedar Sensor measurements using Atlas Protocol.",
            "Aurora Institute owns the greenhouse and funds Project Cedar.",
            "Mira Vale reviews measurements with Noah Reed every week.",
            f"Experiment {page} records sensor calibration and irrigation performance.",
            "The project uses these measurements to improve greenhouse irrigation.",
            "This synthetic illustration adds image bytes without requiring OCR.",
        ]
        if marker:
            lines = [
                line.replace("Cedar", "Cedar" + marker).replace(
                    "Mira Vale", "Mira " + marker
                )
                for line in lines
            ]
        paragraphs.append("\n".join(lines))
        content = (
            b"BT /F1 12 Tf 50 780 Td 18 TL "
            + b" ".join(b"(" + line.encode() + b") Tj T*" for line in lines)
            + b" ET\n"
        )
        if page == 6:
            content += b"q 450 0 0 450 50 80 cm /Im1 Do Q\n"
        content_id = len(objects) + 1
        objects.append(
            b"<< /Length "
            + str(len(content)).encode()
            + b" >>\nstream\n"
            + content
            + b"endstream"
        )
        page_id = len(objects) + 1
        objects.append(
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >> /Contents {content_id} 0 R >>".encode()
        )
        pages.append(page_id)
    objects[0] = b"<< /Type /Catalog /Pages 2 0 R >>"
    objects[1] = (
        f"<< /Type /Pages /Count 6 /Kids [{' '.join(f'{p} 0 R' for p in pages)}] >>".encode()
    )
    data = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for number, body in enumerate(objects, 1):
        offsets.append(len(data))
        data.extend(f"{number} 0 obj\n".encode() + body + b"\nendobj\n")
    xref = len(data)
    data.extend(f"xref\n0 {len(offsets)}\n0000000000 65535 f \n".encode())
    for offset in offsets[1:]:
        data.extend(f"{offset:010d} 00000 n \n".encode())
    data.extend(
        f"trailer\n<< /Size {len(offsets)} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    )
    return bytes(data), "\n\n".join(paragraphs)


def stage_spans(events):
    result = {}
    for stage in ("scrape", "convert", "ingest"):
        rows = [e for e in events if e.get("stage") == stage]
        starts = [
            datetime.fromisoformat(e["timestamp"])
            for e in rows
            if e.get("status") == "running"
        ]
        ends = [
            datetime.fromisoformat(e["timestamp"])
            for e in rows
            if e.get("status") in {"completed", "success"}
        ]
        result[stage] = (
            round((max(ends) - min(starts)).total_seconds(), 3)
            if starts and ends
            else None
        )
    return result


def markdown(report):
    lines = [
        "# Local ingestion timing report",
        "",
        f"Measured: {report['started_at']}",
        "",
        "Synthetic six-page searchable PDF with a 3 MB uncompressed RGB illustration. "
        "This is image-heavy test input, not a prediction for ordinary PDFs, scanned PDFs, or OCR. "
        "Same PDF used for graph off/on; direct text uses its source paragraphs. "
        "One sequential sample per mode; model/cache warmup and polling affect comparisons.",
        "",
        f"PDF: {report['input']['bytes']:,} bytes; source text: {report['input']['text_characters']:,} characters.",
        "",
        f"Runtime: {report.get('runtime', {}).get('engine', 'unrecorded')}; "
        f"model: {report.get('runtime', {}).get('query_model', 'unrecorded')}; "
        f"embeddings: {report.get('runtime', {}).get('embedding_model', 'unrecorded')}.",
        "",
        f"Input SHA-256: `{report['input']['sha256']}`.",
        "",
        "Times are seconds. Admission-to-ready includes upload, queueing, conversion/indexing and up to one polling interval. "
        "Stage spans use worker callback timestamps; missing spans are not estimated. Verification and cleanup are separate.",
        "",
        "| Mode | Result | Admission | Admission to ready | Convert span | Index span | Verification | Cleanup |",
        "|---|---|---:|---:|---:|---:|---:|---:|",
    ]
    for row in report["runs"]:
        fields = [row["mode"], row["status"]] + [
            str(row.get(k) if row.get(k) is not None else "—")
            for k in (
                "admission_seconds",
                "ready_seconds",
                "convert_seconds",
                "ingest_seconds",
                "verification_seconds",
                "cleanup_seconds",
            )
        ]
        lines.append("| " + " | ".join(fields) + " |")
    lines += [
        "",
        "Direct text skips the converter and does not perform graph enrichment. "
        "Each case creates its own dataset, redeems its exact ingest grant, waits for ready indexing, "
        "redeems query access, and verifies retrieval. PDF cases also verify indexed document status "
        "and byte-identical download. Graph on requires nodes and relationships; graph off/text require an empty graph. "
        "Cleanup deletes only this run's source/document and dataset storage.",
        "",
        "Direct-text worker stage spans were not exposed by this run's event projection; "
        "its total admission-to-ready time was measured with the same monotonic clock as the PDF cases.",
        "",
        "| Mode | Retrieval hits | Graph nodes | Graph relationships | Cleanup |",
        "|---|---:|---:|---:|---|",
        *[
            f"| {row['mode']} | {row.get('retrieval_hits', '—')} | "
            f"{row.get('graph', {}).get('nodes', '—')} | {row.get('graph', {}).get('edges', '—')} | "
            f"{row.get('cleanup', '—')} |"
            for row in report["runs"]
        ],
        "",
        "Reproduce from repository root: "
        "`python_backend/.venv/bin/python scripts/benchmark_local_ingestion.py`. "
        "Requires the local stack and a scoped service JWT. "
        "The generated PDF is `/tmp/rawki-synthetic-3mb.pdf`.",
        "",
        f"Detailed receipts and checks: `{report['json_report']}`",
        "",
    ]
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--report", type=Path, default=Path("/tmp/rawki-ingestion-benchmark.json")
    )
    parser.add_argument(
        "--markdown", type=Path, default=Path("/tmp/rawki-ingestion-benchmark.md")
    )
    parser.add_argument("--timeout", type=int, default=1800)
    args = parser.parse_args()
    pdf, text = synthetic_pdf()
    Path("/tmp/rawki-synthetic-3mb.pdf").write_bytes(pdf)
    report = {
        "started_at": datetime.now(timezone.utc).isoformat(),
        "json_report": str(args.report),
        "input": {
            "type": "synthetic",
            "pages": 6,
            "bytes": len(pdf),
            "text_characters": len(text),
            "sha256": hashlib.sha256(pdf).hexdigest(),
        },
        "runs": [],
    }

    def save():
        args.report.write_text(json.dumps(report, indent=2) + "\n")
        args.markdown.write_text(markdown(report))

    with httpx.Client(
        timeout=240,
        trust_env=False,
        follow_redirects=False,
        base_url=api_url(),
        headers=service_headers(),
    ) as client:

        def request(method, path, **kwargs):
            r = client.request(method, path, **kwargs)
            if r.status_code not in {200, 201, 202}:
                raise RuntimeError(f"{method} {path}: HTTP {r.status_code}")
            return r.json()

        def retire(path, key):
            deadline = time.monotonic() + 300
            while time.monotonic() < deadline:
                r = client.delete(path, headers={"Idempotency-Key": key})
                if r.status_code == 200:
                    return
                if r.status_code != 202:
                    raise RuntimeError(f"Cleanup HTTP {r.status_code}")
                time.sleep(2)
            raise TimeoutError("Cleanup pending")

        config = request("GET", "/ops/config")
        runtime = config.get("runtime", {})
        report["runtime"] = {
            key: runtime.get(key)
            for key in ("engine", "query_provider", "query_model", "embedding_model")
        }
        save()
        for mode in ("pdf_vectors", "pdf_graph", "direct_text_vectors"):
            row = {
                "mode": mode,
                "status": "RUNNING",
                "dataset_id": "benchmark-" + uuid4().hex[:16],
            }
            report["runs"].append(row)
            save()
            dataset = row["dataset_id"]
            created = False
            cleanup_path = None
            try:
                creation = request("POST", "/datasets", json={"dataset_id": dataset})
                created = True
                request(
                    "POST",
                    f"/datasets/{dataset}/ingest-grants/self",
                    json={"grant_token": creation["grant_token"]},
                )
                start = time.monotonic()
                if mode.startswith("pdf"):
                    admission = request(
                        "POST",
                        "/documents",
                        headers={"Idempotency-Key": dataset},
                        data={
                            "dataset_id": dataset,
                            "graph": str(mode == "pdf_graph").lower(),
                            "converter_mode": "native",
                        },
                        files={
                            "file": ("synthetic-report.pdf", pdf, "application/pdf")
                        },
                    )
                else:
                    admission = request(
                        "POST",
                        "/documents",
                        headers={"Idempotency-Key": dataset},
                        json={
                            "dataset_id": dataset,
                            "external_document_id": "benchmark-text",
                            "text": text,
                            "content_format": "plain_text",
                        },
                    )
                doc = admission["document"]["document_id"]
                cleanup_path = "/documents/" + doc
                task_id = admission["pipeline"]["task_id"]
                row["admission_seconds"] = round(time.monotonic() - start, 3)
                row["task_id"] = task_id
                save()
                last_status = None
                while time.monotonic() - start < args.timeout:
                    task = request("GET", "/pipeline/tasks/" + task_id)["task"]
                    if task["status"] != last_status:
                        print(
                            json.dumps(
                                {
                                    "mode": mode,
                                    "status": task["status"],
                                    "elapsed_seconds": round(
                                        time.monotonic() - start, 1
                                    ),
                                }
                            ),
                            flush=True,
                        )
                        last_status = task["status"]
                    if task["status"] == "completed":
                        assert task["jobs"] and all(
                            j["index_status"] == "ready" for j in task["jobs"]
                        )
                        break
                    if task["status"] in {"failed", "cancelled"}:
                        raise RuntimeError("Pipeline ended " + task["status"])
                    time.sleep(1)
                else:
                    raise TimeoutError("Ingestion deadline exceeded")
                row["ready_seconds"] = round(time.monotonic() - start, 3)
                row["task"] = task
                verify = time.monotonic()
                events = request("GET", f"/pipeline/tasks/{task_id}/events")["events"]
                row["events"] = events
                spans = stage_spans(events)
                row["convert_seconds"], row["ingest_seconds"] = (
                    spans["convert"],
                    spans["ingest"],
                )
                request("POST", f"/datasets/{dataset}/query-grants/self", json={})
                graph = request(
                    "GET", "/ops/graph/overview", params={"dataset_id": dataset}
                )
                row["graph"] = {
                    "nodes": len(graph["nodes"]),
                    "edges": len(graph["edges"]),
                }
                if mode == "pdf_graph":
                    assert graph["nodes"] and graph["edges"], "Graph expected"
                else:
                    assert not graph["nodes"] and not graph["edges"], "Unexpected graph"
                query = request(
                    "POST",
                    "/query",
                    json={
                        "dataset_id": dataset,
                        "query": "Who leads Project Cedar at Aurora Institute?",
                        "top_k": 5,
                        "generate": False,
                        "fast_mode": False,
                    },
                )
                assert query["hits"], "Retrieval must return indexed content"
                if mode == "pdf_graph":
                    assert query["kg"], "Graph context expected"
                row["retrieval_hits"] = len(query["hits"])
                if mode.startswith("pdf"):
                    document = request("GET", "/documents/" + doc)["document"]
                    assert document["status"] == "indexed"
                    download = client.get(
                        "/documents/uploads/download",
                        params={"document_id": doc},
                    )
                    assert download.status_code == 200 and download.content == pdf
                    row["download_sha256"] = hashlib.sha256(
                        download.content
                    ).hexdigest()
                row["verification_seconds"] = round(time.monotonic() - verify, 3)
                row["status"] = "PASS"
            except Exception as error:
                row["status"] = "FAIL"
                row["error"] = str(error)
            finally:
                cleanup = time.monotonic()
                try:
                    if cleanup_path:
                        retire(cleanup_path, dataset + "-source-cleanup")
                        remaining = request(
                            "GET", "/ops/graph/overview", params={"dataset_id": dataset}
                        )
                        assert not remaining["nodes"] and not remaining["edges"]
                    if created:
                        retire(
                            "/datasets/" + dataset + "/storage",
                            dataset + "-dataset-cleanup",
                        )
                    row["cleanup"] = "PASS"
                except Exception as error:
                    row["cleanup"] = "FAIL"
                    row["cleanup_error"] = str(error)
                    row["status"] = "FAIL"
                row["cleanup_seconds"] = round(time.monotonic() - cleanup, 3)
                save()
                print(
                    json.dumps(
                        {k: v for k, v in row.items() if k not in {"events", "task"}}
                    ),
                    flush=True,
                )
            if row["status"] != "PASS":
                raise SystemExit(1)


if __name__ == "__main__":
    main()
