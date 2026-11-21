# Historical contract fixtures

These inert fixtures replace test-time PHP/Composer dependencies. They are never
loaded by runtime code and contain no deployment credentials.

- `text_ingestion.fixture`: 48 metadata cases and eight encoded-size boundaries
  captured on 2026-11-21 using the original `tests/Support/text_ingestion_parity.php`
  at commit `5391cb23`, before source removal. Expected fingerprints, canonical
  JSON, metadata byte counts and passthrough bytes are independent reference data.
- `managed_upload.fixture`: the original `tests/Fixtures/python_managed_upload.fixture`
  copied byte-for-byte. It verifies stable upload paths and content.
- Signed historical control bytes live at
  `python_rag/packages/contracts/tests/fixtures/historical_control.fixture`.
  Seven cases cover 1/1728 deletion targets, replacement, status and cancellation.
  They were captured with the original control client at `5391cb23`. The public
  test-only HMAC constant is not a service secret. Tests verify the captured
  signature, then refresh only its test clock for replay-window validation.

Do not regenerate historical expectations from the implementation being tested.
