"""Bridge startup and configuration reliability scenarios."""

from __future__ import annotations

import unittest
from unittest.mock import Mock, patch

from neo4j.exceptions import ClientError, ServiceUnavailable
from requests import ConnectionError as RequestsConnectionError


class BridgeReliabilityTests(unittest.TestCase):
    def test_raganything_file_logging_is_not_a_bridge_setting(self) -> None:
        from hawki_bridge.settings import load_settings

        settings = load_settings({})
        configured_settings = load_settings(
            {
                "HAWKI_RAG_RAGANYTHING_LOG_PATH": "/shared/logs/raganything_runtime.log",
            }
        )

        self.assertEqual(configured_settings, settings)
        self.assertFalse(hasattr(configured_settings, "raganything_log_path"))

    def test_startup_checks_fail_fast_after_retry_cap(self) -> None:
        from hawki_bridge.settings import load_settings
        from hawki_bridge.startup_checks import run_startup_checks

        settings = load_settings({"STARTUP_CHECK_ATTEMPTS": "2"})

        check_qdrant = Mock(side_effect=RequestsConnectionError("qdrant unavailable"))
        check_neo4j = Mock()
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(RequestsConnectionError):
                run_startup_checks(
                    settings,
                    logger=__import__("logging").getLogger("tests.reliability.startup"),
                    check_qdrant_fn=check_qdrant,
                    check_neo4j_fn=check_neo4j,
                )
        self.assertEqual(check_qdrant.call_count, 2)
        check_neo4j.assert_not_called()
        self.assertEqual(sleep.call_count, 1)

    def test_neo4j_startup_check_uses_driver_retryability(
        self,
    ) -> None:
        from hawki_bridge.settings import load_settings
        from hawki_bridge.startup_checks import run_startup_checks

        settings = load_settings({"STARTUP_CHECK_ATTEMPTS": "2"})
        logger = __import__("logging").getLogger("tests.reliability.neo4j-startup")

        retryable_check = Mock(side_effect=ServiceUnavailable("not ready"))
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(ServiceUnavailable):
                run_startup_checks(
                    settings,
                    logger=logger,
                    check_qdrant_fn=Mock(),
                    check_neo4j_fn=retryable_check,
                )
        self.assertEqual(retryable_check.call_count, 2)
        self.assertEqual(sleep.call_count, 1)

        fail_fast_check = Mock(side_effect=ClientError("bad query or credentials"))
        with patch("hawki_bridge.startup_checks.time.sleep") as sleep:
            with self.assertRaises(ClientError):
                run_startup_checks(
                    settings,
                    logger=logger,
                    check_qdrant_fn=Mock(),
                    check_neo4j_fn=fail_fast_check,
                )
        self.assertEqual(fail_fast_check.call_count, 1)
        sleep.assert_not_called()
