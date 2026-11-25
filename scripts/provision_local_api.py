"""Generate ignored development credentials and the backend-only local env.

Run with python_backend/.venv/bin/python. This step never connects to a database
or changes running containers. It refuses remote Docker endpoints.
"""

from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
from urllib.parse import quote

import certifi
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
import jwt

ROOT = Path(__file__).resolve().parents[1]
MATERIAL = ROOT / ".secrets/local-api"
ISSUER = "https://rawki-local-auth:8443"
KID = "rawki-local-development-1"
BACKEND_ENV = MATERIAL / "backend.env"


def docker_json(*arguments):
    result = subprocess.run(
        ["docker", *arguments], cwd=ROOT, text=True, capture_output=True, check=True
    )
    return json.loads(result.stdout)


def write(path, value, mode=0o600):
    path.write_bytes(value if isinstance(value, bytes) else value.encode())
    path.chmod(mode)


def secret(name):
    path = MATERIAL / name
    value = path.read_text().strip() if path.exists() else secrets.token_urlsafe(48)
    if len(value) < 32 or any(char.isspace() for char in value):
        raise ValueError("Existing local credential has an invalid format")
    write(path, value + "\n")
    return value


def key(path):
    if path.exists():
        result = serialization.load_pem_private_key(path.read_bytes(), password=None)
        if not isinstance(result, rsa.RSAPrivateKey) or result.key_size < 3072:
            raise ValueError("Unexpected development key type")
        return result
    result = rsa.generate_private_key(public_exponent=65537, key_size=3072)
    write(
        path,
        result.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        ),
    )
    return result


def certificate_material(private):
    now = datetime.now(timezone.utc)
    ca_key = key(private / "ca.key")
    ca_path = private / "ca.pem"
    if ca_path.exists():
        ca = x509.load_pem_x509_certificate(ca_path.read_bytes())
    else:
        name = x509.Name(
            [x509.NameAttribute(NameOID.COMMON_NAME, "RAWKI local development CA")]
        )
        ca = (
            x509.CertificateBuilder()
            .subject_name(name)
            .issuer_name(name)
            .public_key(ca_key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - timedelta(minutes=5))
            .not_valid_after(now + timedelta(days=30))
            .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
            .add_extension(
                x509.SubjectKeyIdentifier.from_public_key(ca_key.public_key()),
                critical=False,
            )
            .add_extension(
                x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()),
                critical=False,
            )
            .add_extension(
                x509.KeyUsage(
                    True, False, False, False, False, True, True, False, False
                ),
                critical=True,
            )
            .sign(ca_key, hashes.SHA256())
        )
        write(ca_path, ca.public_bytes(serialization.Encoding.PEM), 0o644)
    leaf_key = key(private / "server.key")
    leaf_path = private / "server.pem"
    if not leaf_path.exists():
        import ipaddress

        leaf = (
            x509.CertificateBuilder()
            .subject_name(
                x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "rawki-local-auth")])
            )
            .issuer_name(ca.subject)
            .public_key(leaf_key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(now - timedelta(minutes=5))
            .not_valid_after(now + timedelta(days=30))
            .add_extension(
                x509.BasicConstraints(ca=False, path_length=None), critical=True
            )
            .add_extension(
                x509.SubjectKeyIdentifier.from_public_key(leaf_key.public_key()),
                critical=False,
            )
            .add_extension(
                x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()),
                critical=False,
            )
            .add_extension(
                x509.KeyUsage(
                    True, False, True, False, False, False, False, False, False
                ),
                critical=True,
            )
            .add_extension(
                x509.SubjectAlternativeName(
                    [
                        x509.DNSName("rawki-local-auth"),
                        x509.DNSName("localhost"),
                        x509.IPAddress(ipaddress.ip_address("127.0.0.1")),
                    ]
                ),
                critical=False,
            )
            .add_extension(
                x509.ExtendedKeyUsage([x509.oid.ExtendedKeyUsageOID.SERVER_AUTH]),
                critical=False,
            )
            .sign(ca_key, hashes.SHA256())
        )
        write(leaf_path, leaf.public_bytes(serialization.Encoding.PEM), 0o644)
    write(
        MATERIAL / "ca-bundle.pem",
        Path(certifi.where()).read_bytes() + b"\n" + ca_path.read_bytes(),
        0o644,
    )


