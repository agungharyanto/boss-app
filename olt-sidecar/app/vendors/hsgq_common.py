"""Mekanisme sesi SSH bersama HSGQ E04ID/G02ID (v0.23.1 CLI research) —
kedua model memakai mekanisme login/enable/logout yang IDENTIK, port
langsung dari `olt_session.exp`. Hanya peta command per-operation yang
beda per model, tinggal di modul masing-masing (hsgq_e04id.py/
hsgq_g02id.py).
"""
from __future__ import annotations

import time

import pexpect

from .base import (
    INITIAL_PASSWORD_RE,
    OltSessionError,
    PROMPT_RE,
    run_command_and_capture,
)

# Flag SSH persis yang sudah terbukti bekerja sepanjang riset v0.23.1 —
# diperlukan untuk firmware HSGQ yang lama (host key/kex/cipher legacy).
SSH_OPTIONS = [
    '-tt',
    '-o', 'StrictHostKeyChecking=no',
    '-o', 'ConnectTimeout=10',
    '-o', 'NumberOfPasswordPrompts=1',
    '-o', 'HostKeyAlgorithms=+ssh-rsa',
    '-o', 'PubkeyAcceptedKeyTypes=+ssh-rsa',
    '-o', 'KexAlgorithms=+diffie-hellman-group14-sha1,diffie-hellman-group1-sha1',
    '-o', 'Ciphers=+aes128-cbc,3des-cbc',
]

PERMISSION_DENIED_RE = r'(?i)permission denied'


def run_hsgq_ssh_command(connection: dict, command: str, overall_timeout: float = 60.0) -> str:
    """Login (VIEW `>`) -> `enable` (PRIVILEGED `#`, tanpa password
    tambahan, dikonfirmasi riset v0.23.1) -> jalankan SATU command baca ->
    logout (2x `exit`). Escalation ke PRIVILEGED murni untuk memastikan
    command baca berhasil di kedua mode — bukan command tulis."""
    host = connection['host']
    port = connection.get('port') or 22
    username = connection['username']
    password = connection['password']

    args = SSH_OPTIONS + ['-p', str(port), f'{username}@{host}']
    child = pexpect.spawn('ssh', args, timeout=15, encoding='utf-8', codec_errors='replace')
    deadline = time.monotonic() + overall_timeout

    try:
        try:
            index = child.expect([INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=15)
            if index != 0:
                raise OltSessionError(
                    'Tidak ada prompt password dalam batas waktu (kemungkinan masalah negosiasi/konektivitas)',
                    'login',
                )
            child.sendline(password)
        finally:
            # Defense-in-depth — buang referensi password secepat mungkin.
            password = None  # noqa: F841

        index = child.expect(
            [PROMPT_RE, PERMISSION_DENIED_RE, INITIAL_PASSWORD_RE, pexpect.TIMEOUT, pexpect.EOF],
            timeout=30,
        )
        if index == 1:
            raise OltSessionError('Device menolak kredensial ("Permission denied")', 'login')
        if index == 2:
            raise OltSessionError('Prompt password KEDUA muncul — menolak menjawabnya', 'login')
        if index in (3, 4):
            raise OltSessionError('Tidak ada prompt CLI awal setelah login', 'login')

        child.sendline('enable')
        index = child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=10)
        if index != 0:
            raise OltSessionError("Timeout menunggu prompt setelah 'enable'", 'enable')

        output = run_command_and_capture(child, command, deadline)
        return output
    finally:
        _logout_hsgq(child)


def _logout_hsgq(child: 'pexpect.spawn') -> None:
    """Dari PRIVILEGED (`#`): satu `exit` -> VIEW (`>`), `exit` kedua
    menutup koneksi (riset v0.23.1, port apa adanya)."""
    try:
        child.sendline('exit')
        child.expect([PROMPT_RE, pexpect.TIMEOUT, pexpect.EOF], timeout=8)
        child.sendline('exit')
        child.expect([pexpect.EOF, pexpect.TIMEOUT], timeout=8)
    except Exception:
        pass
    finally:
        try:
            child.close(force=True)
        except Exception:
            pass
