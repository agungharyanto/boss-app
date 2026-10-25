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

# READ one-shot (SSH exec; `show version` valid di node exec/enable).
OPERATIONS = {
    'show_version': 'show version',
}

# READ INTERAKTIF di node `configure` (v0.23.8.5b — TERVERIFIKASI device nyata
# 2026-10-25, modem ZICG2976A4FC): `show onu-info ...` HANYA valid di node
# `OLT-Cileg(config)#`, BUKAN enable `OLT-Cileg#` ("Unknown command" node:6) —
# makanya jalur one-shot exec lama gagal. `all` = seluruh ONU (tabel; pager
# `--More--` ditangani base). `mac` = satu ONU, ringkas key:value (TANPA pager,
# cara terbaik ambil onu-id). `{mac}` di-substitusi dari args.
OPERATIONS_CONFIG_READ = {
    'show_onu_info_all': 'show onu-info all',
    'show_onu_info_mac': 'show onu-info mac {mac}',
}

# Operasi TULIS (jalur `/olt/<id>/apply`). Hanya `bind_onu` — admisi EPON
# deny-by-default: `bind-onu mac <MAC> [name <N>] [desc "<D>"]` lalu read-back
# onu-id (`show onu-info mac` di node config) lalu `onu confirm onu-id <N>` di
# `interface epon <PON>`, verifikasi Auth=TRUE, `copy running-config
# startup-config`. TERVERIFIKASI device nyata 2026-10-25 (modem ZICG2976A4FC,
# onu-id auto-assign = 15), lihat docs/omci/hsgq-e04id-cli-reference.md
# §"SINTAKS TERVERIFIKASI".
OPERATIONS_WRITE = ('bind_onu', 'save_config')

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
    # READ interaktif node config (show onu-info *) — HANYA valid di node config.
    if operation in OPERATIONS_CONFIG_READ:
        command = OPERATIONS_CONFIG_READ[operation]
        if '{mac}' in command:
            command = command.format(mac=_require_mac(args or {}))
        raw = _run_e04id_read_at_config(connection, command)
        return _format_read(raw, command, mask_sensitive)

    command = OPERATIONS.get(operation)
    if command is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk hsgq_e04id", 'operation')

    raw = hsgq_common.run_hsgq_ssh_command(connection, command)
    return _format_read(raw, command, mask_sensitive)


