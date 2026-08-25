# HAWKI RAG stores

This package contains typed, injectable Qdrant and Neo4j primitives. It owns
transport, request, response, retry, and storage-shape behavior. Query and
indexing workflow orchestration remain in their owning services.

## Neo4j workflow and failure policy

`hawki-rag-stores` has a required dependency on Neo4j Python Driver 6.2; an
installed application must never conditionally import or stub the driver. A
graph operation follows these steps:

1. `Neo4jGraph` loads the URI, credentials, database, and the managed retry
   window, then creates one driver with a connection pool.
2. A typed `Neo4jQueryRequest` carries the Cypher parameters and telemetry
   identity to `Neo4jQueryExecutor`.
3. The executor opens a short-lived session and delegates the callback to
   `Session.execute_read` or `Session.execute_write`.
4. The callback fully materializes records or consumes the result before it
   returns. The driver commits on return or rolls back when the callback raises.
5. The driver retries errors it classifies as retryable until
   `NEO4J_MAX_TRANSACTION_RETRY_TIME` (30 seconds by default) expires. The
   executor logs and re-raises the final server or driver exception.

Retry ownership belongs exclusively to the Neo4j managed-transaction methods.
They may invoke a callback more than once, so callbacks must be idempotent and
must not cause non-transactional side effects. Do not add an application retry
loop around `execute_read` or `execute_write`. Auto-commit `Session.run` calls
are not covered by this retry guarantee. See the
[Driver 6 managed transaction API](https://neo4j.com/docs/api/python-driver/current/api.html#neo4j.Session.execute_write)
and
[driver retry configuration](https://neo4j.com/docs/api/python-driver/current/api.html#max-transaction-retry-time-ref).

The exception policy follows the public
[Neo4j Driver error hierarchy](https://neo4j.com/docs/api/python-driver/current/api.html#errors):

- Server errors are caught through `Neo4jError`. `ClientError` covers
  `CypherSyntaxError`, `CypherTypeError`, `ConstraintError`, `AuthError`,
  `TokenExpired`, and `Forbidden`; these fail fast. `DatabaseError` also
  propagates. `TransientError` covers `DatabaseUnavailable`, `NotALeader`, and
  `ForbiddenOnReadOnlyDatabase`; managed transactions let the driver retry them.
- Client-side driver errors are caught through `DriverError`. `SessionError`,
  `TransactionError`/`TransactionNestingError`,
  `ResultError`/`ResultFailedError`/`ResultConsumedError`/`ResultNotSingleError`,
  and `BrokenRecordError` propagate because they normally represent lifecycle,
  contract, or decoding failures.
- `SessionExpired` and
  `ServiceUnavailable`/`RoutingServiceUnavailable`/`WriteServiceUnavailable`/
  `ReadServiceUnavailable` are availability failures. The driver retries them
  inside managed transactions; optional bridge graph enrichment may degrade to
  an empty graph result only after those retries are exhausted.
- `ConfigurationError` and its `AuthConfigurationError`,
  `CertificateConfigurationError`, and `UnsupportedServerProduct` subclasses
  fail fast. `ConnectionPoolError` and `ConnectionAcquisitionTimeoutError` are
  explicit capacity failures and are never inferred from message strings.
- `IncompleteCommit` is never automatically retried by application code. It
  means the connection was lost while waiting for the commit response, so a
  write may or may not have committed. Telemetry marks its outcome as unknown.

Database fallback is intentionally narrow: it occurs only when a configured
database returns `Neo.ClientError.Database.DatabaseNotFound`. Authentication,
authorization, Cypher, configuration, and connectivity failures never silently
switch to the default database.
