"""HSGQ E04ID (EPON) — SSH. HANYA operasi berstatus TERUJI di
docs/omci/hsgq-e04id-cli-reference.md yang diimplementasikan di sini
(v0.23.3 §5 desain) — operasi lain (mis. onu_uncfg_list/onu_detail) BELUM
punya sintaks yang terverifikasi 100% dan sengaja TIDAK ditambahkan
sampai riset lanjutan mengonfirmasinya.
"""
from __future__ import annotations

from . import hsgq_common
from .base import OltSessionError, lines_from_capture, mask_sensitive_tokens

OPERATIONS = {
    'show_version': 'show version',
}


def execute(connection: dict, operation: str, args: dict):
    command = OPERATIONS.get(operation)
    if command is None:
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk hsgq_e04id", 'operation')

    raw = hsgq_common.run_hsgq_ssh_command(connection, command)
    lines = [mask_sensitive_tokens(line) for line in lines_from_capture(raw, command)]

    if not lines:
        return None, mask_sensitive_tokens(raw)[:2000]

    return {'lines': lines}, None
