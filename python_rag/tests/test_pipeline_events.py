import os
import sys
import unittest
from datetime import datetime, timezone
from uuid import uuid4


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)

from common.pipeline_events import ValidationError, parse_document_converted_event


def _payload(**overrides):
    data = {
        "event_id": str(uuid4()),
        "job_id": str(uuid4()),
        "parent_event_id": str(uuid4()),
        "schema_version": "1",
        "event_type": "convert.document.completed",
        "source": "file-converter",
        "original_url": "https://example.org/a.pdf",
        "original_path": "/app/shared/a.pdf",
        "original_relative_path": "a.pdf",
        "converted_path": "/app/shared/converted/a.md",
        "converted_relative_path": "converted/a.md",
        "output_format": "markdown",
        "converter_name": "mineru",
        "converter_version": "1.0.0",
        "input_checksum_sha256": "a" * 64,
        "output_checksum_sha256": "b" * 64,
        "converted_at": datetime.now(timezone.utc).isoformat(),
        "trace_id": "trace-1",
        "payload": {"lang": "en"},
    }
    data.update(overrides)
    return data


class PipelineEventsSchemaTests(unittest.TestCase):
    def test_document_converted_event_validation(self):
        evt = parse_document_converted_event(_payload())
        self.assertEqual(evt.event_type, "convert.document.completed")
        self.assertEqual(evt.output_format, "markdown")

    def test_document_converted_event_invalid_schema(self):
        bad = _payload(event_type="convert.document.done")
        with self.assertRaises(ValidationError):
            parse_document_converted_event(bad)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()

