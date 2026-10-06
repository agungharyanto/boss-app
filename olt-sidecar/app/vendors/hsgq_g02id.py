"""HSGQ G02ID (GPON) — SSH. Operasi berstatus TERUJI di
docs/omci/hsgq-g02id-cli-reference.md.

v0.23.6: arsitektur final G02ID = TR-069 untuk WAN, OMCI-direct HANYA untuk
PENAMAAN. `ont wanconfig add` TIDAK diimplementasikan di sini — sudah
terbukti tidak viable (lihat "KESIMPULAN ARSITEKTUR FINAL v0.23.6" di CLI
reference). Yang ditambahkan v0.23.6:
  - READ  `resolve_onu_by_sn`  : cari onu_id + state dari SN (`show ont-info
    sn <SN>`), coba gpon 1 lalu gpon 2.
  - WRITE `set_ont_naming`      : `ont setting <id> name "<Nama - CID>"`
    (+ `desc` opsional) — TERUJI aman di produksi (5× tulis/rollback metadata
    nol gangguan, Sesi 4/5).

Semua operasi node-level (`show ont-info`/`ont setting`) berjalan di dalam
`configure` -> `interface gpon <pon>`; transport SSH login/enable identik
hsgq_common (port dari olt_session.exp v0.23.1).
"""
from __future__ import annotations

import re
import time

import pexpect

from . import hsgq_common
from .base import (
    INITIAL_PASSWORD_RE,
    OltSessionError,
    PROMPT_RE,
    lines_from_capture,
    mask_sensitive_tokens,
    run_command_and_capture,
)

OPERATIONS = {
    'show_version': 'show version',
}

# Pola error device G02ID untuk jalur TULIS — melempar OltSessionError berisi
# teks device persis (sesuai disiplin "laporkan pesan error apa adanya").
# TIDAK dipakai untuk resolve (read): di sana "ONU is not exist" adalah hasil
# sah "tidak ditemukan", bukan error.
_DEVICE_ERROR_RE = re.compile(
    r'(?im)(?:^\s*%|^\s*vty%|\bError,|\bfail\b|no matched command|incomplete command)'
)

_ONU_ID_RE = re.compile(r'ONU ID\s*:\s*(\d+)', re.IGNORECASE)
_NOT_EXIST_RE = re.compile(r'not exist|ont-auth table fail|not found', re.IGNORECASE)
# name/desc: hindari karakter yang merusak kutip CLI; batas aman 40 (observasi
# pasif, firmware sendiri tak mendeklarasikan batas numerik — lihat CLI ref).
_NAMING_MAX_LEN = 40


def execute(connection: dict, operation: str, args: dict, mask_sensitive: bool = True):
    """Jalur BACA (`/olt/<id>/read`). Mengembalikan (data, raw_excerpt)."""
    if operation == 'resolve_onu_by_sn':
        return _resolve_onu_by_sn(connection, args or {}), None

    command = OPERATIONS.get(operation)
    if command is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk hsgq_g02id", 'operation')

    raw = hsgq_common.run_hsgq_ssh_command(connection, command)
    lines = lines_from_capture(raw, command)
    if mask_sensitive:
        lines = [mask_sensitive_tokens(line) for line in lines]

    if not lines:
        excerpt = raw
        if mask_sensitive:
            excerpt = mask_sensitive_tokens(excerpt)
        return None, excerpt[:2000]

    return {'lines': lines}, None


def execute_apply(connection: dict, operation: str, params: dict):
    """Jalur TULIS (`/olt/<id>/apply`). `params` SELALU dict terstruktur
    (bukan command mentah). Mengembalikan (result_dict, None) kalau sukses;
    melempar OltSessionError kalau validasi gagal atau device menolak."""
    if operation != 'set_ont_naming':
        raise OltSessionError(f"Operasi tulis '{operation}' tidak dikenal untuk hsgq_g02id", 'operation')

    pon = _require_pon(params)
    onu_id = _require_onu_id(params)
    commands = _build_set_ont_naming_commands(params, onu_id)

    outputs = _run_g02id_gpon_session(connection, pon, commands, check_errors=True)

    return {
        'pon': pon,
        'onu_id': onu_id,
        'fields_applied': [c.split()[2] for c in commands],  # ['name'] atau ['name','desc']
        'commands_applied': len(outputs),
    }, None