def _format_read(raw: str, command: str, mask_sensitive: bool):
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
    kalau validasi gagal atau device menolak.

    v0.23.8.5b — READ-BACK onu-id (Bagian H poin 3 di-wire): `bind-onu` TIDAK
    membawa onu-id (device auto-assign), lalu `show onu-info all` DIBACA untuk
    mengambil onu-id yang BENAR-BENAR di-assign device untuk MAC ini, baru
    `onu confirm onu-id <id-dari-device>`. `onu_id` di params kini OPSIONAL:
    kalau disuplai pemanggil → dijadikan CROSS-CHECK (mismatch vs device =
    GAGAL KERAS, tidak confirm diam-diam id mana pun); kalau tidak → pakai
    id hasil-baca device langsung."""
    if operation != 'bind_onu':
        raise OltSessionError(f"Operasi tulis '{operation}' tidak dikenal untuk hsgq_e04id", 'operation')

    pon = _require_pon(params)
    mac = _require_mac(params)
    expected_onu_id = _optional_onu_id(params)  # cross-check, BUKAN sumber kebenaran
    bind_command = _build_bind_command(mac, params.get('name'), params.get('desc'))

    return _run_e04id_bind_with_readback(connection, pon, mac, bind_command, expected_onu_id), None


def execute_save(connection: dict) -> dict:
    """Jalur SAVE (`/olt/<id>/save`). `copy running-config startup-config` di
    node privileged `#` via run_hsgq_ssh_command (login->enable->command->logout,
    identik mekanisme g02id). TERVERIFIKASI device nyata 2026-10-25 (modem
    ZICG2976A4FC, lewat jalur bind — device membalas "Configuration saved
    successfully"). HSGQ butuh save EKSPLISIT (tak auto-write seperti ZTE C300)."""
    output = hsgq_common.run_hsgq_ssh_command(
        connection, 'copy running-config startup-config', overall_timeout=45.0
    )
    if _DEVICE_ERROR_RE.search(output):
        raise OltSessionError(f"Device menolak save: {output.strip()[:500]}", 'command')

    return {
        'saved': 'saved successfully' in output.lower(),
        'raw_excerpt': output.strip()[:500],
    }


_MAC_DETAIL_ONU_ID_RE = re.compile(r'(?im)^\s*ONU\s+ID\s*:\s*(\d+)\s*$')
_MAC_DETAIL_PON_ID_RE = re.compile(r'(?im)^\s*PON\s+ID\s*:\s*(\d+)\s*$')
_MAC_DETAIL_AUTH_RE = re.compile(r'(?im)^\s*Authrize\s+state\s*:\s*TRUE\s*$')


def _mac_in(output: str, mac: str) -> bool:
    plain = re.sub(r'[^0-9a-f]', '', mac.lower())
    return bool(plain) and plain in re.sub(r'[^0-9a-f]', '', output.lower())


def _parse_mac_detail(output: str, mac: str) -> 'dict | None':
    """MURNI — dari `show onu-info mac <MAC>` (ringkas key:value, TERVERIFIKASI
    2026-10-25 modem ZICG2976A4FC) ambil {pon, onu_id}. None kalau MAC tak ada
    di output (device belum punya ONU itu / bind gagal) atau 'ONU ID' tak ketemu.
    Catatan: firmware mengeja 'Authrize state' (lihat _parse_auth_true)."""
    if not _mac_in(output, mac):
        return None
    m = _MAC_DETAIL_ONU_ID_RE.search(output)
    if m is None:
        return None
    pon_m = _MAC_DETAIL_PON_ID_RE.search(output)

    return {'pon': int(pon_m.group(1)) if pon_m else None, 'onu_id': int(m.group(1))}


def _parse_auth_true(output: str, mac: str) -> bool:
    """MURNI — True kalau output `show onu-info mac` menunjukkan ONU MAC ini
    'Authrize state : TRUE' (ejaan firmware). False kalau MAC tak ada / bukan
    TRUE. Dipakai verifikasi pasca-confirm."""
    return _mac_in(output, mac) and _MAC_DETAIL_AUTH_RE.search(output) is not None


def _parse_assigned_onu_id(info_output: str, mac: str) -> 'int | None':
    """MURNI — dari tabel `show onu-info all`, temukan baris MAC target lalu
    ambil onu-id dari token `<pon>/<onu-id>`. Fallback kalau `show onu-info mac`
    tak mem-parse. Format kolom TERVERIFIKASI device nyata 2026-10-25
    (`3/15 f8:6c:e1:76:a4:14 Online TRUE TRUE ... KhikmatulKho`). Return None
    kalau MAC tak ada (bind gagal / belum muncul)."""
    mac_colon = mac.strip().lower()
    mac_plain = re.sub(r'[^0-9a-f]', '', mac_colon)
    for line in info_output.splitlines():
        low = line.lower()
        line_plain = re.sub(r'[^0-9a-f]', '', low)
        if (mac_colon and mac_colon in low) or (mac_plain and mac_plain in line_plain):
            m = re.search(r'(\d+)\s*/\s*(\d+)', line)
            if m:
                return int(m.group(2))
    return None


def _resolve_confirm_onu_id(parsed: 'int | None', expected: 'int | None') -> int:
    """MURNI — putuskan onu-id untuk `onu confirm`, dari id hasil-baca device
    (`parsed`) + id yg disuplai pemanggil (`expected`, opsional):
      parsed None             → GAGAL ('tak ketemu row Initial' — bind gagal/MAC
                                 belum muncul), JANGAN tebak/confirm apa pun.
      expected diberi & beda  → GAGAL KERAS (mismatch), JANGAN confirm diam-diam.
      selain itu              → pakai `parsed` (sumber kebenaran = device)."""
    if parsed is None:
        raise OltSessionError(
            "onu-id auto-assign tak ditemukan di 'show onu-info' (mac/all) untuk MAC ini "
            '— bind-onu kemungkinan gagal, TIDAK confirm.',
            'readback',
        )
    if expected is not None and int(expected) != int(parsed):
        raise OltSessionError(
            f"onu-id device ({parsed}) BEDA dari yang disuplai pemanggil ({expected}) — "
            'GAGAL KERAS, tidak confirm id mana pun.',
            'mismatch',
        )
    return int(parsed)


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


def _optional_onu_id(params: dict) -> 'int | None':
    """onu_id OPSIONAL (cross-check, v0.23.8.5b). Absen/None/'' → None.
    Kalau ada, divalidasi rentang 1-64 (nilai ngawur ditolak dini)."""
    v = params.get('onu_id')
    if v is None or v == '':
        return None
    return _require_onu_id(params)


def _require_mac(params: dict) -> str:
    """Normalisasi MAC ke format colon lowercase (xx:xx:xx:xx:xx:xx)."""
    raw = str(params.get('mac') or '').strip().lower()
    hexonly = re.sub(r'[^0-9a-f]', '', raw)
    if not _MAC_HEX_RE.match(hexonly):
        raise OltSessionError(f"Param 'mac' tidak valid (butuh 12 hex): {raw!r}", 'params')
    return ':'.join(hexonly[i:i + 2] for i in range(0, 12, 2))


def _build_bind_command(mac: str, name, desc) -> str:
    """MURNI — string `bind-onu mac <MAC> [name <N>] [desc "<D>"]` (TANPA onu-id;
    device auto-assign). name opsional (token aman), desc opsional (bebas spasi,
    dibungkus kutip — kutip ganda di dalam ditolak agar tak merusak sintaks)."""
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
    return bind


def _build_bind_onu_commands(mac: str, onu_id: int, name, desc) -> list[str]:
    """[LEGACY pra-v0.23.8.5b, dipertahankan untuk test lama] bind + confirm
    dgn onu_id caller-supplied (tanpa read-back). Alur produksi kini pakai
    execute_apply → _run_e04id_bind_with_readback (confirm pakai id device)."""
    return [_build_bind_command(mac, name, desc), f'onu confirm onu-id {onu_id}']


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


def _e04id_login(connection: dict, deadline: float) -> 'pexpect.spawn':
    """Login SSH + handshake prompt → enable → configure. Kembalikan child di
    node `(config)#`. Dipakai jalur READ interaktif + bind-with-readback (satu
    pola login, tak diduplikasi)."""
    host = connection['host']
    port = connection.get('port') or 22
    username = connection['username']
    password = connection['password']

    args = hsgq_common.SSH_OPTIONS + ['-p', str(port), f'{username}@{host}']
    child = pexpect.spawn('ssh', args, timeout=15, encoding='utf-8', codec_errors='replace')

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

    return child


def _run_e04id_read_at_config(connection: dict, command: str, overall_timeout: float = 90.0) -> str:
    """READ-only interaktif di node `configure` (login→enable→configure→<command>
    →logout). `show onu-info ...` HANYA valid di node config (TERVERIFIKASI
    2026-10-25). TIDAK menjalankan command tulis apa pun; output device
    di-capture apa adanya (termasuk 'Unknown command' kalau ada)."""
    deadline = time.monotonic() + overall_timeout
    child = _e04id_login(connection, deadline)
    try:
        return run_command_and_capture(child, command, deadline)
    finally:
        _logout_e04id(child)


def _logout_e04id(child: 'pexpect.spawn') -> None:
    """Dari node `(config-epon-N)#`: `end` -> privileged `#`, lalu tutup via
    hsgq_common (`#`->`>`->exit). Toleran; force-close di akhir."""
    try:
        child.sendline('end')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=8)
    except Exception:
        pass
    hsgq_common._logout_hsgq(child)


