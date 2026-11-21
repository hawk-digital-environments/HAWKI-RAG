"""Explicit local-only HTTPS OAuth/JWKS service issuer.

Only server-side client credentials obtain short-lived service JWTs. This
internal service has no browser authorization, human credentials or sessions.
"""

import base64
from contextlib import asynccontextmanager
import hashlib
import hmac
import json
import os
from pathlib import Path
import time
from uuid import uuid4

from fastapi import FastAPI, HTTPException, Request
import jwt

ISSUER = "https://rawki-local-auth:8443"


@asynccontextmanager
async def lifespan(application: FastAPI):
    if os.environ.get("RAWKI_LOCAL_AUTH_ENABLED") != "true":
        raise RuntimeError("The local service issuer must be explicitly enabled")
    root = Path(os.environ["RAWKI_LOCAL_AUTH_DIRECTORY"])
    config = json.loads((root / "issuer.json").read_text(encoding="utf-8"))
    if config.get("issuer") != ISSUER:
        raise RuntimeError("Only the local development issuer is permitted")
    application.state.config = config
    application.state.key = (root / "signing.pem").read_bytes()
    application.state.jwks = json.loads(
        (root / "jwks.json").read_text(encoding="utf-8")
    )
    yield


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)


def client_credential(request: Request) -> tuple[str, str]:
    values = request.headers.getlist("authorization")
    try:
        if len(values) != 1 or len(values[0]) > 4096:
            raise ValueError()
        scheme, encoded = values[0].split()
        if scheme.lower() != "basic":
            raise ValueError()
        client_id, secret = (
            base64.b64decode(encoded, validate=True).decode().split(":", 1)
        )
        return client_id, hashlib.sha256(secret.encode()).hexdigest()
    except (ValueError, UnicodeError):
        raise HTTPException(401, "credential_required") from None


@app.get("/health")
def health():
    return {"status": "ok", "development_only": True}


@app.get("/jwks.json")
def jwks(request: Request):
    return request.app.state.jwks


@app.post("/token")
async def token(request: Request):
    client_id, digest = client_credential(request)
    config = request.app.state.config
    client = config["clients"].get(client_id)
    if not client or not hmac.compare_digest(digest, client["sha256"]):
        raise HTTPException(401, "invalid_client")
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > 8192:
            raise HTTPException(413, "request_too_large")
    request._body = bytes(body)
    form = await request.form(max_fields=2, max_part_size=4096)
    if (
        set(form) - {"grant_type", "scope"}
        or form.get("grant_type") != "client_credentials"
    ):
        raise HTTPException(400, "unsupported_grant_type")
    scopes = str(form.get("scope", "")).split()
    if not set(scopes) <= set(client["scopes"]):
        raise HTTPException(403, "invalid_scope")
    now = int(time.time())
    access_token = jwt.encode(
        {
            "iss": ISSUER,
            "aud": config["audience"],
            "azp": client_id,
            "sub": client_id,
            "tenant_id": client["tenant_id"],
            "scope": " ".join(scopes),
            "iat": now,
            "nbf": now,
            "exp": now + 300,
            "jti": uuid4().hex,
        },
        request.app.state.key,
        algorithm="RS256",
        headers={"kid": config["kid"]},
    )
    response = {"access_token": access_token, "token_type": "Bearer", "expires_in": 300}
    from fastapi.responses import JSONResponse

    return JSONResponse(
        response, headers={"Cache-Control": "no-store", "Pragma": "no-cache"}
    )
