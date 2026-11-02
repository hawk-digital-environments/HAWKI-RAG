# Laravel to Python query trust boundary

The boundary is implemented but disabled by default. `HAWKI_QUERY_BACKEND=laravel`
keeps `POST /api/query` on the existing Laravel/bridge path. `python` selects only
the internal Python client; any other value fails closed. There is no automatic
fallback, retry, shadow query, or dual execution. Dataset listing and MCP do not
use this selector.

The existing session/Sanctum/sole-active-user principal resolution stays in place.
The single-user fallback is not proof of an interactive login: continue to
protect this deployment's public/management surface at the network/ingress boundary.

## Server-owned token profile

Laravel creates a new RS256 token immediately before each Python request:

| Field | Source |
|---|---|
| `iss`, `aud`, `azp`, `tenant_id`, header `kid` | BFF deployment metadata |
| `sub` | Stringified resolved Laravel user ID |
| `scope` | Fixed `rag:query` |
| `iat`, `exp` | Injected clock; lifetime configured from 1 to 60 seconds (default 60) |
| `jti` | New UUID per request; correlation, not replay prevention |

The signer never accepts browser claims or algorithm selection. It does not emit
`client_id`, `nbf`, `act`, or `may_act`. The browser never receives this token.
Python verifies it before retrieval, so a 300-second query budget does not need
a 300-second bearer lifetime. Its default 30-second clock leeway still applies.

## Metadata and verifier agreement

Root `.env.example` documents `HAWKI_QUERY_BACKEND`, `HAWKI_PYTHON_QUERY_URL`, and
`HAWKI_QUERY_BFF_*`. The internal query URL defaults to
`http://hawki_backend/v1/query`; only a configured HTTP(S) `/v1/query` endpoint
without userinfo, query string, or fragment is accepted. The client does not
inherit browser headers/cookies, follows no redirects, and uses the existing
`HAWKI_RAG_QUERY_TIMEOUT` (default 300 seconds) plus a 3-second connect timeout.

For a future dedicated BFF verifier, agree on:

| Python setting | Agreement |
|---|---|
| `HAWKI_BACKEND_AUTH_ISSUER` | Exactly `HAWKI_QUERY_BFF_ISSUER`; no normalization |
| `HAWKI_BACKEND_AUTH_AUDIENCE` | `hawki-backend`, matching BFF audience |
| `HAWKI_BACKEND_AUTH_CLIENTS` | Includes `{"hawki-bff":"user"}`, never `service` for browser users |
| `HAWKI_BACKEND_AUTH_TENANT_CLAIM` | `tenant_id` |
| `HAWKI_BACKEND_AUTH_ALGORITHMS` | `RS256` |
| `HAWKI_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS` | 60 for a dedicated BFF verifier; assess other clients before narrowing a shared verifier |
| `HAWKI_BACKEND_AUTH_JWKS_URL` | Final certificate-valid HTTPS URL of the public static JWKS |

`default` is the proposed tenant, not an automatic grant migration. Obtain operator
approval and use the identical tenant/user identifiers in Python-owned grants.
No valid token supplies grants, physical storage scope, or model configuration.
Keep the existing shared retrieval environment aligned with the bridge.

## Opt-in key and JWKS mounts

Provision outside the checkout and every Docker build context. Do not place a
private key in `public/`, Laravel `storage/`, shared artifact volumes, examples,
or an image. Do not reuse `APP_KEY`. Only the Laravel application receives the
private directory; Python and workers receive no signing key.

The optional `docker-compose.bff.yml` mounts:

| Host directory variable | Container destination | Required file |
|---|---|---|
| `HAWKI_QUERY_BFF_PRIVATE_DIR` | `/run/secrets/query-bff` | `private.pem`, RSA private key at least 2048 bits |
| `HAWKI_QUERY_BFF_PUBLIC_DIR` | `/run/query-bff-public` | `jwks.json`, public verification material only |

