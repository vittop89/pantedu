"""HMAC-SHA256 request authentication.

Headers attesi sul request:
  X-Timestamp: unix epoch seconds (string)
  X-Signature: hex(HMAC-SHA256(secret, f"{timestamp}.{body}"))

Il client firma timestamp + body. Il server verifica:
  1) timestamp entro la finestra MAX_CLOCK_SKEW
  2) firma corrisponde

Niente nonce/replay-tracking persistente in v1: la combinazione
TLS + finestra timestamp stretta (5 min default) è sufficiente per il PoC.
"""
from __future__ import annotations

import hashlib
import hmac
import os
import time

from fastapi import Header, HTTPException, Request, status


_SECRET = os.environ.get("TEX_COMPILE_SECRET", "")
_MAX_SKEW = int(os.environ.get("TEX_COMPILE_MAX_CLOCK_SKEW", "300"))


def _expected_signature(timestamp: str, body: bytes) -> str:
    if not _SECRET:
        raise RuntimeError("TEX_COMPILE_SECRET non configurato")
    msg = timestamp.encode("ascii") + b"." + body
    return hmac.new(_SECRET.encode("utf-8"), msg, hashlib.sha256).hexdigest()


async def verify_request(
    request: Request,
    x_timestamp: str = Header(..., alias="X-Timestamp"),
    x_signature: str = Header(..., alias="X-Signature"),
) -> None:
    """Dependency FastAPI da iniettare sugli endpoint protetti."""
    try:
        ts = int(x_timestamp)
    except ValueError:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "X-Timestamp non valido")

    now = int(time.time())
    if abs(now - ts) > _MAX_SKEW:
        raise HTTPException(
            status.HTTP_401_UNAUTHORIZED,
            f"X-Timestamp fuori finestra (skew {now - ts}s, max {_MAX_SKEW}s)",
        )

    body = await request.body()
    expected = _expected_signature(x_timestamp, body)
    if not hmac.compare_digest(expected, x_signature):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Firma HMAC non valida")
