from __future__ import annotations

from types import ModuleType, SimpleNamespace
import sys
import unittest

from temporal_rag.metadata import AppMetadataStore


class _FakeConnection:
    def __init__(self) -> None:
        self.closed = False

    def close(self) -> None:
        self.closed = True


class TemporalMetadataTests(unittest.TestCase):
    def test_metadata_store_forces_utc_database_session(self) -> None:
        calls: list[dict[str, object]] = []
        connection = _FakeConnection()
        psycopg = ModuleType("psycopg")

        def connect(**kwargs: object) -> _FakeConnection:
            calls.append(kwargs)
            return connection

        psycopg.connect = connect  # type: ignore[attr-defined]
        previous = sys.modules.get("psycopg")
        sys.modules["psycopg"] = psycopg
        try:
            store = AppMetadataStore(
                SimpleNamespace(
                    db_host="postgres",
                    db_port=5432,
                    db_name="hawki_rag",
                    db_user="rag_user",
                    db_password="secret",
                )
            )

            with store.connection() as returned:
                self.assertIs(returned, connection)
        finally:
            if previous is None:
                sys.modules.pop("psycopg", None)
            else:
                sys.modules["psycopg"] = previous

        self.assertEqual(calls[0]["options"], "-c timezone=UTC")
        self.assertTrue(connection.closed)


if __name__ == "__main__":
    unittest.main()