def _run_e04id_bind_with_readback(
    connection: dict,
    pon: int,
    mac: str,
    bind_command: str,
    expected_onu_id: 'int | None',
    overall_timeout: float = 150.0,
) -> dict:
    """v0.23.8.5b — TERVERIFIKASI device nyata 2026-10-25 (modem ZICG2976A4FC,
    onu-id auto-assign=15). Urutan:
      configure → interface epon <pon> → bind-onu (TANPA onu-id; auto-assign)
      → exit (ke node config) → `show onu-info mac <MAC>` (ringkas; fallback
      `show onu-info all`) → parse onu-id → cross-check expected (mismatch/
      not-found = GAGAL KERAS, sebelum confirm apa pun)
      → interface epon <pon> → `onu confirm onu-id <id-device>` → exit (config)
      → `show onu-info mac` verifikasi Auth=TRUE
      → end (ke `#`) → `copy running-config startup-config`.
    `show onu-info ...` HANYA valid di node config (BUKAN enable) — ini koreksi
    utama vs versi lama yang jalan di `#`."""
    deadline = time.monotonic() + overall_timeout
    child = _e04id_login(connection, deadline)  # child di node (config)#

    try:
        _run_command_or_raise(child, f'interface epon {pon}', deadline)
        # 1. bind (device auto-assign onu-id; TANPA onu-id di command)
        _run_command_or_raise(child, bind_command, deadline)
        # 2. kembali ke node config untuk `show onu-info` (valid HANYA di config).
        _run_command_or_raise(child, 'exit', deadline)
        info = run_command_and_capture(child, f'show onu-info mac {mac}', deadline)
        parsed = _parse_mac_detail(info, mac)
        parsed_onu_id = parsed['onu_id'] if parsed is not None else None
        if parsed_onu_id is None:
            # fallback: tabel lengkap (parser kolom <pon>/<onu-id>).
            info_all = run_command_and_capture(child, 'show onu-info all', deadline)
            parsed_onu_id = _parse_assigned_onu_id(info_all, mac)
        # 3. verify (GAGAL KERAS kalau mismatch/not-found) — SEBELUM confirm.
        confirm_id = _resolve_confirm_onu_id(parsed_onu_id, expected_onu_id)
        # 4. confirm pakai id hasil-baca device.
        _run_command_or_raise(child, f'interface epon {pon}', deadline)
        _run_command_or_raise(child, f'onu confirm onu-id {confirm_id}', deadline)
        _run_command_or_raise(child, 'exit', deadline)
        # 5. verifikasi Auth=TRUE pasca-confirm.
        verify = run_command_and_capture(child, f'show onu-info mac {mac}', deadline)
        auth_true = _parse_auth_true(verify, mac)
        # 6. save (copy running-config startup-config) di node enable `#`.
        _run_command_or_raise(child, 'end', deadline)
        save_out = run_command_and_capture(child, 'copy running-config startup-config', deadline)
        saved = 'saved successfully' in save_out.lower()

        return {
            'pon': pon,
            'mac': mac,
            'assigned_onu_id': parsed_onu_id,    # dibaca dari device (sumber kebenaran)
            'expected_onu_id': expected_onu_id,  # cross-check caller (opsional)
            'confirmed_onu_id': confirm_id,
            'auth_true': auth_true,
            'saved': saved,
        }
    finally:
        _logout_e04id(child)