def execute_save(connection: dict) -> dict:
    """Jalur SAVE (`/olt/<id>/save`). Persist running-config -> startup-config
    via `copy running-config startup-config` — command TERVERIFIKASI dari
    bantuan CLI device sendiri (`copy running-config ?` -> "startup-config:
    Copy running config to startup config (same as write file)", 2026-10-06).
    Device membalas "Configuration saved successfully". Dijalankan di node
    PRIVILEGED (`#`) via run_hsgq_ssh_command (login->enable->command->logout).
    BEDA dari ZTE C300 yang auto-write: G02ID (HSGQ) butuh save EKSPLISIT,
    kalau tidak config hilang saat OLT reboot. Melempar OltSessionError kalau
    device mengembalikan pola error eksplisit (_DEVICE_ERROR_RE)."""
    output = hsgq_common.run_hsgq_ssh_command(
        connection, 'copy running-config startup-config', overall_timeout=45.0
    )
    if _DEVICE_ERROR_RE.search(output):
        raise OltSessionError(f"Device menolak save: {output.strip()[:500]}", 'command')

    return {
        'saved': 'saved successfully' in output.lower(),
        'raw_excerpt': output.strip()[:500],
    }


# --------------------------------------------------------------------------
# Validasi params (TULIS)
# --------------------------------------------------------------------------

def _require_pon(params: dict) -> int:
    value = params.get('pon')
    if not isinstance(value, int) or value not in (1, 2):
        raise OltSessionError("Parameter 'pon' harus 1 atau 2", 'operation')
    return value


def _require_onu_id(params: dict) -> int:
    value = params.get('onu_id')
    if not isinstance(value, int) or not (0 <= value <= 127):
        raise OltSessionError("Parameter 'onu_id' harus integer 0-127", 'operation')
    return value


def _validate_naming_value(label: str, value) -> str:
    if not isinstance(value, str) or value.strip() == '':
        raise OltSessionError(f"Parameter '{label}' harus string tidak kosong", 'operation')
    if '"' in value or '\n' in value or '\r' in value:
        raise OltSessionError(f"Parameter '{label}' mengandung karakter terlarang (kutip/newline)", 'operation')
    if len(value) > _NAMING_MAX_LEN:
        raise OltSessionError(
            f"Parameter '{label}' terlalu panjang ({len(value)} > {_NAMING_MAX_LEN})", 'operation'
        )
    return value


def _build_set_ont_naming_commands(params: dict, onu_id: int) -> list[str]:
    """`name` WAJIB (format 'Nama - CID'); `desc` OPSIONAL. Keduanya dikutip
    karena biasanya mengandung spasi (catatan help CLI: 'If NAME/DESC
    contains spaces, use "" quotes it')."""
    name = _validate_naming_value('name', params.get('name'))
    commands = [f'ont setting {onu_id} name "{name}"']

    if 'desc' in params and params.get('desc') is not None:
        desc = _validate_naming_value('desc', params.get('desc'))
        commands.append(f'ont setting {onu_id} desc "{desc}"')

    return commands


# --------------------------------------------------------------------------
# READ: resolve onu_id dari SN
# --------------------------------------------------------------------------

def _resolve_onu_by_sn(connection: dict, args: dict) -> dict:
    """Cari onu_id + state untuk sebuah SN. Coba gpon 1 lalu gpon 2 (SN hanya
    muncul di salah satu PON). Mengembalikan dict terstruktur TANPA data PII
    (tak mengembalikan ONU Name) — hanya pon/onu_id/state/run_state."""
    sn = args.get('sn')
    if not isinstance(sn, str) or sn.strip() == '':
        raise OltSessionError("Parameter 'sn' wajib untuk resolve_onu_by_sn", 'operation')
    if not re.fullmatch(r'[0-9A-Za-z-]{8,20}', sn):
        raise OltSessionError("Parameter 'sn' format tidak valid", 'operation')

    for pon in (1, 2):
        output = _run_g02id_gpon_session(connection, pon, [f'show ont-info sn {sn}'], check_errors=False)[0]
        if _NOT_EXIST_RE.search(output):
            continue
        match = _ONU_ID_RE.search(output)
        if match:
            return {
                'found': True,
                'pon': pon,
                'onu_id': int(match.group(1)),
                'state': _grab_field(output, 'State'),
                'run_state': _grab_field(output, 'Run State'),
            }

    return {'found': False, 'pon': None, 'onu_id': None, 'state': None, 'run_state': None}


