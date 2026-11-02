"""Deterministic query-parity verification for the Laravel-to-Python cutover.

The package compares the Laravel ``POST /api/query`` contract with the Python
``POST /v1/query`` boundary before any traffic is cut over. It is test-only:
nothing under ``hawki_backend`` imports it, and the canonical fixture is never
read at request time.
"""
