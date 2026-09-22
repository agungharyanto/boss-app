"""ZTE C300 — Telnet. Login mendarat LANGSUNG di privileged `#` — JANGAN
PERNAH kirim `enable` (akar penyebab "Insiden Sesi 1" v0.23.2 — device
memperlakukan `exit`/`logout` sebagai 3x percobaan password salah kalau
dikirim ke prompt password `enable` yang tidak diharapkan). Logout:
KIRIM `exit` SATU KALI SAJA, dengan teks EKSAK
"confirm to logout without saving? [yes/no]" sebagai SATU-SATUNYA
konfirmasi yang boleh dijawab "yes" — ini mekanika terminal ZTE yang
NORMAL (muncul di setiap logout, bahkan sesi baca-saja), BUKAN tanda ada
perubahan belum tersimpan. Port langsung dari `olt_telnet_session.exp`
(v0.23.2), termasuk fix "Insiden Sesi 6" (deteksi password-reprompt
indentation-aware — lihat base.py).
"""
from __future__ import annotations

import time

import pexpect

from .base import (
    OltSessionError,
    PROMPT_RE,
    lines_from_capture,
    mask_sensitive_tokens,
    parse_field_value_block,
    run_command_and_capture,
)

# Value = command TEMPLATE — operasi yang butuh argumen memakai '{onu}'
# sebagai placeholder, diisi dari args['onu'] saat execute() (lihat
# REQUIRES_ONU_ARG di bawah). onu_uncfg_list tidak butuh argumen apa pun.
OPERATIONS = {
    'onu_uncfg_list': 'show gpon onu uncfg',
    # v0.23.4 — registry ONU. `show gpon onu detail-info <onu>` TERUJI
    # eksekusi nyata (Sesi 6-7 v0.23.2, thd ONU_UJI_1/2) — lihat
    # docs/omci/onu-registry-design.md §7.
    'onu_detail_info': 'show gpon onu detail-info {onu}',
}

REQUIRES_ONU_ARG = {'onu_detail_info'}

USERNAME_PROMPT_RE = r'(?i)(username|login)\s*:\s*'
INITIAL_PASSWORD_RE = r'(?i)password:'
LOGIN_FAILURE_RE = r'(?i)incorrect|invalid|denied|failed|refused'
LOGOUT_EXACT_CONFIRM_RE = r'(?i)confirm to logout without saving\? \[yes/no\]'
LOGOUT_GENERIC_CONFIRM_RE = r'\[yes/no\]|\(y/n\)|\[y/n\]|\(yes/no\)'


def execute(connection: dict, operation: str, args: dict, mask_sensitive: bool = True):
    template = OPERATIONS.get(operation)
    if template is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk zte_c300", 'operation')

    if operation in REQUIRES_ONU_ARG:
        onu = (args or {}).get('onu')
        if not onu:
            raise OltSessionError(f"Operasi '{operation}' butuh argumen 'onu'", 'operation')
        command = template.format(onu=onu)
    else:
        command = template

    raw = _run_zte_telnet_command(connection, command)

    if operation == 'onu_detail_info':
        fields = parse_field_value_block(raw, command)
        if mask_sensitive:
            fields = {key: mask_sensitive_tokens(value) for key, value in fields.items()}
        if not fields:
            excerpt = raw
            if mask_sensitive:
                excerpt = mask_sensitive_tokens(excerpt)
            return None, excerpt[:2000]
        return fields, None

    lines = lines_from_capture(raw, command)
    if mask_sensitive:
        lines = [mask_sensitive_tokens(line) for line in lines]

    if not lines:
        excerpt = raw
        if mask_sensitive:
            excerpt = mask_sensitive_tokens(excerpt)
        return None, excerpt[:2000]

    return {'lines': lines}, None


def _run_zte_telnet_command(connection: dict, command: str, overall_timeout: float = 60.0) -> str:
    host = connection['host']
    port = connection.get('port') or 23
    username = connection['username']
    password = connection['password']

    child = pexpect.spawn('telnet', [host, str(port)], timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    try:
        try:
            index = child.expect(
                [USERNAME_PROMPT_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
                timeout=15,
            )
            if index == 0:
                child.sendline(username)
                index2 = child.expect(
                    [INITIAL_PASSWORD_RE, PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF],
                    timeout=15,
                )
                if index2 == 0:
                    child.sendline(password)
                elif index2 in (2, 3):
                    raise OltSessionError('Tidak ada prompt password setelah kirim username', 'login')
                # index2 == 1 -> langsung masuk prompt CLI tanpa password (jarang, tapi ditangani).
            elif index == 1:
                # Beberapa device langsung ke prompt password (banner
                # sudah membawa username) — kirim CR kosong dulu lalu
                # password, persis skrip Tcl asli.
                child.sendline('')
                child.sendline(password)
            else:
                raise OltSessionError(
                    'Tidak ada prompt username/login dalam batas waktu (kemungkinan masalah konektivitas/banner)',
                    'login',
                )
        finally:
            password = None  # noqa: F841 — defense-in-depth

        index = child.expect(
            [PROMPT_RE, LOGIN_FAILURE_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=20,
        )
        if index == 1:
            raise OltSessionError('Device menolak kredensial (login incorrect/denied)', 'login')
        if index == 2:
            raise OltSessionError('Prompt password KEDUA muncul — menolak menjawabnya', 'login')
        if index in (3, 4):
            raise OltSessionError('Tidak ada prompt CLI awal setelah login', 'login')

        # JANGAN PERNAH kirim `enable` — ZTE sudah privileged (`#`) sejak login.
        output = run_command_and_capture(child, command, deadline)
        return output
    finally:
        _logout_zte(child)


def _logout_zte(child: 'pexpect.spawn') -> None:
    # Ctrl-C dulu, unconditional — membatalkan sub-dialog macet apa pun
    # (mis. prompt password `enable` yang tidak diharapkan) SEBELUM
    # mengirim teks exit/logout apa pun ke dalamnya (root-cause fix
    # "Insiden Sesi 1").
    try:
        child.send('\x03')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=2)
    except Exception:
        pass

    try:
        child.sendline('exit')
        index = child.expect(
            [LOGOUT_EXACT_CONFIRM_RE, LOGOUT_GENERIC_CONFIRM_RE, PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=8,
        )
        if index == 0:
            # SATU-SATUNYA konfirmasi yang boleh dijawab — mekanika
            # terminal ZTE normal, bukan tanda perubahan belum tersimpan.
            child.sendline('yes')
            child.expect([pexpect.EOF, pexpect.TIMEOUT], timeout=8)
        # index == 1 (konfirmasi lain yang TIDAK dikenal) -> sengaja TIDAK
        # dijawab, langsung tutup koneksi dari sisi client.
        # index 2/3/4 -> baik-baik saja, lanjut tutup.
    except Exception:
        pass
    finally:
        try:
            child.close(force=True)
        except Exception:
            pass
