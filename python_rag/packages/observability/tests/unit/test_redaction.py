"""Secret-safe observability redaction behavior."""

from __future__ import annotations

import logging
import unittest


class RedactionTests(unittest.TestCase):
    def test_event_logging_recursively_redacts_and_bounds_fields(self) -> None:
        from hawki_observability.event_logging import log_event

        logger = logging.getLogger("tests.observability.event")
        with self.assertLogs(logger, level=logging.INFO) as captured:
            log_event(
                logger,
                "pipeline.completed",
                result={
                    "error_details": "Authorization: Bearer super-secret-token",
                    "documents": [1, 2],
                },
            )

        output = captured.output[0]
        self.assertIn("pipeline.completed", output)
        self.assertIn("Authorization=<redacted>", output)
        self.assertNotIn("super-secret-token", output)

    def test_log_redaction_masks_secrets_in_headers_and_body_snippets(self) -> None:
        from hawki_observability.redaction import (
            log_redacted_value,
            preview_request_body,
            preview_request_headers,
            sanitize_for_log,
        )

        headers = {
            "authorization": "Bearer deadbeef-token",
            "x-request-id": "req-1",
            "api-key": "super-secret-key",
            "content-type": "application/json",
        }
        preview = preview_request_headers(headers)
        self.assertEqual(preview["authorization"], "<redacted>")
        self.assertEqual(preview["api-key"], "<redacted>")
        self.assertEqual(preview["x-request-id"], "req-1")

        body = preview_request_body(
            '{"api_key":"super-secret-key","query":"wood toy"}',
            content_type="application/json",
        )
        self.assertIn("<redacted>", body or "")

        self.assertIn("<redacted>", log_redacted_value("api_key=super-secret-key"))

        authorization = log_redacted_value("Authorization: Bearer super-secret-token")
        json_authorization = log_redacted_value(
            '{"authorization":"Bearer super-secret-token"}'
        )
        self.assertEqual(authorization, "Authorization=<redacted>")
        self.assertNotIn("super-secret-token", json_authorization)
        self.assertIn("authorization=<redacted>", json_authorization)

        for unsafe in (
            "token=query-secret-value",
            "https://example.test/path?x=1&token=query-secret-value&safe=1",
            "converter_token=converter-secret-value",
            "Authorization: Basic dXNlcjpzdXBlcnNlY3JldA==",
            'Authorization: Digest username="admin", nonce="digest-secret"',
            "https://user:password@example.test/private",
        ):
            redacted = sanitize_for_log(unsafe)
            self.assertNotIn("secret", redacted)
            self.assertNotIn("password", redacted)

        bounded = sanitize_for_log("x" * 3000, max_length=2048)
        self.assertEqual(len(bounded), 2048)
        self.assertTrue(bounded.endswith("..."))
