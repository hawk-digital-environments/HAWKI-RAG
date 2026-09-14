# Installation

This is the first-install procedure. Check [Requirements](./1_requirements.md)
before starting. For an existing installation, use [Run HAWKI RAG](./2_setup.md).

## 1. Obtain the checkout and create configuration

```bash
git clone https://github.com/hawk-digital-environments/HAWKI-RAG.git
cd HAWKI-RAG
test -f .env || cp .env.example .env
```

If you already have a checkout, run only the last command from its root.
Keep `.env` private. It is both the Compose interpolation source and the
environment file supplied to configured containers.

## 2. Set the installation secrets

| Variable | Action before first startup |
|---|---|
| `APP_KEY` | Generate a Laravel encryption key as described below; retain it across restarts. |
| `DB_PASSWORD` | Replace `change_me` with a unique password. |
| `NEO4J_PASSWORD` | Replace `change_me` with a different unique password. |
| `HAWKI_RAG_WORKER_CALLBACK_SECRET` | Set one non-empty secret shared by Laravel and all activity workers. |

Generate the Laravel key material:

```bash
openssl rand -base64 32
```

OpenSSL prints **only the encoded value**. Add the literal `base64:` prefix
yourself when pasting it into `.env`:

```env
APP_KEY=base64:<generated-value>
```

Generate each database password separately with `openssl rand -hex 24`.
Generate the callback secret with `openssl rand -hex 32`.

<details>
<summary>Using Laravel's key generator instead</summary>

If the local Composer dependencies and PHP runtime are already installed,
`php artisan key:generate` is Laravel's native way to write a correctly prefixed
key into the checkout's `.env`. `php artisan key:generate --show` prints one.

The Composer create-project hook invokes that command, but cloning this
repository and starting Docker does not run that hook. Generating a key only
inside a built container does not reliably update the host environment file
that Compose injects. Set the host `.env` before starting the stack.

</details>

:::warning Persistent credentials

Changing `.env` does not rotate users in existing PostgreSQL or Neo4j volumes.
Changing `APP_KEY` can make encrypted sessions and stored values unreadable.
See [configuration change impact](../Operations/5_environment_db_queue.md#before-changing-a-value).

:::

Keep the internal endpoints from the template for the supplied stack. The
[Configuration Reference](../Operations/5_environment_db_queue.md) owns provider,
storage, external-tool, and URL settings.

For a server deployment, set `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL` to the public HTTPS URL, and `SESSION_SECURE_COOKIE=true`.
The Make target does not override these values. Review the
[actual access boundaries](../Core%20Concepts/authorization_dataset_scope.md#access-boundaries)
before exposing the management application.

## 3. Start the core stack

Start Docker, then run:

```bash
docker ps
make up-core
```

This creates the external Docker networks, builds images, stops existing
Compose services, starts PostgreSQL/Temporal/Laravel, runs Laravel migrations,
and starts the remaining services. Laravel startup initializes shared storage;
there is no separate migration container.

Startup also attempts to pull the local models. Pull failures are suppressed
by the Make target, so successful command completion does not prove the models
are available.

Open [http://localhost:8080](http://localhost:8080).
For source-mounted development or a reverse proxy, use the
[startup mode table](./2_setup.md#choose-a-startup-mode).

## 4. Verify health and create a query identity

```bash
make health
curl -fsS http://localhost:8080/up
curl -fsS http://localhost:8080/api/ping
docker exec hawki_ollama ollama list
docker exec hawki_rag_app php artisan pipeline:workers
```

Expect the liveness/API requests to succeed, the template's models to appear
in Ollama, and configured worker/queue ownership to be listed. The listing
does not prove live workers are polling. Inspect every `WARN` and `SKIPPED`
line from `make health`: some essential RAG components are classified as
optional by that target. [Monitoring](../Operations/monitoring.md) explains
what these probes do and do not establish.

Create a local user if this is a fresh installation:

```bash
docker exec -it hawki_rag_app php artisan user:create
```

Keep the printed user ID. The single-user browser flow resolves the sole active
local user; API tokens select an explicit user. See
[Authorization & Dataset Scope](../Core%20Concepts/authorization_dataset_scope.md).

## 5. Prove ingestion and retrieval

Run the [direct-text smoke test](./2_setup.md#direct-text-smoke-test).
It writes a small document, waits for the source to become ready, and queries
the resulting evidence. This does not require the crawler or converter.

If you need websites or uploads, prepare the
[external tools](./2_setup.md#external-tools) and run the
[full-ingestion smoke test](./2_setup.md#full-ingestion-smoke-test) too.
Core startup alone does not start the external projects.

## 6. Optional: connect an MCP client

After the smoke test succeeds, create a `query` token for the user:

```bash
docker exec -it hawki_rag_app php artisan user:token --abilities=query
docker exec hawki_rag_app php artisan route:list --path=hawki_rag
```

Use the listed MCP route with bearer authentication. With the supplied
`MCP_SERVER=hawki_rag`, the route is `/hawki_rag`; `MCP_BASE_URL` does not
register a different transport route. Give the user access to the target dataset
if explicit grants are enabled.

The [MCP query-search contract](../Reference/mcp_query_search_contract.md)
documents tool input, output, and the verified output-schema mismatch.

Sources: [Makefile](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/Makefile), [environment template](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/.env.example),
[Composer scripts](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/composer.json), [MCP route](https://github.com/hawk-digital-environments/HAWKI-RAG/blob/main/routes/ai.php).
