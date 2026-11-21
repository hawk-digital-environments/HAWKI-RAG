# Historical activation model

This test-only model preserves deployment/security regression expectations from
retired migration epochs. It is outside runtime and operator tooling. Tests mock
the transport; real PHP/Artisan commands are refused. Current deployment checks
are `hawki_backend.readiness` and `scripts/cutover_readiness.py`, both Python-only.
