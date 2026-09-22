"""olt-sidecar (v0.23.3) — sidecar OLT baca-saja. Lihat
docs/omci/sidecar-design.md untuk desain lengkap.

BATAS KERAS: TIDAK ADA command tulis ke OLT mana pun di sub-versi ini —
hanya operation yang ADA di modul vendor masing-masing (`OPERATIONS`
dict) yang bisa dijalankan, dan setiap operation di sana adalah command
`show`/baca yang sudah TERUJI di riset manual v0.23.1/v0.23.2.
"""
from __future__ import annotations

import json
import logging
import os
import time
from logging.handlers import RotatingFileHandler

from flask import Flask, jsonify, request

from .hmac_verify import verify as hmac_verify
from .olt_queue import OltBusyError, OltSessionGuard
from .vendors import hsgq_e04id, hsgq_g02id, zte_c300
from .vendors.base import OltSessionError

app = Flask(__name__)

VENDOR_MODULES = {
    'hsgq_e04id': hsgq_e04id,
    'hsgq_g02id': hsgq_g02id,
    'zte_c300': zte_c300,
}

_LOG_DIR = os.environ.get('OLT_SIDECAR_LOG_DIR', '/var/log/olt-sidecar')
os.makedirs(_LOG_DIR, exist_ok=True)

# Log audit operasional (§9 desain) — HANYA {timestamp, olt_device_id,
# operation, requested_by, result, duration_ms}. TIDAK PERNAH: kredensial,
# SN/nama pelanggan/community, isi command CLI mentah, output device
# mentah.
audit_logger = logging.getLogger('olt_sidecar.audit')
audit_logger.setLevel(logging.INFO)
if not audit_logger.handlers:
    _handler = RotatingFileHandler(
        os.path.join(_LOG_DIR, 'audit.log'), maxBytes=10_000_000, backupCount=5,
    )
    _handler.setFormatter(logging.Formatter('%(message)s'))
    audit_logger.addHandler(_handler)


@app.get('/health')
def health():
    """Liveness murni — TANPA HMAC, TIDAK menyentuh OLT mana pun."""
    return jsonify({'status': 'ok'})


@app.post('/olt/<int:olt_device_id>/read')
def read_olt(olt_device_id: int):
    raw_body = request.get_data()
    timestamp_header = request.headers.get('X-Olt-Timestamp', '')
    signature_header = request.headers.get('X-Olt-Signature', '')

    try:
        timestamp = int(timestamp_header)
    except (TypeError, ValueError):
        return jsonify({'success': False, 'error': 'X-Olt-Timestamp hilang atau tidak valid'}), 401

    if not hmac_verify(raw_body, signature_header, timestamp):
        return jsonify({'success': False, 'error': 'Signature HMAC tidak valid atau sudah kedaluwarsa'}), 401

    try:
        payload = json.loads(raw_body)
    except ValueError:
        return jsonify({'success': False, 'error': 'Body JSON tidak valid'}), 400

    vendor = payload.get('vendor')
    operation = payload.get('operation')
    connection = payload.get('connection') or {}
    args = payload.get('args') or {}
    requested_by = payload.get('requested_by')
    # v0.23.4 — default True (perilaku v0.23.3, TIDAK berubah untuk
    # pemanggil mana pun yang sudah ada). Hanya App\Services\Network\
    # OnuRegistryService (Laravel) yang mengirim false secara eksplisit —
    # lihat docs/omci/sidecar-design.md §4 dan
    # docs/omci/onu-registry-design.md §3 untuk alasan lengkap.
    mask_sensitive = payload.get('mask_sensitive', True)
    if not isinstance(mask_sensitive, bool):
        mask_sensitive = True

    module = VENDOR_MODULES.get(vendor)
    if module is None:
        return jsonify({'success': False, 'error': f'Vendor tidak dikenal: {vendor}'}), 422

    started = time.monotonic()
    result_status = 'fail'
    response = None
    status_code = 200

    try:
        with OltSessionGuard(olt_device_id):
            data, raw_excerpt = module.execute(connection, operation, args, mask_sensitive)
        result_status = 'success'
        response = {
            'success': True,
            'operation': operation,
            'olt_device_id': olt_device_id,
            'data': data,
            'raw_excerpt': raw_excerpt,
            'device_message': None,
        }
    except OltBusyError as exc:
        status_code = 409
        response = {'success': False, 'error': str(exc)}
    except OltSessionError as exc:
        status_code = 502
        response = {
            'success': False,
            'operation': operation,
            'olt_device_id': olt_device_id,
            'data': None,
            'raw_excerpt': None,
            'device_message': str(exc),
        }
    except Exception as exc:  # pragma: no cover — batas terluar, jangan pernah diam tanpa pesan
        status_code = 500
        response = {'success': False, 'error': 'internal error', 'detail': str(exc)}
    finally:
        # `connection` (berisi password) dibuang dari scope secepat
        # mungkin — defense-in-depth, sidecar tidak pernah menyimpannya
        # permanen (desain §8).
        connection = None  # noqa: F841

        duration_ms = int((time.monotonic() - started) * 1000)
        audit_logger.info(json.dumps({
            'timestamp': int(time.time()),
            'olt_device_id': olt_device_id,
            'operation': operation,
            'requested_by': requested_by,
            'result': result_status,
            'duration_ms': duration_ms,
        }))

    return jsonify(response), status_code
