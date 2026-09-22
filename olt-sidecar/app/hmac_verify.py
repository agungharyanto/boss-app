"""Verifikasi HMAC untuk interface HTTP internal boss-app <-> olt-sidecar.

Pola SAMA PERSIS dengan App\\Support\\WhatsappHmac (PHP) /
whatsapp-gateway/internal/hmacsig/hmacsig.go (Go) — direplikasi apa
adanya, bukan diciptakan ulang (lihat docs/omci/sidecar-design.md §4):
HMAC-SHA256(secret, "{unix_timestamp}.{raw_body}"), hex-encoded,
toleransi replay 300 detik, perbandingan timing-safe.
"""
from __future__ import annotations

import hashlib
import hmac
import os
import time

TOLERANCE_SECONDS = 300


def _secret() -> bytes:
    return os.environ.get('OLT_SIDECAR_HMAC_SECRET', '').encode('utf-8')


def sign(body: bytes, timestamp: int, secret: bytes | None = None) -> str:
    key = secret if secret is not None else _secret()
    message = f'{timestamp}.'.encode('utf-8') + body
    return hmac.new(key, message, hashlib.sha256).hexdigest()


def verify(body: bytes, signature: str, timestamp: int, secret: bytes | None = None) -> bool:
    """`body` HARUS berupa raw bytes request APA ADANYA — menandatangani
    ulang hasil re-encode akan diam-diam gagal pada perbedaan
    whitespace/urutan key (peringatan yang sama seperti
    SendWhatsappMessageJob::sendToGateway())."""
    if not signature:
        return False
    if abs(time.time() - timestamp) > TOLERANCE_SECONDS:
        return False
    expected = sign(body, timestamp, secret)
    return hmac.compare_digest(expected, signature)
