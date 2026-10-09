"""Exact-body authentication for destructive Temporal control-plane requests."""

import hashlib
import hmac
import time

from fastapi import HTTPException, Request


async def verify_control_signature(request: Request, secret: str) -> None:
    if not secret:
        raise HTTPException(503, "Control-plane authentication is not configured.")
    timestamp = request.headers.get("X-Hawki-Timestamp", "")
    signature = request.headers.get("X-Hawki-Signature", "")
    if not timestamp.isdigit() or len(timestamp) not in range(10, 13):
        raise HTTPException(401, "Invalid control-plane signature.")
    if abs(time.time() - int(timestamp)) > 300:
        raise HTTPException(401, "Expired control-plane signature.")
    body = await request.body()
    digest = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(signature, "v1=" + digest):
        raise HTTPException(401, "Invalid control-plane signature.")