Both mounts are read-only and refuse to create missing host directories. Set
private directory/file ownership for the actual Laravel runtime UID, with no
access for other users (for example directory 0700, key 0400). Verify permissions
inside the container; Compose local bind mounts do not enforce secret ownership
on the host. Docker administrators/root remain inside the trust boundary.

`docker/rag_app/nginx/project.prod.conf` has an exact
`/.well-known/jwks.json` alias to the public file. Missing provisioning returns
404 instead of falling through to PHP. No PHP JWKS/key-management API exists.
Deploy/rebuild the application image with this configuration; do not assume an
old running image has picked up the repository edit.

The provisioned JSON must have a `keys` array, unique nonempty `kid` values,
`kty=RSA`, `alg=RS256`, `use=sig`, `key_ops=["verify"]`, and valid public `n` and
`e` values. Publish the actual public counterpart of each signing key. Validate
the artifact before mounting: never include `d`, `p`, `q`, `dp`, `dq`, `qi`,
`oth`, private PEM, or symmetric material. Static Nginx serves the operator's
file; it does not sanitize incorrectly provisioned contents.

Add the BFF override to the deployment's existing Compose layers only after
provisioning. Do not include it in default startup or switch the selector as part
of installing the plumbing. The legacy deployment needs no BFF files.

## HTTPS and rotation

The local HTTP application mapping alone is not a valid JWKS deployment. Use an
HTTPS ingress hostname reachable from `hawki_backend`, with a certificate valid
for that hostname. Account for the deployment's application base path and any
ingress rewrite: configure the final URL directly, without redirects. Nginx
disables compression for the JWKS endpoint; Python requests identity encoding.

For an operator-managed CA, the optional `docker-compose.bff-ca.yml` mounts
`HAWKI_QUERY_BFF_CA_DIR/ca-bundle.pem` and sets backend `SSL_CERT_FILE`. Supply an
appropriate trust bundle, not a private key. Never disable certificate checking
or relax Python's HTTPS requirement. Public-CA deployments need no CA override.

Prepublish old and new public keys with distinct IDs, verify propagation to all
verifiers, then switch the private key and `HAWKI_QUERY_BFF_KEY_ID`. Keep the old
public key until its last token expires plus leeway. Allow for Python's defaults:
600-second cache TTL, 60-second refresh cooldown, 3600-second maximum stale age,
and Nginx's 60-second HTTP cache. For emergency compromise, key removal alone
does not invalidate cached keys; include verifier cache invalidation/restart.
Use directory mounts and an operator-controlled atomic publication process.

## Failures and rollback

Signing/configuration failure returns a generic 503; connection failure a generic
502; timeout a generic 504. Recognized Python JSON statuses/bodies are preserved,
including auth, grant denial, readiness, retrieval failures, and validation errors.
Redirects, malformed/non-JSON/debug responses, and unrecognized bodies become a
generic 502. No raw transport exception, JWT, or upstream dump is logged/returned.

To roll back, restore the deployment selector to `laravel`, refresh Laravel's
configuration cache, and restart/recreate the app with the updated environment.
No image rebuild is needed for the selector change. Editing a host env file alone
does not change an existing container's injected environment.

Before activation: provision real keys, verify HTTPS JWKS/certificates, approve
the canonical tenant, backfill and validate Python grants, resolve the query-all
transition and Laravel-owned dataset-list parity, then run live query parity.
These are activation gates; this implementation does not execute them.

## Tests

New Laravel tests cover the signer, filtered HTTP client, branch selection and
identity injection. `python_backend/tests/auth/test_php_bff_interoperability.py`
calls the real PHP signer with ephemeral test keys through stdin, then verifies
with the unchanged Python JWT/JWKS code. Run it with PHP and the root Composer
dependencies installed; otherwise that cross-language group explicitly skips.
No committed test key or JWT is used by deployment configuration.
