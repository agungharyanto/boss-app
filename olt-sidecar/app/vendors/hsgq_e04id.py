"""HSGQ E04ID (EPON) — SSH. HANYA operasi berstatus TERUJI di
docs/omci/hsgq-e04id-cli-reference.md yang diimplementasikan di sini
(v0.23.3 §5 desain) — operasi lain (mis. onu_uncfg_list/onu_detail) BELUM
punya sintaks yang terverifikasi 100% dan sengaja TIDAK ditambahkan
sampai riset lanjutan mengonfirmasinya.
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

# Operasi TULIS (jalur `/olt/<id>/apply`). Hanya `bind_onu` — admisi EPON
# deny-by-default: `bind-onu mac <MAC> [name <N>] [desc "<D>"]` lalu
# `onu confirm onu-id <N>` di node `configure -> interface epon <PON>`
# (TERUJI manual v0.23.7, lihat docs/omci/hsgq-e04id-cli-reference.md §"Hasil
# Aktivasi Unit Uji"). Produksi sidecar BARU — jalur interaktif penuh
# diverifikasi ulang terhadap device di v0.23.8.5b (nol write device di batch
# pembangunannya).
OPERATIONS_WRITE = ('bind_onu',)

# Pola error device untuk jalur TULIS — melempar OltSessionError berisi teks
# device PERSIS (disiplin "laporkan pesan error apa adanya").
_DEVICE_ERROR_RE = re.compile(
    r'(?im)(?:^\s*%|^\s*vty%|\bError,|\bfail(?:ed)?\b|no matched command|incomplete command|invalid)'
)

_MAC_HEX_RE = re.compile(r'^[0-9a-f]{12}$')
# name = token tunggal tanpa spasi/kutip; desc = bebas spasi TANPA kutip ganda
# (dibungkus "..." di CLI — kutip ganda di dalam akan merusak sintaks).
_NAME_SAFE_RE = re.compile(r'^[A-Za-z0-9._\-]{1,40}$')


def execute(connection: dict, operation: str, args: dict, mask_sensitive: bool = True):
    command = OPERATIONS.get(operation)
    if command is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk hsgq_e04id", 'operation')

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
    """Jalur TULIS (`/olt/<id>/apply`). `params` SELALU dict terstruktur.
    Mengembalikan (result_dict, None) kalau sukses; melempar OltSessionError
    kalau validasi gagal atau device menolak."""
    if operation != 'bind_onu':
        raise OltSessionError(f"Operasi tulis '{operation}' tidak dikenal untuk hsgq_e04id", 'operation')

    pon = _require_pon(params)
    onu_id = _require_onu_id(params)
    mac = _require_mac(params)
    commands = _build_bind_onu_commands(mac, onu_id, params.get('name'), params.get('desc'))

    outputs = _run_e04id_epon_session(connection, pon, commands, check_errors=True)

    return {
        'pon': pon,
        'onu_id': onu_id,
        'mac': mac,
        'commands_applied': len(outputs),
        # onu_id = NILAI YANG DIMINTA PEMANGGIL (bukan hasil baca-balik). Device
        # meng-assign onu-id saat `bind-onu`; `onu confirm onu-id <onu_id>` di
        # bawah MENGONFIRMASI id itu — kalau device meng-assign id berbeda,
        # `onu confirm` ditolak (gagal KERAS, bukan salah diam-diam). Alokasi
        # onu-id yang andal = tanggung jawab pemanggil/8.5b (sama terbuka spt ZTE).
    }, None


def _require_pon(params: dict) -> int:
    try:
        pon = int(params.get('pon'))
    except (TypeError, ValueError):
        raise OltSessionError("Param 'pon' wajib integer 1-4 (interface epon)", 'params')
    if not 1 <= pon <= 4:
        raise OltSessionError(f"PON {pon} di luar rentang E04ID (1-4)", 'params')
    return pon


def _require_onu_id(params: dict) -> int:
    try:
        onu_id = int(params.get('onu_id'))
    except (TypeError, ValueError):
        raise OltSessionError("Param 'onu_id' wajib integer 1-64", 'params')
    if not 1 <= onu_id <= 64:
        raise OltSessionError(f"onu_id {onu_id} di luar rentang (1-64)", 'params')
    return onu_id


def _require_mac(params: dict) -> str:
    """Normalisasi MAC ke format colon lowercase (xx:xx:xx:xx:xx:xx)."""
    raw = str(params.get('mac') or '').strip().lower()
    hexonly = re.sub(r'[^0-9a-f]', '', raw)
    if not _MAC_HEX_RE.match(hexonly):
        raise OltSessionError(f"Param 'mac' tidak valid (butuh 12 hex): {raw!r}", 'params')
    return ':'.join(hexonly[i:i + 2] for i in range(0, 12, 2))


def _build_bind_onu_commands(mac: str, onu_id: int, name, desc) -> list[str]:
    """Rakit urutan command node `interface epon <PON>` — MURNI (deterministik,
    bisa diuji tanpa device). `bind-onu` TANPA onu-id (device meng-assign;
    sintaks TERUJI v0.23.7), `onu confirm onu-id <N>` memakai onu_id pemanggil.

    name opsional (token aman), desc opsional (bebas spasi, dibungkus kutip —
    kutip ganda di dalam ditolak agar tak merusak sintaks CLI)."""
    bind = f'bind-onu mac {mac}'
    if name is not None and str(name) != '':
        name = str(name)
        if not _NAME_SAFE_RE.match(name):
            raise OltSessionError(f"Param 'name' mengandung karakter tak aman / terlalu panjang: {name!r}", 'params')
        bind += f' name {name}'
    if desc is not None and str(desc) != '':
        desc = str(desc)
        if '"' in desc:
            raise OltSessionError("Param 'desc' tidak boleh mengandung kutip ganda (merusak sintaks CLI)", 'params')
        if len(desc) > 60:
            raise OltSessionError("Param 'desc' terlalu panjang (maks 60)", 'params')
        bind += f' desc "{desc}"'

    return [bind, f'onu confirm onu-id {onu_id}']


def _run_e04id_epon_session(
    connection: dict,
    pon: int,
    commands: list[str],
    check_errors: bool,
    overall_timeout: float = 90.0,
) -> list[str]:
    """Login -> enable -> configure -> interface epon <pon> -> [commands] ->
    logout. Pola identik _run_g02id_gpon_session (hsgq_g02id) kecuali node PON
    = `interface epon` (prompt `config-epon-<PON>`). check_errors=True: tiap
    command lewat _run_command_or_raise (melempar OltSessionError kalau device
    menolak)."""
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
        _run_command_or_raise(child, f'interface epon {pon}', deadline)

        outputs: list[str] = []
        for command in commands:
            if check_errors:
                outputs.append(_run_command_or_raise(child, command, deadline))
            else:
                outputs.append(run_command_and_capture(child, command, deadline))
        return outputs
    finally:
        _logout_e04id(child)


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


def _logout_e04id(child: 'pexpect.spawn') -> None:
    """Dari node `(config-epon-N)#`: `end` -> privileged `#`, lalu tutup via
    hsgq_common (`#`->`>`->exit). Toleran; force-close di akhir."""
    try:
        child.sendline('end')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=8)
    except Exception:
        pass
    hsgq_common._logout_hsgq(child)
