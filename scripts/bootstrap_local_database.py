"""Explicit LOCAL-only schema upgrade and least-privilege runtime role bootstrap.

Run inside the backend one-off container with the repository mounted read-only.
Only the hawki_backend schema is migrated. Public tables are counted, not changed.
"""

import json
import os

from alembic import command
from alembic.config import Config
import psycopg
from psycopg import sql
from sqlalchemy.engine import make_url, URL

from hawki_backend.readiness import required_tables

ROLE = "hawki_backend_app"
SCHEMA = "hawki_backend"


def public_counts(connection):
    names = connection.execute(
        "SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"
    ).fetchall()
    return {
        name: connection.execute(
            sql.SQL("SELECT count(*) FROM public.{}").format(sql.Identifier(name))
        ).fetchone()[0]
        for (name,) in names
    }


def main():
    if (
        os.environ.get("RAWKI_LOCAL_DATABASE_BOOTSTRAP") != "true"
        or os.environ.get("DB_HOST") != "postgres"
    ):
        raise ValueError("Explicit local database bootstrap is required")
    runtime_dsn = os.environ.get("RAG_BACKEND_DB_DSN", "")
    if not runtime_dsn:
        raise ValueError("Resolved runtime DSN is absent")
    runtime = make_url(runtime_dsn)
    if (
        runtime.host != "postgres"
        or runtime.username != ROLE
        or runtime.database != os.environ["POSTGRES_DB"]
    ):
        raise ValueError("Runtime DSN is not the expected local target")
    print(
        json.dumps(
            {"resolved_runtime_dsn_present": True, "target_is_local_postgres": True}
        )
    )
    arguments = dict(
        host="postgres",
        port=5432,
        dbname=os.environ["POSTGRES_DB"],
        user=os.environ["POSTGRES_USER"],
        password=os.environ["POSTGRES_PASSWORD"],
    )
    with psycopg.connect(**arguments, autocommit=True) as connection:
        if not connection.execute(
            "SELECT rolsuper FROM pg_roles WHERE rolname=current_user"
        ).fetchone()[0]:
            raise ValueError(
                "The local bootstrap connection must be the known local administrator"
            )
        before = public_counts(connection)
        exists = connection.execute(
            "SELECT to_regclass('hawki_backend.alembic_version') IS NOT NULL"
        ).fetchone()[0]
        previous = (
            connection.execute(
                "SELECT version_num FROM hawki_backend.alembic_version"
            ).fetchone()[0]
            if exists
            else None
        )
        role = connection.execute(
            "SELECT rolsuper OR rolcreatedb OR rolcreaterole FROM pg_roles WHERE rolname=%s",
            (ROLE,),
        ).fetchone()
        if role is None:
            connection.execute(
                sql.SQL(
                    "CREATE ROLE {} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD {}"
                ).format(sql.Identifier(ROLE), sql.Literal(runtime.password))
            )
        elif (
            role[0]
            or connection.execute(
                "SELECT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname=%s))",
                (ROLE,),
            ).fetchone()[0]
        ):
            raise ValueError("Existing runtime role has unexpected privileges")
        # No public table grants or ownership changes are made.
        if connection.execute(
            "SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f') AND has_table_privilege(%s,c.oid,'SELECT'))",
            (ROLE,),
        ).fetchone()[0]:
            raise ValueError("The runtime role already inherits legacy table access")
    admin_url = URL.create(
        "postgresql+psycopg",
        username=arguments["user"],
        password=arguments["password"],
        host="postgres",
        port=5432,
        database=arguments["dbname"],
    )
    os.environ["RAG_BACKEND_DB_DSN"] = admin_url.render_as_string(hide_password=False)
    config = Config("/workspace/python_backend/alembic.ini")
    command.upgrade(config, "head")
    os.environ["RAG_BACKEND_DB_DSN"] = runtime_dsn
    with psycopg.connect(**arguments, autocommit=True) as connection:
        connection.execute(
            sql.SQL("GRANT CONNECT ON DATABASE {} TO {}").format(
                sql.Identifier(arguments["dbname"]), sql.Identifier(ROLE)
            )
        )
        connection.execute(
            sql.SQL("GRANT USAGE ON SCHEMA {} TO {}").format(
                sql.Identifier(SCHEMA), sql.Identifier(ROLE)
            )
        )
        for table, privileges in required_tables(os.environ).items():
            connection.execute(
                sql.SQL("GRANT {} ON TABLE {}.{} TO {}").format(
                    sql.SQL(",".join(sorted(privileges))),
                    sql.Identifier(SCHEMA),
                    sql.Identifier(table.name),
                    sql.Identifier(ROLE),
                )
            )
        connection.execute(
            sql.SQL("GRANT SELECT ON TABLE hawki_backend.alembic_version TO {}").format(
                sql.Identifier(ROLE)
            )
        )
        connection.execute(
            sql.SQL(
                "GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA hawki_backend TO {}"
            ).format(sql.Identifier(ROLE))
        )
        after = public_counts(connection)
        if before != after:
            raise ValueError(
                "Historical table counts changed during the local migration"
            )
        current = connection.execute(
            "SELECT version_num FROM hawki_backend.alembic_version"
        ).fetchone()[0]
    # A real runtime login proves the generated password and exact DSN agree.
    from hawki_backend.readiness import check_database

    check_database(os.environ)
    print(
        json.dumps(
            {
                "revision_before": previous,
                "revision_after": current,
                "historical_public_tables_unchanged": len(before),
                "runtime_role_check": "PASS",
            }
        )
    )


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(
            json.dumps(
                {
                    "local_database_bootstrap": "FAIL",
                    "error_type": type(error).__name__,
                    "secret_values": "suppressed",
                }
            )
        )
        raise SystemExit(1) from None
