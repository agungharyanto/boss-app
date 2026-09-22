"""HSGQ G02ID (GPON) — SSH. HANYA operasi berstatus TERUJI di
docs/omci/hsgq-g02id-cli-reference.md yang diimplementasikan di sini
(v0.23.3 §5 desain).
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
        raise OltSessionError(f"Operasi '{operation}' belum didukung untuk hsgq_g02id", 'operation')

    raw = hsgq_common.run_hsgq_ssh_command(connection, command)
    lines = [mask_sensitive_tokens(line) for line in lines_from_capture(raw, command)]

    if not lines:
        return None, mask_sensitive_tokens(raw)[:2000]

    return {'lines': lines}, None