def env_value(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def main():
    if os.environ.get("DOCKER_HOST") and not os.environ["DOCKER_HOST"].startswith(
        "unix://"
    ):
        raise ValueError("A local Unix Docker endpoint is required")
    context = docker_json("context", "inspect")[0]
    if not context["Endpoints"]["docker"]["Host"].startswith("unix://"):
        raise ValueError("A local Unix Docker context is required")
    initial = docker_json("compose", "config", "--format", "json")
    base = initial["services"]["postgres"]["environment"]
    if base.get("DB_HOST") != "postgres" or base.get("DB_DATABASE") != base.get(
        "POSTGRES_DB"
    ):
        raise ValueError(
            "The existing local Postgres configuration must be unambiguous"
        )
    (ROOT / ".secrets").mkdir(exist_ok=True, mode=0o700)
    (ROOT / ".secrets").chmod(0o700)
    MATERIAL.mkdir(exist_ok=True, mode=0o755)
    MATERIAL.chmod(0o755)
    private = MATERIAL / "issuer"
    private.mkdir(exist_ok=True, mode=0o700)
    private.chmod(0o700)
    runtime_password = secret("runtime-db-password")
    management_secret = secret("management-client.secret")
    hawki_secret = secret("hawki-client.secret")
    certificate_material(private)
    signing = key(private / "signing.pem")
    public = jwt.algorithms.RSAAlgorithm.to_jwk(signing.public_key(), as_dict=True)
    public.update(kid=KID, alg="RS256", use="sig")
    write(private / "jwks.json", json.dumps({"keys": [public]}), 0o644)
    scopes = ["rag:query", "rag:ingest", "rag:datasets:manage"]
    config = {
        "issuer": ISSUER,
        "audience": "hawki-backend",
        "kid": KID,
        "clients": {
            name: {
                "sha256": hashlib.sha256(value.encode()).hexdigest(),
                "tenant_id": "default",
                "scopes": allowed,
            }
            for name, value, allowed in [
                ("rag-management", management_secret, scopes),
                ("hawki-service", hawki_secret, ["rag:query"]),
            ]
        },
    }
    write(private / "issuer.json", json.dumps(config))
    values = {
        "RAG_BACKEND_DB_DSN": "postgresql+psycopg://hawki_backend_app:"
        + quote(runtime_password, safe="")
        + "@postgres:5432/"
        + quote(base["POSTGRES_DB"], safe=""),
        "RAG_BACKEND_AUTH_ISSUER": ISSUER,
        "RAG_BACKEND_AUTH_AUDIENCE": "hawki-backend",
        "RAG_BACKEND_AUTH_JWKS_URL": ISSUER + "/jwks.json",
        "RAG_BACKEND_AUTH_CLIENTS": json.dumps(
            {"rag-management": "service", "hawki-service": "service"},
            separators=(",", ":"),
        ),
        "RAG_BACKEND_AUTH_TENANT_CLAIM": "tenant_id",
        "RAG_BACKEND_AUTH_ALGORITHMS": "RS256",
        "RAG_BACKEND_AUTH_LEEWAY_SECONDS": "30",
        "RAG_BACKEND_AUTH_MAX_TOKEN_TTL_SECONDS": "3600",
        "RAG_BACKEND_AUTH_JWKS_CACHE_TTL_SECONDS": "600",
        "RAG_BACKEND_AUTH_JWKS_MIN_REFRESH_SECONDS": "60",
        "RAG_BACKEND_AUTH_JWKS_MAX_STALE_SECONDS": "3600",
        "RAG_BACKEND_AUTH_JWKS_TIMEOUT_SECONDS": "3",
        "RAG_BACKEND_AUTH_JWKS_MAX_BYTES": "524288",
        "RAG_BACKEND_READINESS_JWKS_KEY_ID": KID,
        "RAG_BACKEND_QUERY_MODELS": json.dumps(
            {
                "ollama": {
                    "chat_model": base["OLLAMA_RAG_MODEL"],
                    "vision_model": base["OLLAMA_VISION_MODEL"],
                }
            },
            separators=(",", ":"),
        ),
        "RAG_BACKEND_QUERY_RERANKER": base.get(
            "RAG_BACKEND_QUERY_RERANKER", "external"
        ),
        "RAG_BACKEND_QUERY_RERANK_TOP_N": base.get(
            "RAG_BACKEND_QUERY_RERANK_TOP_N", "20"
        ),
        "RAG_BACKEND_DATASET_EMBEDDING_PROVIDER": "ollama",
        "RAG_BACKEND_DATASET_EMBEDDING_MODEL": base["OLLAMA_EMBED_MODEL"],
        "RAG_BACKEND_TEXT_INGESTION_QDRANT_URL": base["QDRANT_HTTP_URL"],
        "RAG_BACKEND_TEXT_INGESTION_QDRANT_API_KEY": base.get("QDRANT_API_KEY", ""),
        "NEO4J_DATABASE": base.get("NEO4J_DATABASE") or "neo4j",
        "TEMPORAL_CLIENT_IDENTITY": "hawki-rag-python-local",
    }
    for name in (
        "RERANKER_API_URL",
        "RERANKER_API_DIALECT",
        "RERANKER_MODEL",
        "RAG_RERANKER_MODEL",
        "RERANKER_API_KEY",
    ):
        if name in base:
            values[name] = base[name]
    output = (
        "# Ignored local development settings. Never commit or print values.\n"
        + "".join(
            name + "=" + env_value(value) + "\n" for name, value in values.items()
        )
    )
    write(BACKEND_ENV, output)
    resolved = docker_json(
        "compose",
        "-f",
        "docker-compose.yml",
        "-f",
        "docker-compose.local.yml",
        "config",
        "--format",
        "json",
    )["services"]["rag_backend"]["environment"]
    if any(resolved.get(name) != value for name, value in values.items()):
        raise ValueError(
            "Resolved backend environment does not match the local configuration"
        )
    print("Local material provisioned; values suppressed.")
    print("Resolved backend runtime DSN: present")
    print(
        "Configured backend variable names: "
        + ", ".join(
            sorted(name for name in values if name.startswith("RAG_BACKEND_"))
        )
    )


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(
            "Local provisioning failed: "
            + type(error).__name__
            + "; secret values suppressed."
        )
        raise SystemExit(1) from None
