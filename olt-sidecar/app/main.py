"""olt-sidecar (v0.23.3-v0.23.5) — sidecar OLT. Lihat
docs/omci/sidecar-design.md untuk desain lengkap.

BATAS KERAS operasi BACA (`/olt/<id>/read`, sejak v0.23.3): hanya
operation yang ADA di modul vendor masing-masing (`OPERATIONS` dict) yang
bisa dijalankan, setiap operation di sana adalah command `show`/baca yang
sudah TERUJI di riset manual v0.23.1/v0.23.2.

TULIS (`/olt/<id>/apply` dan `/olt/<id>/save`, v0.23.5) — keputusan
desain terkunci Agung (2026-09-22): pemanggil (Laravel) TIDAK PERNAH
mengirim command CLI mentah, hanya `operation` (nama operasi terstruktur)
+ `params` (dict field-value). SIDECAR sendiri yang merakit + memvalidasi
urutan command CLI dari template internal per-vendor (`OPERATIONS_WRITE`
dict di modul vendor, mis. `zte_c300.OPERATIONS_WRITE`) — tidak ada satu
pun jalur untuk mengirim string command bebas dari luar sidecar. `/apply`
menjalankan konfigurasi sampai `end` TANPA menyimpannya (`wr`); `/save`
adalah endpoint TERPISAH yang HANYA mengirim `wr` — pemisahan sengaja
(pengaman: config yang di-apply tanpa save aman hilang kalau device
reboot, verifikasi online dilakukan DI ANTARA kedua panggilan ini,
dari sisi Laravel/caller, bukan sidecar)."""
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


def _read_hmac_authenticated_payload():
    """Verifikasi HMAC + parse body JSON — logic identik `read_olt()`,
    diekstrak supaya `/apply`/`/save` tidak menduplikasinya. Return
    `(payload_dict, None, None)` kalau sukses, atau `(None, error_response,
    status_code)` kalau verifikasi/parse gagal — pemanggil cukup
    `return err, code` kalau elemen ke-2 bukan None."""
    raw_body = request.get_data()
    timestamp_header = request.headers.get('X-Olt-Timestamp', '')
    signature_header = request.headers.get('X-Olt-Signature', '')

    try:
        timestamp = int(timestamp_header)
    except (TypeError, ValueError):
        return None, jsonify({'success': False, 'error': 'X-Olt-Timestamp hilang atau tidak valid'}), 401

    if not hmac_verify(raw_body, signature_header, timestamp):
        return None, jsonify({'success': False, 'error': 'Signature HMAC tidak valid atau sudah kedaluwarsa'}), 401

    try:
        payload = json.loads(raw_body)
    except ValueError:
        return None, jsonify({'success': False, 'error': 'Body JSON tidak valid'}), 400

    return payload, None, None


@app.post('/olt/<int:olt_device_id>/apply')
def apply_olt(olt_device_id: int):
    payload, err, code = _read_hmac_authenticated_payload()
    if err is not None:
        return err, code

    vendor = payload.get('vendor')
    operation = payload.get('operation')
    params = payload.get('params') or {}
    connection = payload.get('connection') or {}
    requested_by = payload.get('requested_by')

    module = VENDOR_MODULES.get(vendor)
    if module is None:
        return jsonify({'success': False, 'error': f'Vendor tidak dikenal: {vendor}'}), 422
    if not hasattr(module, 'execute_apply'):
        return jsonify({'success': False, 'error': f"Vendor '{vendor}' belum mendukung operasi tulis"}), 422

    started = time.monotonic()
    result_status = 'fail'
    response = None
    status_code = 200

    try:
        with OltSessionGuard(olt_device_id):
            data, raw_excerpt = module.execute_apply(connection, operation, params)
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
        connection = None  # noqa: F841 — defense-in-depth, sama seperti read_olt().

        duration_ms = int((time.monotonic() - started) * 1000)
        # v0.23.5 — PENGECUALIAN eksplisit dari kebijakan audit_logger di
        # atas ("TIDAK PERNAH SN/nama pelanggan"): keputusan Agung
        # 2026-09-22 — "onu_id, sn boleh dicatat karena ini perangkat uji
        # Agung, cid, vlans" — cukup detail untuk investigasi apply/save
        # kalau ada masalah, TAPI TETAP BUKAN command CLI mentah/output
        # device mentah (params sudah TERSTRUKTUR, bukan string bebas).
        audit_logger.info(json.dumps({
            'timestamp': int(time.time()),
            'olt_device_id': olt_device_id,
            'operation': operation,
            'params': params,
            'requested_by': requested_by,
            'result': result_status,
            'duration_ms': duration_ms,
        }))

    return jsonify(response), status_code