def _grab_field(output: str, label: str) -> str | None:
    # `Run State` vs `State`: anchor label di awal baris (setelah strip) supaya
    # 'State' tidak salah-match baris 'Run State'/'Config State'/'Match State'.
    pattern = re.compile(r'(?im)^\s*' + re.escape(label) + r'\s*:\s*(\S.*?)\s*$')
    match = pattern.search(output)
    return match.group(1).strip() if match else None


# --------------------------------------------------------------------------
# Transport sesi node `interface gpon <pon>` (login/enable identik hsgq_common)
# --------------------------------------------------------------------------

def _run_g02id_gpon_session(
    connection: dict,
    pon: int,
    commands: list[str],
    check_errors: bool,
    overall_timeout: float = 90.0,
) -> list[str]:
    """Login -> enable -> configure -> interface gpon <pon> -> [commands] ->
    logout. `check_errors=True` (jalur TULIS): tiap command lewat
    _run_command_or_raise() yang melempar OltSessionError kalau device
    menolak. `check_errors=False` (resolve/baca): capture apa adanya."""
    host = connection['host']
    port = connection.get('port') or 22
    username = connection['username']
    password = connection['password']

    args = hsgq_common.SSH_OPTIONS + ['-p', str(port), f'{username}@{host}']
    child = pexpect.spawn('ssh', args, timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    try:
        try:
            index = child.expect([INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=15)
            if index != 0:
                raise OltSessionError('Tidak ada prompt password (negosiasi/konektivitas)', 'login')
            child.sendline(password)
        finally:
            password = None  # noqa: F841 — buang referensi secepat mungkin

        index = child.expect(
            [PROMPT_RE, hsgq_common.PERMISSION_DENIED_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=30,
        )
        if index == 1:
            raise OltSessionError('Device menolak kredensial ("Permission denied")', 'login')
        if index == 2:
            raise OltSessionError('Prompt password KEDUA muncul — menolak menjawabnya', 'login')
        if index in (3, 4):
            raise OltSessionError('Tidak ada prompt CLI awal setelah login', 'login')

        _run_command_or_raise(child, 'enable', deadline)
        _run_command_or_raise(child, 'configure', deadline)
        # Masuk node PON — navigasi murni (bebas-tulis, dikonfirmasi Sesi 2).
        _run_command_or_raise(child, f'interface gpon {pon}', deadline)

        outputs: list[str] = []
        for command in commands:
            if check_errors:
                outputs.append(_run_command_or_raise(child, command, deadline))
            else:
                outputs.append(run_command_and_capture(child, command, deadline))
        return outputs
    finally:
        _logout_g02id(child)


def _run_command_or_raise(child: 'pexpect.spawn', command: str, deadline: float) -> str:
    """run_command_and_capture() + deteksi pola error device eksplisit.
    Melempar OltSessionError berisi teks device PERSIS kalau ditemukan."""
    output = run_command_and_capture(child, command, deadline)
    if _DEVICE_ERROR_RE.search(output):
        raise OltSessionError(
            f"Device menolak command '{command}': {output.strip()[:500]}",
            'command',
        )
    return output


def _logout_g02id(child: 'pexpect.spawn') -> None:
    """Dari node `(config-gpon-N)#`: `end` -> privileged `#`, lalu 2× `exit`
    (pola hsgq_common: `#`->`>`->tutup). Dibungkus toleran; force-close di
    akhir (device bersihkan vty lewat idle-timeout kalau perlu)."""
    try:
        child.sendline('end')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=8)
    except Exception:
        pass
    hsgq_common._logout_hsgq(child)