@app.post('/olt/<int:olt_device_id>/save')
def save_olt(olt_device_id: int):
    payload, err, code = _read_hmac_authenticated_payload()
    if err is not None:
        return err, code

    vendor = payload.get('vendor')
    connection = payload.get('connection') or {}
    requested_by = payload.get('requested_by')

    module = VENDOR_MODULES.get(vendor)
    if module is None:
        return jsonify({'success': False, 'error': f'Vendor tidak dikenal: {vendor}'}), 422
    if not hasattr(module, 'execute_save'):
        return jsonify({'success': False, 'error': f"Vendor '{vendor}' belum mendukung operasi tulis"}), 422

    started = time.monotonic()
    result_status = 'fail'
    response = None
    status_code = 200

    try:
        with OltSessionGuard(olt_device_id):
            data = module.execute_save(connection)
        result_status = 'success'
        response = {
            'success': True,
            'operation': 'save_config',
            'olt_device_id': olt_device_id,
            'data': data,
            'raw_excerpt': None,
            'device_message': None,
        }
    except OltBusyError as exc:
        status_code = 409
        response = {'success': False, 'error': str(exc)}
    except OltSessionError as exc:
        status_code = 502
        response = {
            'success': False,
            'operation': 'save_config',
            'olt_device_id': olt_device_id,
            'data': None,
            'raw_excerpt': None,
            'device_message': str(exc),
        }
    except Exception as exc:  # pragma: no cover — batas terluar, jangan pernah diam tanpa pesan
        status_code = 500
        response = {'success': False, 'error': 'internal error', 'detail': str(exc)}
    finally:
        connection = None  # noqa: F841 — defense-in-depth.

        duration_ms = int((time.monotonic() - started) * 1000)
        audit_logger.info(json.dumps({
            'timestamp': int(time.time()),
            'olt_device_id': olt_device_id,
            'operation': 'save_config',
            'requested_by': requested_by,
            'result': result_status,
            'duration_ms': duration_ms,
        }))

    return jsonify(response), status_code


@app.post('/olt/<int:olt_device_id>/probe')
def probe_olt(olt_device_id: int):
    """v0.23.5 — HANYA untuk mencari command CLI yang benar lewat bantuan
    `?` (mis. mencari nama command save setelah `wr` ditolak device) —
    TIDAK PERNAH mengeksekusi config sungguhan. `params['query']`
    divalidasi ketat di sisi vendor module (_HELP_QUERY_RE) SEBELUM
    menyentuh jaringan sama sekali — string yang tidak diakhiri `?`
    ditolak, sehingga endpoint ini secara struktural tidak bisa dipakai
    untuk mengirim command config sungguhan."""
    payload, err, code = _read_hmac_authenticated_payload()
    if err is not None:
        return err, code

    vendor = payload.get('vendor')
    # v0.23.5 amendment — 'config_help' tetap default (backward-compatible
    # untuk OltSidecarClient::probeConfigHelp() yang tidak pernah mengirim
    # field 'operation' sama sekali). 'operation' di sini adalah nama
    # OPERASI PROBE (routing ke fungsi Python via PROBE_OPERATIONS dict
    # di modul vendor), TIDAK PERNAH command CLI itu sendiri — command CLI
    # aslinya selalu 'params.query', divalidasi _HELP_QUERY_RE di sisi
    # vendor module.
    operation = payload.get('operation') or 'config_help'
    params = payload.get('params') or {}
    connection = payload.get('connection') or {}
    requested_by = payload.get('requested_by')

    module = VENDOR_MODULES.get(vendor)
    if module is None:
        return jsonify({'success': False, 'error': f'Vendor tidak dikenal: {vendor}'}), 422
    probe_operations = getattr(module, 'PROBE_OPERATIONS', None)
    method_name = probe_operations.get(operation) if probe_operations else None
    if method_name is None or not hasattr(module, method_name):
        return jsonify({'success': False, 'error': f"Operasi probe '{operation}' tidak dikenal untuk vendor '{vendor}'"}), 422
    method = getattr(module, method_name)

    started = time.monotonic()
    result_status = 'fail'
    response = None
    status_code = 200

    try:
        with OltSessionGuard(olt_device_id):
            data = method(connection, params)
        result_status = 'success'
        response = {
            'success': True,
            'operation': operation,
            'olt_device_id': olt_device_id,
            'data': data,
            'raw_excerpt': None,
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
        connection = None  # noqa: F841 — defense-in-depth.

        duration_ms = int((time.monotonic() - started) * 1000)
        # v0.23.5 — sama posture dengan /apply: params boleh dicatat
        # (di sini cuma 'query', string bantuan CLI, bukan data sensitif
        # sama sekali), BUKAN command mentah/output device mentah.
        audit_logger.info(json.dumps({
            'timestamp': int(time.time()),
            'olt_device_id': olt_device_id,
            'operation': operation,
            'params': params,
            'requested_by': requested_by,
            'result': result_status,
            'duration_ms': duration_ms,
        }))

    return jsonify(response), status_code
